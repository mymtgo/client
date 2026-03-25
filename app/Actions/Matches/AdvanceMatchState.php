<?php

namespace App\Actions\Matches;

use App\Actions\Util\ExtractKeyValueBlock;
use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Events\DeckLinkedToMatch;
use App\Events\LeagueMatchStarted;
use App\Jobs\SyncDecks;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

        // Wrap all state-advancement writes in a single transaction so
        // the SQLite write-lock is held once instead of 10–15 times.
        return DB::transaction(function () use ($matchToken, $matchId, $events, $stateChanges, $joinedState) {
            // ── Find or create the match ────────────────────────────────
            $match = MtgoMatch::where('mtgo_id', $matchId)->first();

            if (! $match) {
                $gameMeta = ExtractKeyValueBlock::run($joinedState->raw_text);

                $started = now()->parse($joinedState->logged_at)
                    ->setTimeFromTimeString($joinedState->timestamp);

                $match = MtgoMatch::create([
                    'mtgo_id' => $matchId,
                    'token' => $matchToken,
                    'format' => $gameMeta['PlayFormatCd'] ?? 'Unknown',
                    'match_type' => $gameMeta['GameStructureCd'] ?? 'Unknown',
                    'started_at' => $started,
                    'ended_at' => null,
                    'state' => MatchState::Started,
                ]);

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

        // ── Create games (idempotent — CreateGames uses firstOrCreate) ──
        CreateOrUpdateGames::run($match, $events);

        // ── Link deck (if not already linked) ──
        if (! $match->deck_version_id) {
            DetermineMatchDeck::run($match);
            $match->refresh();

            // No match found — sync decks from disk and retry
            if (! $match->deck_version_id) {
                SyncDecks::dispatchSync();
                DetermineMatchDeck::run($match);
                $match->refresh();
            }

            if ($match->deck_version_id) {
                DeckLinkedToMatch::dispatch($match);
            }
        }

        // ── Assign league (if not already assigned) ──
        if (! $match->league_id) {
            AssignLeague::run($match, $gameMeta);
        }

        $match->update(['state' => MatchState::InProgress]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: Started → InProgress", [
            'game_state_events' => $gameStateEvents->count(),
            'game_ids' => $gameStateEvents->pluck('game_id')->unique()->values()->toArray(),
            'deck_linked' => (bool) $match->deck_version_id,
            'league_id' => $match->league_id,
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
            Log::channel('pipeline')->info("Match {$match->mtgo_id}: InProgress → Ended waiting", [
                'state_changes' => $stateChanges->count(),
                'contexts' => $stateChanges->pluck('context')->toArray(),
            ]);

            return false;
        }

        $lastEvent = $events->last();
        $ended = now()->parse($lastEvent->logged_at)
            ->setTimeFromTimeString($lastEvent->timestamp);

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
