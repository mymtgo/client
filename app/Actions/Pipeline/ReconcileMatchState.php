<?php

namespace App\Actions\Pipeline;

use App\Actions\Matches\DetermineMatchDeck;
use App\Enums\MatchState;
use App\Models\Deck;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class ReconcileMatchState
{
    public static function run(): void
    {
        self::resolveStaleMatches();
        self::backfillGameResults();
        self::retryDeckLinking();
    }

    /**
     * Resolve matches stuck in Started/InProgress with no recent events.
     */
    private static function resolveStaleMatches(): void
    {
        $staleThreshold = now()->subMinutes(2);

        $staleMatches = MtgoMatch::whereIn('state', [MatchState::Started, MatchState::InProgress])
            ->where('updated_at', '<', $staleThreshold)
            ->get();

        foreach ($staleMatches as $match) {
            $hasLeague = $match->league_id !== null;
            $newState = $hasLeague ? MatchState::Ended : MatchState::Voided;

            $match->update(['state' => $newState]);

            Log::channel('pipeline')->info('ReconcileMatchState: stale match resolved', [
                'match_id' => $match->mtgo_id,
                'token' => $match->token,
                'from_state' => $match->getOriginal('state'),
                'to_state' => $newState,
            ]);
        }
    }

    /**
     * Backfill null game results from completed matches.
     *
     * No-op: the event-driven pipeline handles game results as they arrive.
     * Match-level win/loss counts are no longer stored; backfilling from them
     * is not possible. Results are recorded per-game via SyncGameResults.
     */
    private static function backfillGameResults(): void
    {
        // Intentionally empty — see docblock.
    }

    /**
     * Retry deck linking for matches missing a deck version.
     */
    private static function retryDeckLinking(): void
    {
        if (! Deck::count()) {
            return;
        }

        $matches = MtgoMatch::whereNull('deck_version_id')
            ->whereIn('state', [MatchState::InProgress, MatchState::Ended, MatchState::Complete])
            ->get();

        foreach ($matches as $match) {
            DetermineMatchDeck::run($match);
        }
    }
}
