<?php

namespace App\Actions\Matches;

use App\Actions\Limited\SyncLimitedMatchDeck;
use App\Actions\Logs\ConvertMtgoTimestamp;
use App\Actions\Overlay\SyncGameOverlayVisibility;
use App\Actions\Tournaments\AssignTournament;
use App\Actions\Util\ExtractJson;
use App\Actions\Util\ExtractKeyValueBlock;
use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Events\DeckLinkedToMatch;
use App\Events\GameCardsSnapshotChanged;
use App\Events\LeagueMatchStarted;
use App\Facades\Mtgo;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Support\TimedTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AdvanceMatchState
{
    /**
     * Find-or-create a match for the given token/ID pair and advance it
     * through states as far as available data allows.
     *
     * Returns the match (at whatever state it reached) or null when
     * no join event exists yet.
     */
    public static function run(string $matchToken, int|string $matchId): ?MtgoMatch
    {
        $events = LogEvent::where('match_id', $matchId)->orderBy('timestamp')->get();

        $stateChanges = LogEvent::where('match_token', $matchToken)
            ->where('event_type', LogEventType::MATCH_STATE_CHANGED->value)
            ->get()
            ->values();

        // ── Gate: require a join event ──────────────────────────────
        // Prefer the $events version (game_management_json) because it
        // contains the key-value metadata block (PlayFormatCd, etc).
        // Fall back to state changes which only have the header line.
        $joinedState = $events->first(
            fn (LogEvent $event) => str_contains($event->context ?? '', 'MatchJoinedEventUnderwayState')
        ) ?? $stateChanges->first(
            fn (LogEvent $event) => str_contains($event->context ?? '', 'MatchJoinedEventUnderwayState')
        );

        if (! $joinedState) {
            Log::channel('pipeline')->warning("AdvanceMatchState: no join event for token={$matchToken} id={$matchId}", [
                'events_count' => $events->count(),
                'state_changes_count' => $stateChanges->count(),
                'state_change_contexts' => $stateChanges->pluck('context')->toArray(),
                'event_types' => $events->pluck('event_type')->unique()->toArray(),
            ]);

            return null;
        }

        $previousState = MtgoMatch::where('mtgo_id', $matchId)->value('state');

        // Wrap all state-advancement writes in a single transaction so
        // the SQLite write-lock is held once instead of 10–15 times.
        $match = TimedTransaction::run("AdvanceMatchState:{$matchId}", function () use ($matchToken, $matchId, $events, $stateChanges, $joinedState) {
            // ── Find or create the match ────────────────────────────────
            $match = MtgoMatch::where('mtgo_id', $matchId)->first();

            if (! $match) {
                // ── Gate: require local player in game state ───────────
                // Game state events contain a Players array with Name
                // fields. Verify the local user is a participant and
                // there are 2+ players before creating anything. This
                // prevents phantom matches from other players' league
                // games leaking into the database.
                $gameStateEvents = $events->filter(
                    fn (LogEvent $e) => $e->event_type === LogEventType::GAME_STATE_UPDATE->value
                );

                if ($gameStateEvents->isNotEmpty()) {
                    $firstState = ExtractJson::run($gameStateEvents->first()->raw_text)->first();
                    $players = $firstState['Players'] ?? [];
                    $playerNames = array_column($players, 'Name');
                    $username = $events->first(fn (LogEvent $e) => $e->username !== null)?->username
                        ?? Mtgo::resolveUsername($playerNames);

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

                $tournamentEventId = null;
                $tournamentRound = null;
                $descriptionSource = $gameMeta['Description'] ?? $joinedState->raw_text;
                if (preg_match('/Tournament:(\d+)\s+Round:(\d+)/', $descriptionSource, $descMatch)) {
                    $tournamentEventId = (int) $descMatch[1];
                    $tournamentRound = (int) $descMatch[2];
                } elseif (self::contextSuggestsTournament($joinedState->context, $descriptionSource)) {
                    // Format drift detector: the match looks like a tournament
                    // (TournamentMatch* state context, or "Tournament:" string
                    // in the description) but our regex did not capture an
                    // event id + round. Surface the raw description so we can
                    // adapt the parser to whatever new MTGO variant shipped.
                    Log::channel('pipeline')->warning("AdvanceMatchState: tournament-shaped join missed regex token={$matchToken} id={$matchId}", [
                        'context' => $joinedState->context,
                        'description' => $descriptionSource,
                    ]);
                }

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
                // tournament_token now. Otherwise RunPipeline's backfill pass
                // will pick it up on a later tick.
                if ($tournamentEventId !== null) {
                    LinkMatchToTournament::run($match);
                    $match->refresh();
                }

                Log::channel('pipeline')->info("Match {$matchId}: created in Started state", [
                    'token' => $matchToken,
                    'format' => $match->format,
                    'match_type' => $match->match_type,
                ]);
            }

            // ── No regression ───────────────────────────────────────────
            if ($match->state === MatchState::Complete || $match->failed_at !== null) {
                return $match;
            }

            $gameMeta ??= ExtractKeyValueBlock::run($joinedState->raw_text);

            Log::channel('pipeline')->info("Match {$match->mtgo_id}: gameMeta keys", [
                'keys' => array_keys($gameMeta),
                'has_league_token' => ! empty($gameMeta['League Token']),
            ]);

            // ── Started → InProgress ────────────────────────────────────
            if ($match->state === MatchState::Started) {
                $advanced = self::tryAdvanceToInProgress($match, $events, $gameMeta);

                if (! $advanced) {
                    return $match;
                }
            }

            // ── Create any games whose events arrived after Started → InProgress ──
            if ($match->state === MatchState::InProgress || $match->state === MatchState::Ended) {
                CreateOrUpdateGames::run($match, $events);

                if ($match->state === MatchState::InProgress) {
                    GameCardsSnapshotChanged::dispatch($match->id);
                }
            }

            // ── InProgress → Ended ──────────────────────────────────────
            if ($match->state === MatchState::InProgress) {
                $advanced = self::tryAdvanceToEnded($match, $events, $stateChanges);

                if (! $advanced) {
                    return $match;
                }
            }

            return $match->refresh();
        });

        // Reconcile the overlay window only when the state actually moved —
        // syncing hits the Electron window API, too heavy for every tick.
        if ($match && $match->state !== $previousState) {
            SyncGameOverlayVisibility::run();
        }

        return $match;
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

    /**
     * Started → InProgress: game_state_update events exist.
     * Creates games, links deck, assigns league.
     */
    private static function tryAdvanceToInProgress(
        MtgoMatch $match,
        Collection $events,
        array $gameMeta,
    ): bool {
        $gameStateEvents = $events->filter(
            fn (LogEvent $e) => $e->event_type === LogEventType::GAME_STATE_UPDATE->value
        );

        if ($gameStateEvents->isEmpty()) {
            Log::channel('pipeline')->warning("Match {$match->mtgo_id}: Started → InProgress FAILED", [
                'reason' => '0 game_state_update events',
                'total_events' => $events->count(),
                'event_types' => $events->pluck('event_type')->countBy()->toArray(),
            ]);

            return false;
        }

        // ── Create games (idempotent — CreateGames uses firstOrCreate) ──
        CreateOrUpdateGames::run($match, $events);

        // ── Link deck (if not already linked) ──
        // If the matching DeckVersion doesn't exist yet (deck XML not synced),
        // RelinkOrphanMatches will re-attempt on a later pipeline tick once
        // SyncDecks creates it. We intentionally do NOT dispatch SyncDecks
        // synchronously here — it holds the SQLite write lock across XML I/O
        // and caused the queue worker to thrash on "database is locked".
        if (! $match->deck_version_id) {
            DetermineMatchDeck::run($match);
            $match->refresh();

            if ($match->deck_version_id) {
                DeckLinkedToMatch::dispatch($match);
            }
        }

        // ── Assign league (if not already assigned) ──
        if (! $match->league_id) {
            AssignLeague::run($match, $gameMeta);
        }

        // ── Limited: snapshot the registered deck and keep the league's
        //    deck_version_id current. Shared with RelinkOrphanMatches, which
        //    has to do the same for a match whose league arrived late. ──
        if ($match->league_id) {
            $match->refresh();
            SyncLimitedMatchDeck::run($match);
        }

        // ── Assign tournament (if not already assigned) ──
        if (! $match->tournament_id) {
            AssignTournament::run($match);
        }

        $match->update(['state' => MatchState::InProgress]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: Started → InProgress", [
            'game_state_events' => $gameStateEvents->count(),
            'game_ids' => $gameStateEvents->pluck('game_id')->unique()->values()->toArray(),
            'deck_linked' => (bool) $match->deck_version_id,
            'league_id' => $match->league_id,
            'tournament_id' => $match->tournament_id,
        ]);

        if ($match->league_id) {
            LeagueMatchStarted::dispatch();
        }

        return true;
    }

    /**
     * InProgress → Ended: match end signals detected in state changes.
     */
    private static function tryAdvanceToEnded(
        MtgoMatch $match,
        Collection $events,
        Collection $stateChanges,
    ): bool {
        $matchEnded = $stateChanges->first(
            fn (LogEvent $event) => str_contains($event->context, 'TournamentMatchClosedState')
                || str_contains($event->context, 'MatchCompletedState')
                || str_contains($event->context, 'MatchEndedState')
                || str_contains($event->context, 'MatchClosedState')
                || str_contains($event->context, 'JoinedCompletedState')
        );

        $concededAndQuit = DetermineMatchResult::localPlayerConceded($stateChanges);

        if (! $matchEnded && ! $concededAndQuit) {
            Log::channel('pipeline')->debug("Match {$match->mtgo_id}: InProgress → Ended waiting", [
                'state_changes' => $stateChanges->count(),
            ]);

            return false;
        }

        $lastEvent = $events->last();
        $ended = ConvertMtgoTimestamp::run($lastEvent->logged_at, $lastEvent->timestamp);

        $match->update([
            'ended_at' => $ended,
            'state' => MatchState::Ended,
        ]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: InProgress → Ended", [
            'signal' => $matchEnded ? $matchEnded->context : 'local_concede',
        ]);

        return true;
    }
}
