<?php

namespace App\Actions\Matches;

use App\Actions\DetermineMatchArchetypes;
use App\Actions\Util\ExtractJson;
use App\Actions\Util\ExtractKeyValueBlock;
use App\Enums\LogEventType;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Native\Desktop\Facades\Notification;

class BuildMatch
{
    public static function run(string $matchToken, int|string $matchId): ?MtgoMatch
    {
        $events = LogEvent::where('match_id', $matchId)->orderBy('timestamp')->get();

        $stateChanges = LogEvent::where('match_token', $matchToken)->where('event_type', LogEventType::MATCH_STATE_CHANGED->value)->get()->values();

        $idxConcede = $stateChanges->search(fn ($e) => str_contains($e->context ?? '', 'MatchConcedeReq'));
        $idxNotJoined = $stateChanges->search(fn ($e) => str_contains($e->context ?? '', 'MatchNotJoinedUnderway'));
        $idxConcedeFulfilled = $stateChanges->search(fn ($e) => str_contains($e->context ?? '', 'MatchConcedeReqState to MatchNotJoinedEventUnderwayState'));

        $concededAndQuit = ($idxConcede !== false
            && $idxNotJoined !== false
            && $idxNotJoined > $idxConcede) || $idxConcedeFulfilled;

        $idxCompletedAfterNotJoined = $events
            ->slice($idxNotJoined)
            ->search(fn ($e) => str_contains($e->message ?? '', 'MatchCompleted')
            );

        $matchEnded = $stateChanges->first(
            fn (LogEvent $event) => str_contains($event->context, 'TournamentMatchClosedState') || str_contains($event->context, 'MatchCompletedState') || str_contains($event->context, 'MatchEndedState') || str_contains($event->context, 'MatchClosedState')
        );

        $concededAndQuit = $concededAndQuit && $idxCompletedAfterNotJoined !== false || $idxConcedeFulfilled !== false;

        $canFinalize = $matchEnded || $concededAndQuit;

        $joinedState = $events->first(
            fn (LogEvent $event) => str_contains($event->context, 'MatchJoinedEventUnderwayState')
        );

        if (MtgoMatch::where('mtgo_id', $matchId)->exists()) {
            LogEvent::where('match_id', $matchId)
                ->orWhere('match_token', $matchId)
                ->update([
                    'processed_at' => now(),
                ]);

            return null;
        }

        if (! $joinedState || ! $canFinalize) {
            return null;
        }

        $gameMeta = ExtractKeyValueBlock::run($joinedState->raw_text);

        if (! empty($gameMeta['League Token'])) {
            $league = League::firstOrCreate([
                'token' => $gameMeta['League Token'],
                'format' => $gameMeta['PlayFormatCd'],
            ], [
                'started_at' => now(),
                'name' => trim(($gameMeta['GameStructureCd'] ?? '').' League '.now()->format('d-m-Y h:ma')),
            ]);
        } else {
            // Phantom league assignment is deferred until after DetermineMatchDeck
            // so we can match on deck identity. See below.
            $league = null;
        }

        $lastEvent = $events->last();

        $started = now()->parse($joinedState->logged_at)->setTimeFromTimeString($joinedState->timestamp);

        $ended = now()->parse($lastEvent->logged_at)->setTimeFromTimeString($lastEvent->timestamp);

        DB::beginTransaction();

        /**
         * Have we already got this match?
         */
        $match = MtgoMatch::create([
            'mtgo_id' => $matchId,
            'league_id' => $league?->id,
            'token' => $matchToken,
            'format' => $gameMeta['PlayFormatCd'],
            'match_type' => $gameMeta['GameStructureCd'],
            'started_at' => $started,
            'ended_at' => $ended,
        ]);

        $games = $events->groupBy('game_id');

        $gameIds = $games->keys();

        /**
         * Build out our initial deck, we use the first game as it's
         * a high chance it'll be the deck from the collection.
         */
        $decksEvents = LogEvent::where('event_type', 'deck_used')->whereIn('game_id', $gameIds)->get();

        $gameIndex = 0;

        foreach ($games as $gameId => $gameEvents) {
            $playerDeck = $decksEvents->first(
                fn ($event) => (int) $event->game_id === $gameId
            );

            $deckJson = ExtractJson::run($playerDeck->raw_text)->first() ?: [];

            CreateGames::run($match, $gameId, $gameEvents, $gameIndex, $deckJson);
            $gameIndex++;
        }

        DetermineMatchDeck::run($match);

        // Assign phantom league now that we know which deck was used.
        if (! $league) {
            $match->refresh();

            $deckId = $match->deck_version_id
                ? DeckVersion::find($match->deck_version_id)?->deck_id
                : null;

            $league = self::findOrCreatePhantomLeague($gameMeta, $deckId);

            $match->update(['league_id' => $league->id]);
        }

        $gameLog = GetGameLog::run($match->token);
        $gameCount = $games->count();

        // Cap log results to actual game count — the game log parser can
        // produce a phantom extra result when the opponent disconnects
        // after the final game (terminal event fires fallback).
        $logResults = array_slice($gameLog['results'] ?? [], 0, $gameCount);

        $wins = count(array_filter($logResults, fn ($r) => $r === true));
        $losses = count(array_filter($logResults, fn ($r) => $r === false));

        /**
         * Matches are best-of-N (BO3 needs 2 wins, BO5 needs 3).
         * Determine the win threshold from the game count: a completed
         * BO3 has 2–3 games, a completed BO5 has 3–5 games.
         * If the match ended early with fewer results than expected,
         * either the local player conceded or the opponent quit.
         *
         * MatchConcedeReqState → MatchNotJoinedEventUnderwayState only appears
         * when the LOCAL player triggers the concede protocol; an opponent quitting
         * MTGO entirely never generates that transition on our client.
         */
        $winThreshold = $gameCount >= 3 && ($wins >= 3 || $losses >= 3) ? 3 : 2;

        if (($wins + $losses) < $winThreshold) {
            $localConceded = $stateChanges->contains(
                fn (LogEvent $event) => str_contains($event->context ?? '', 'MatchConcedeReqState to MatchNotJoinedEventUnderwayState')
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
        ]);

        DetermineMatchArchetypes::run($match);

        DB::commit();

        Notification::title('New match Recorded')
            ->message($match->deck?->name.' // '.$match->games_won.'-'.$match->games_lost)
            ->show();

        LogEvent::where('match_id', $match->mtgo_id)
            ->orWhere('match_token', $match->token)
            ->orWhereIn('game_id', $match->games->pluck('mtgo_id'))
            ->update([
                'processed_at' => now(),
            ]);

        return $match;
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
