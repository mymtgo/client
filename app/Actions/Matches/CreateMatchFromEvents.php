<?php

namespace App\Actions\Matches;

use App\Actions\Logs\ConvertMtgoTimestamp;
use App\Actions\Util\ExtractJson;
use App\Actions\Util\ExtractKeyValueBlock;
use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Jobs\SubmitMatchLogSample;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Create a brand-new MtgoMatch row from the events that triggered the
 * MatchJoinedEventUnderwayState transition.
 *
 * Encapsulates:
 * - Phantom-match guard (require local user to be one of the players
 *   when a game-state update exists).
 * - Tournament metadata parsing (event id + round) from the Description
 *   key, with diagnostic logging when the shape looks tournament-like
 *   but the regex fails (format drift detector).
 * - Initial match insert in `Started` state.
 * - Optional immediate tournament link when a round_info event already
 *   landed for the parsed tournament id.
 * - Sample-log dispatch for support diagnostics.
 *
 * Returns `null` when the phantom-match gate rejects the events.
 */
class CreateMatchFromEvents
{
    /**
     * @param  Collection<int, LogEvent>  $events
     */
    public static function run(
        string $matchToken,
        int|string $matchId,
        Collection $events,
        LogEvent $joinedState,
    ): ?MtgoMatch {
        $gameStateEvents = $events->filter(
            fn (LogEvent $e) => $e->event_type === LogEventType::GAME_STATE_UPDATE->value
        );

        $username = null;

        if ($gameStateEvents->isNotEmpty()) {
            $firstState = ExtractJson::run($gameStateEvents->first()->raw_text)->first();
            $players = $firstState['Players'] ?? [];
            $playerNames = array_column($players, 'Name');
            $username = ResolveMatchUsername::fromEvents($events, $playerNames);

            if (count($players) < 2 || ($username && ! in_array($username, $playerNames))) {
                Log::channel('pipeline')->info("AdvanceMatchState: skipping phantom match token={$matchToken} id={$matchId}", [
                    'player_count' => count($players),
                    'player_names' => $playerNames,
                    'local_username' => $username,
                ]);

                return null;
            }
        }

        $gameMeta = ExtractKeyValueBlock::run($joinedState->raw_text);
        $started = ConvertMtgoTimestamp::run($joinedState->logged_at, $joinedState->timestamp);

        [$tournamentEventId, $tournamentRound] = self::parseTournamentDescriptor(
            matchToken: $matchToken,
            matchId: $matchId,
            joinedState: $joinedState,
            gameMeta: $gameMeta,
        );

        $match = MtgoMatch::create([
            'mtgo_id' => $matchId,
            'token' => $matchToken,
            'format' => $gameMeta['PlayFormatCd'] ?? 'Unknown',
            'match_type' => $gameMeta['GameStructureCd'] ?? 'Unknown',
            'started_at' => $started,
            'ended_at' => null,
            'state' => MatchState::Started,
            'tournament_event_id' => $tournamentEventId,
            'tournament_round' => $tournamentRound,
        ]);

        // If a round_info event landed before the match did, pull the
        // tournament_token now. Otherwise RunPipeline's backfill pass will
        // pick it up on a later tick.
        if ($tournamentEventId !== null) {
            LinkMatchToTournament::run($match);
            $match->refresh();
        }

        SubmitMatchLogSample::dispatch(
            matchToken: $matchToken,
            matchType: $match->match_type,
            format: $match->format,
            rawText: $joinedState->raw_text,
            username: $username,
        );

        Log::channel('pipeline')->info("Match {$matchId}: created in Started state", [
            'token' => $matchToken,
            'format' => $match->format,
            'match_type' => $match->match_type,
        ]);

        return $match;
    }

    /**
     * Parse tournament_event_id and tournament_round from the joined-state
     * description, or log a warning when the shape looks tournament-like
     * but the regex misses.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private static function parseTournamentDescriptor(
        string $matchToken,
        int|string $matchId,
        LogEvent $joinedState,
        array $gameMeta,
    ): array {
        $descriptionSource = $gameMeta['Description'] ?? $joinedState->raw_text;

        if (preg_match('/Tournament:(\d+)\s+Round:(\d+)/', $descriptionSource, $descMatch)) {
            return [(int) $descMatch[1], (int) $descMatch[2]];
        }

        if (self::contextSuggestsTournament($joinedState->context, $descriptionSource)) {
            Log::channel('pipeline')->warning("AdvanceMatchState: tournament-shaped join missed regex token={$matchToken} id={$matchId}", [
                'context' => $joinedState->context,
                'description' => $descriptionSource,
            ]);
        }

        return [null, null];
    }

    /**
     * Heuristic: does the surrounding signal look like a tournament join
     * even though our regex did not match? Used purely for diagnostic
     * logging — never gates match creation.
     */
    private static function contextSuggestsTournament(?string $context, string $descriptionSource): bool
    {
        if ($context !== null && str_contains($context, 'TournamentMatch')) {
            return true;
        }

        return str_contains($descriptionSource, 'Tournament:')
            || str_contains($descriptionSource, 'TournamentMatch');
    }
}
