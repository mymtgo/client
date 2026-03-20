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

        // Find the latest match that has started (to avoid voiding all old matches)
        $latestMatch = MtgoMatch::whereIn('state', [MatchState::InProgress, MatchState::Complete])
            ->latest('started_at')
            ->first();

        if (! $latestMatch) {
            return;
        }

        $staleMatches = MtgoMatch::whereIn('state', [MatchState::Started, MatchState::InProgress])
            ->where('started_at', '<', $latestMatch->started_at)
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
     */
    private static function backfillGameResults(): void
    {
        $matches = MtgoMatch::where('state', MatchState::Complete)
            ->whereHas('games', fn ($q) => $q->whereNull('won'))
            ->with('games')
            ->get();

        foreach ($matches as $match) {
            $gamesOrdered = $match->games->sortBy('started_at')->values();
            $totalWins = $match->games_won ?? 0;
            $totalLosses = $match->games_lost ?? 0;

            // Simple backfill: assign wins first, then losses
            $winCount = 0;
            $lossCount = 0;

            foreach ($gamesOrdered as $game) {
                if ($game->won !== null) {
                    if ($game->won) {
                        $winCount++;
                    } else {
                        $lossCount++;
                    }

                    continue;
                }

                if ($winCount < $totalWins) {
                    $game->update(['won' => true]);
                    $winCount++;
                } elseif ($lossCount < $totalLosses) {
                    $game->update(['won' => false]);
                    $lossCount++;
                }
            }
        }
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
