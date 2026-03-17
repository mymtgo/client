<?php

namespace App\Actions\Matches;

use App\Actions\DetermineMatchArchetypes;
use App\Actions\Util\ExtractJson;
use App\Actions\Util\ExtractKeyValueBlock;
use App\Enums\LeagueState;
use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Events\AppNotification;
use App\Events\DeckLinkedToMatch;
use App\Events\LeagueMatchStarted;
use App\Jobs\SubmitMatch;
use App\Jobs\SyncDecks;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
                    'ended_at' => $started, // placeholder until real end is known
                    'state' => MatchState::Started,
                ]);

                Log::channel('pipeline')->info("Match {$matchId}: created in Started state", [
                    'token' => $matchToken,
                    'format' => $match->format,
                    'match_type' => $match->match_type,
                ]);
            }

            // ── No regression ───────────────────────────────────────────
            if ($match->state === MatchState::Complete || $match->state === MatchState::Voided) {
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
                self::createOrUpdateGames($match, $events);
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
        self::createOrUpdateGames($match, $events);

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
            self::assignLeague($match, $gameMeta);
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

        $result = DetermineMatchResult::run($logResults, $stateChanges, $gameMeta['GameStructureCd'] ?? '');

        $match->update([
            'games_won' => $result['wins'],
            'games_lost' => $result['losses'],
            'state' => MatchState::Complete,
        ]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: Ended → Complete", [
            'result' => "{$result['wins']}-{$result['losses']}",
            'game_log_results' => count($logResults),
        ]);

        if (
            ($league = $match->league)
            && $league->state === LeagueState::Active
            && $league->matches()->where('state', MatchState::Complete)->count() >= 5
        ) {
            $league->update(['state' => LeagueState::Complete]);
        }

        SyncGameResults::run($match, $gameLog['results'] ?? []);

        // Post-completion steps: each is independent and should not
        // prevent the others from running if one fails.
        try {
            DetermineMatchArchetypes::run($match);
        } catch (\Throwable $e) {
            Log::warning("Failed to determine archetypes for match {$match->id}: {$e->getMessage()}");
        }

        SubmitMatch::dispatch($match->id);
        \App\Jobs\ComputeCardGameStats::dispatch($match->id);

        $won = $match->games_won > $match->games_lost;
        $opponentArchetype = $match->opponentArchetypes()->with('archetype')->first()?->archetype?->name ?? 'Unknown';

        AppNotification::dispatch(
            type: $won ? 'match_win' : 'match_loss',
            title: ($won ? 'Win' : 'Loss').' vs '.$opponentArchetype,
            message: $match->games_won.'-'.$match->games_lost,
            route: '/matches/'.$match->id,
        );

        // Mark all related log events as processed
        LogEvent::where(function ($query) use ($match) {
            $query->where('match_id', $match->mtgo_id)
                ->orWhere('match_token', $match->token)
                ->orWhereIn('game_id', $match->games->pluck('mtgo_id'));
        })->update([
            'processed_at' => now(),
        ]);

    }

    /**
     * Create or update games from log events.
     *
     * Groups events by game_id, fetches associated deck data, and runs
     * CreateGames for each. Safe to call multiple times — CreateGames
     * upserts game records and replaces timeline entries idempotently.
     */
    private static function createOrUpdateGames(MtgoMatch $match, Collection $events): void
    {
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

            if ($league->wasRecentlyCreated) {
                League::where('format', $gameMeta['PlayFormatCd'])
                    ->where('phantom', false)
                    ->where('state', LeagueState::Active)
                    ->where('id', '!=', $league->id)
                    ->where('started_at', '<', $league->started_at)
                    ->update(['state' => LeagueState::Partial]);
            }
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

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: assigned to league #{$league->id}", [
            'league_name' => $league->name,
            'phantom' => $league->phantom,
            'has_league_token' => ! empty($gameMeta['League Token']),
        ]);
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
