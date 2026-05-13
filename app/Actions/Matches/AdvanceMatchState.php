<?php

namespace App\Actions\Matches;

use App\Actions\Logs\ConvertMtgoTimestamp;
use App\Actions\Tournaments\AssignTournament;
use App\Actions\Util\ExtractKeyValueBlock;
use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Events\DeckLinkedToMatch;
use App\Events\LeagueMatchStarted;
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
            fn (LogEvent $event) => TransitionMatchState::isJoinSignal($event->context)
        ) ?? $stateChanges->first(
            fn (LogEvent $event) => TransitionMatchState::isJoinSignal($event->context)
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

        // Wrap all state-advancement writes in a single transaction so
        // the SQLite write-lock is held once instead of 10–15 times.
        return TimedTransaction::run("AdvanceMatchState:{$matchId}", function () use ($matchToken, $matchId, $events, $stateChanges, $joinedState) {
            $match = MtgoMatch::where('mtgo_id', $matchId)->first();

            if (! $match) {
                $match = CreateMatchFromEvents::run($matchToken, $matchId, $events, $joinedState);

                if (! $match) {
                    return null;
                }
            }

            // ── No regression ───────────────────────────────────────────
            if ($match->state === MatchState::Complete || $match->failed_at !== null) {
                return $match;
            }

            $gameMeta = ExtractKeyValueBlock::run($joinedState->raw_text);

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

        CreateOrUpdateGames::run($match, $events);

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

        if (! $match->league_id) {
            AssignLeague::run($match, $gameMeta);
        }

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
            fn (LogEvent $event) => TransitionMatchState::isEndSignal($event->context)
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
