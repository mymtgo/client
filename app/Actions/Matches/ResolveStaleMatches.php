<?php

namespace App\Actions\Matches;

use App\Enums\MatchState;
use App\Events\AppNotification;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class ResolveStaleMatches
{
    /**
     * Detect and resolve matches stuck in Started/InProgress/Ended.
     *
     * A match is stale if a newer match has been created since it started.
     * BuildMatches has already given every match with unprocessed events
     * a chance to advance — this handles the ones that couldn't.
     */
    public static function run(): void
    {
        $latestMatchStart = MtgoMatch::latest('started_at')->value('started_at');

        if (! $latestMatchStart) {
            return;
        }

        $staleMatches = MtgoMatch::incomplete()
            ->where('started_at', '<', $latestMatchStart)
            ->get();

        if ($staleMatches->isEmpty()) {
            return;
        }

        Log::channel('pipeline')->info("ResolveStaleMatches: found {$staleMatches->count()} stale matches");

        foreach ($staleMatches as $match) {
            $isRealLeague = $match->league_id && ! $match->league?->phantom;

            if ($isRealLeague) {
                $match->update(['state' => MatchState::Ended]);

                Log::channel('pipeline')->warning("ResolveStaleMatches: match {$match->mtgo_id} ended (incomplete real league)");

                AppNotification::dispatch(
                    type: 'match_incomplete',
                    title: 'Match recorded but some data is missing',
                    message: '',
                    route: '/matches/'.$match->id,
                );
            } else {
                $match->delete();

                Log::channel('pipeline')->warning("ResolveStaleMatches: match {$match->mtgo_id} deleted (stale casual)");
            }
        }
    }
}
