<?php

namespace App\Actions\Compile;

use App\Actions\Logs\ConvertMtgoTimestamp;
use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ExtractMetaMessageEntries;
use App\Actions\Util\ExtractKeyValueBlock;
use App\Data\ProjectedMatch\GameData;
use App\Data\ProjectedMatch\LeagueData;
use App\Data\ProjectedMatch\MatchData;
use App\Data\ProjectedMatch\OpponentData;
use App\Data\ProjectedMatch\TimelineEntryData;
use App\Data\ProjectedMatch\TournamentData;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Enums\OutcomeSource;
use App\Models\LogEvent;
use Illuminate\Support\Collection;

/**
 * Pure projection: LogEvent rows for one match token → a contract-shaped
 * MatchData. Zero table writes — the state machine and metadata extraction
 * are ported from the 0.x AdvanceMatchState / ResolveMatchFromMetaMessages,
 * with every `->save()` replaced by a returned value.
 *
 * The outcome is deliberately left Unknown here — the ordered resolver
 * pipeline (ResolveMatchOutcome) owns outcome determination, and the
 * envelope (identity, versions) is stamped by CompileMatch.
 *
 * Not yet projected (later enrichment tasks): the registered deck{} (needs
 * the deck-XML port), per-game instance ids / dice / decks (need
 * game_state_update + deck_used coverage), and card_stats[] (needs the
 * gamelog card chain).
 */
final class ProjectMatch
{
    /**
     * Terminal signals proving the match is over. Superset of the narrow
     * variants DetermineMatchResult recognises — league matches end on
     * LeagueMatchJoinedCompletedState, which never carries a result line.
     */
    private const COMPLETED_SIGNALS = [
        'LeagueMatchJoinedCompletedState',
        'MatchJoinedCompletedState',
        'MatchCompletedState',
        'MatchClosedState',
        'TournamentMatchClosedState',
        'MatchEndedState',
    ];

    public function run(string $matchKey, string $localUsername): ?MatchData
    {
        $tokenEvents = LogEvent::query()
            ->where('match_token', $matchKey)
            ->orderBy('timestamp')
            ->get();

        if ($tokenEvents->isEmpty()) {
            return null;
        }

        $matchId = $tokenEvents->first(fn (LogEvent $e) => $e->match_id !== null)?->match_id;

        $stateChanges = $tokenEvents
            ->where('event_type', 'match_state_changed')
            ->values();

        $joined = $this->findJoinEvent($tokenEvents, $stateChanges);

        if ($joined === null) {
            return null;
        }

        $gameMeta = ExtractKeyValueBlock::run($joined->raw_text);
        $startedAt = ConvertMtgoTimestamp::run($joined->logged_at, $joined->timestamp);

        $entries = ExtractMetaMessageEntries::run($matchKey);
        $extracted = empty($entries) ? null : ExtractGameResults::run($entries, $localUsername);

        $games = $this->buildGames($tokenEvents, $entries, $extracted, $localUsername);

        $completed = $this->hasCompletedSignal($stateChanges);
        $lastEvent = $tokenEvents->last();

        return new MatchData(
            token: $matchKey,
            mtgo_id: $matchId !== null ? (int) $matchId : null,
            format: $gameMeta['PlayFormatCd'] ?? null,
            match_type: $gameMeta['GameStructureCd'] ?? null,
            outcome: MatchOutcome::Unknown,
            outcome_source: OutcomeSource::Unknown,
            state: $this->deriveState($completed, $games)->value,
            started_at: $startedAt?->toIso8601String(),
            ended_at: $completed
                ? ConvertMtgoTimestamp::run($lastEvent->logged_at, $lastEvent->timestamp)?->toIso8601String()
                : null,
            notes: null,
            opponent: new OpponentData(
                mtgo_player_id: null,
                username: $this->opponentUsername($extracted, $localUsername),
            ),
            deck: null,
            league: $this->buildLeague($gameMeta),
            tournament: $this->buildTournament($gameMeta, $joined->raw_text),
            games: $games,
            opponent_archetype: null,
        );
    }

    /**
     * The join gate, ported from AdvanceMatchState: prefer the
     * game_management_json variant (it carries the key-value metadata
     * block), fall back to the bare state-change line.
     */
    private function findJoinEvent(Collection $tokenEvents, Collection $stateChanges): ?LogEvent
    {
        $isJoin = fn (LogEvent $e): bool => str_contains($e->context ?? '', 'MatchJoinedEventUnderwayState')
            || str_contains($e->raw_text ?? '', 'MatchJoinedEventUnderwayState');

        return $tokenEvents
            ->where('event_type', 'game_management_json')
            ->first($isJoin)
            ?? $stateChanges->first($isJoin);
    }

    /**
     * One GameData per GameID seen in the token's game traffic, merged by
     * index with the per-game data ExtractGameResults decodes from the
     * MetaMessage stream (winner, on-play, starting hands, timestamps).
     *
     * @return array<int, GameData>
     */
    private function buildGames(
        Collection $tokenEvents,
        array $entries,
        ?array $extracted,
        string $localUsername,
    ): array {
        $gameIds = $tokenEvents
            ->where('event_type', 'game_management_json')
            ->pluck('game_id')
            ->filter()
            ->unique()
            ->values();

        $extractedGames = $extracted['games'] ?? [];
        $timelines = empty($entries) ? [] : ExtractGameResults::splitIntoGames($entries);

        $count = max($gameIds->count(), count($extractedGames));
        $games = [];

        for ($i = 0; $i < $count; $i++) {
            $data = $extractedGames[$i] ?? null;
            $hands = $data['starting_hands'] ?? [];
            $opponent = $this->opponentUsername($extracted, $localUsername);

            $games[] = new GameData(
                mtgo_id: $gameIds->get($i) !== null ? (int) $gameIds->get($i) : null,
                won: $this->wonByLocal($data, $localUsername),
                started_at: $data['started_at'] ?? null,
                ended_at: $data['ended_at'] ?? null,
                turn_count: null,
                local_on_play: isset($data['on_play']) && $data['on_play'] !== null
                    ? $data['on_play'] === $localUsername
                    : null,
                local_mulligans: isset($hands[$localUsername]) ? max(0, 7 - $hands[$localUsername]) : null,
                opp_mulligans: $opponent !== null && isset($hands[$opponent]) ? max(0, 7 - $hands[$opponent]) : null,
                local_dice: null,
                opp_dice: null,
                local_instance: null,
                opp_instance: null,
                local_deck: null,
                opponent_deck: null,
                card_stats: [],
                timeline: array_map(
                    fn (array $entry) => new TimelineEntryData(
                        action: $entry['message'],
                        timestamp: $entry['timestamp'] ?? null,
                        player: null,
                        context: null,
                    ),
                    $timelines[$i] ?? [],
                ),
            );
        }

        return $games;
    }

    private function wonByLocal(?array $gameData, string $localUsername): ?bool
    {
        return match (true) {
            $gameData === null => null,
            ($gameData['winner'] ?? null) === $localUsername => true,
            ($gameData['loser'] ?? null) === $localUsername => false,
            ($gameData['winner'] ?? null) !== null => false,
            default => null,
        };
    }

    private function opponentUsername(?array $extracted, string $localUsername): ?string
    {
        return collect($extracted['players'] ?? [])
            ->first(fn (string $player) => $player !== $localUsername);
    }

    private function buildLeague(array $gameMeta): ?LeagueData
    {
        if (empty($gameMeta['League Token'])) {
            return null;
        }

        return new LeagueData(
            token: $gameMeta['League Token'],
            name: null,
            format: $gameMeta['PlayFormatCd'] ?? null,
            joined_at: null,
            dropped_at: null,
        );
    }

    private function buildTournament(array $gameMeta, string $joinedRawText): ?TournamentData
    {
        $description = $gameMeta['Description'] ?? $joinedRawText;

        if (! preg_match('/Tournament:(\d+)\s+Round:(\d+)/', $description, $m)) {
            return null;
        }

        return new TournamentData(
            mtgo_event_id: (int) $m[1],
            round: (int) $m[2],
            name: null,
        );
    }

    private function hasCompletedSignal(Collection $stateChanges): bool
    {
        return $stateChanges->contains(function (LogEvent $event) {
            foreach (self::COMPLETED_SIGNALS as $signal) {
                if (str_contains($event->raw_text ?? '', $signal)) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @param  array<int, GameData>  $games
     */
    private function deriveState(bool $completed, array $games): MatchState
    {
        return match (true) {
            $completed => MatchState::Complete,
            $games !== [] => MatchState::InProgress,
            default => MatchState::Started,
        };
    }
}
