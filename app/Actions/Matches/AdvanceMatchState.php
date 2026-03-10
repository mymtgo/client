<?php

namespace App\Actions\Matches;

use App\Actions\DetermineMatchArchetypes;
use App\Actions\Util\ExtractJson;
use App\Actions\Util\ExtractKeyValueBlock;
use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Jobs\SubmitMatch;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Native\Desktop\Facades\Notification;
use Native\Desktop\Facades\Settings;

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
        $joinedState = $events->first(
            fn (LogEvent $event) => str_contains($event->context, 'MatchJoinedEventUnderwayState')
        );

        if (! $joinedState) {
            return null;
        }

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
                'ended_at' => $started, // placeholder until real end is known
                'state' => MatchState::Started,
            ]);
        }

        // ── No regression ───────────────────────────────────────────
        if ($match->state === MatchState::Complete) {
            return $match;
        }

        $gameMeta ??= ExtractKeyValueBlock::run($joinedState->raw_text);

        // ── Started → InProgress ────────────────────────────────────
        if ($match->state === MatchState::Started) {
            $advanced = self::tryAdvanceToInProgress($match, $events, $gameMeta);

            if (! $advanced) {
                return $match;
            }
        }

        // ── Create any games whose events arrived after Started → InProgress ──
        if ($match->state === MatchState::InProgress || $match->state === MatchState::Ended) {
            self::createMissingGames($match, $events);
        }

        // ── InProgress → Ended ──────────────────────────────────────
        if ($match->state === MatchState::InProgress) {
            $advanced = self::tryAdvanceToEnded($match, $events, $stateChanges);

            if (! $advanced) {
                return $match;
            }
        }

        // ── Ended → Complete ────────────────────────────────────────
        if ($match->state === MatchState::Ended) {
            self::tryAdvanceToComplete($match, $events, $stateChanges, $gameMeta);
        }

        return $match->refresh();
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
            return false;
        }

        // ── Create games (idempotent — CreateGames uses firstOrCreate) ──
        $games = $events->groupBy('game_id')->filter(
            fn ($group, $key) => $key !== '' && $key !== null
        );

        $gameIds = $games->keys();

        $decksEvents = LogEvent::where('event_type', LogEventType::DECK_USED->value)
            ->whereIn('game_id', $gameIds)
            ->get();

        $gameIndex = 0;

        foreach ($games as $gameId => $gameEvents) {
            $playerDeck = $decksEvents->first(
                fn ($event) => (int) $event->game_id === (int) $gameId
            );

            $deckJson = $playerDeck
                ? (ExtractJson::run($playerDeck->raw_text)->first() ?: [])
                : [];

            CreateGames::run($match, $gameId, $gameEvents, $gameIndex, $deckJson);
            $gameIndex++;
        }

        // ── Link deck (if not already linked) ──
        if (! $match->deck_version_id) {
            DetermineMatchDeck::run($match);
            $match->refresh();

        }

        // ── Assign league (if not already assigned) ──
        if (! $match->league_id) {
            self::assignLeague($match, $gameMeta);
        }

        $match->update(['state' => MatchState::InProgress]);

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
        );

        // Concede sequence detection (same logic as BuildMatch)
        $idxConcede = $stateChanges->search(
            fn ($e) => str_contains($e->context ?? '', 'MatchConcedeReq')
        );
        $idxNotJoined = $stateChanges->search(
            fn ($e) => str_contains($e->context ?? '', 'MatchNotJoinedUnderway')
        );
        $idxConcedeFulfilled = $stateChanges->search(
            fn ($e) => str_contains($e->context ?? '', 'MatchConcedeReqState to MatchNotJoinedEventUnderwayState')
        );

        $concededAndQuit = ($idxConcede !== false
            && $idxNotJoined !== false
            && $idxNotJoined > $idxConcede) || $idxConcedeFulfilled;

        $idxCompletedAfterNotJoined = $events
            ->slice($idxNotJoined ?: 0)
            ->search(fn ($e) => str_contains($e->message ?? '', 'MatchCompleted'));

        $concededAndQuit = $concededAndQuit
            && $idxCompletedAfterNotJoined !== false
            || $idxConcedeFulfilled !== false;

        if (! $matchEnded && ! $concededAndQuit) {
            return false;
        }

        $lastEvent = $events->last();
        $ended = now()->parse($lastEvent->logged_at)
            ->setTimeFromTimeString($lastEvent->timestamp);

        $match->update([
            'ended_at' => $ended,
            'state' => MatchState::Ended,
        ]);

        return true;
    }

    /**
     * Ended → Complete: parse results, determine win/loss, resolve archetypes,
     * send notification, dispatch SubmitMatch, mark log events processed.
     */
    private static function tryAdvanceToComplete(
        MtgoMatch $match,
        Collection $events,
        Collection $stateChanges,
        array $gameMeta,
    ): void {
        $gameLog = GetGameLog::run($match->token);

        // Trust game log results as source of truth for win/loss.
        // Cap to maximum possible games for the match type to guard
        // against the parser producing phantom results on disconnect.
        $maxGames = str_contains($gameMeta['GameStructureCd'] ?? '', 'BO5') ? 5 : 3;
        $logResults = array_slice($gameLog['results'] ?? [], 0, $maxGames);

        $wins = count(array_filter($logResults, fn ($r) => $r === true));
        $losses = count(array_filter($logResults, fn ($r) => $r === false));

        /**
         * Matches are best-of-N (BO3 needs 2 wins, BO5 needs 3).
         * If the match ended early with fewer results than expected,
         * either the local player conceded or the opponent quit.
         *
         * MatchConcedeReqState -> MatchNotJoinedEventUnderwayState only appears
         * when the LOCAL player triggers the concede protocol; an opponent quitting
         * MTGO entirely never generates that transition on our client.
         */
        $winThreshold = ($wins >= 3 || $losses >= 3) ? 3 : 2;

        if (($wins + $losses) < $winThreshold) {
            $localConceded = $stateChanges->contains(
                fn (LogEvent $event) => str_contains(
                    $event->context ?? '',
                    'MatchConcedeReqState to MatchNotJoinedEventUnderwayState'
                )
            );

            if ($localConceded) {
                // Local forfeited — opponent is awarded enough games to win
                $losses = $winThreshold;
            } else {
                // Opponent quit/disconnected — local is awarded enough games to win
                $wins = $winThreshold;
            }
        }

        $match->update([
            'games_won' => $wins,
            'games_lost' => $losses,
            'state' => MatchState::Complete,
        ]);

        DetermineMatchArchetypes::run($match);

        SubmitMatch::dispatch($match->id);

        Notification::title('New match Recorded')
            ->message($match->deck?->name.' // '.$match->games_won.'-'.$match->games_lost)
            ->show();

        // Mark all related log events as processed
        LogEvent::where('match_id', $match->mtgo_id)
            ->orWhere('match_token', $match->token)
            ->orWhereIn('game_id', $match->games->pluck('mtgo_id'))
            ->update([
                'processed_at' => now(),
            ]);
    }

    /**
     * Create games for any game_ids in log events that don't already
     * have a Game record.  Events for later games may arrive after the
     * Started → InProgress transition, so this is called again before
     * the match completes.
     */
    private static function createMissingGames(MtgoMatch $match, Collection $events): void
    {
        $games = $events->groupBy('game_id')->filter(
            fn ($group, $key) => $key !== '' && $key !== null
        );

        $existingMtgoIds = $match->games()->pluck('mtgo_id')->map(fn ($id) => (string) $id)->toArray();

        $gameIds = $games->keys();

        $decksEvents = LogEvent::where('event_type', LogEventType::DECK_USED->value)
            ->whereIn('game_id', $gameIds)
            ->get();

        $gameIndex = 0;

        foreach ($games as $gameId => $gameEvents) {
            if (in_array((string) $gameId, $existingMtgoIds, true)) {
                $gameIndex++;

                continue;
            }

            $playerDeck = $decksEvents->first(
                fn ($event) => (int) $event->game_id === (int) $gameId
            );

            $deckJson = $playerDeck
                ? (ExtractJson::run($playerDeck->raw_text)->first() ?: [])
                : [];

            CreateGames::run($match, $gameId, $gameEvents, $gameIndex, $deckJson);
            $gameIndex++;
        }
    }

    /**
     * Assign a league to the match — real league if token present, phantom otherwise.
     */
    private static function assignLeague(MtgoMatch $match, array $gameMeta): void
    {
        if (! empty($gameMeta['League Token'])) {
            $league = League::firstOrCreate([
                'token' => $gameMeta['League Token'],
                'format' => $gameMeta['PlayFormatCd'],
            ], [
                'started_at' => now(),
                'name' => trim(($gameMeta['GameStructureCd'] ?? '').' League '.now()->format('d-m-Y h:ma')),
            ]);
        } elseif (! Settings::get('hide_phantom_leagues')) {
            $match->refresh();

            $deckId = $match->deck_version_id
                ? DeckVersion::find($match->deck_version_id)?->deck_id
                : null;

            $league = self::findOrCreatePhantomLeague($gameMeta, $deckId);
        } else {
            return;
        }

        $match->update(['league_id' => $league->id]);
    }

    /**
     * Find an existing phantom league for the given deck and format, or create a new one.
     *
     * We only append to a phantom league when:
     *  - It belongs to the same deck (prevents cross-deck contamination)
     *  - It is not already flagged as having a deck change
     *  - It has fewer than 5 matches (league run limit)
     *
     * If the deck is unknown (DetermineMatchDeck found no signature match) we always
     * create a fresh league rather than risk polluting an existing one.
     */
    private static function findOrCreatePhantomLeague(array $gameMeta, ?int $deckId): League
    {
        if ($deckId) {
            $existing = League::where('format', $gameMeta['PlayFormatCd'])
                ->where('phantom', true)
                ->where('deck_change_detected', false)
                ->has('matches', '<', 5)
                ->whereHas('matches', fn ($q) => $q
                    ->join('deck_versions as dv', 'dv.id', '=', 'matches.deck_version_id')
                    ->where('dv.deck_id', $deckId)
                )
                ->latest('started_at')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return League::create([
            'token' => Str::random(),
            'format' => $gameMeta['PlayFormatCd'],
            'phantom' => true,
            'started_at' => now(),
            'name' => 'Phantom '.trim(($gameMeta['GameStructureCd'] ?? '').' League '.now()->format('d-m-Y h:ma')),
        ]);
    }
}
