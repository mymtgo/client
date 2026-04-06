<?php

namespace App\Actions\Pipeline;

use App\Enums\MatchState;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class ResolveActiveMatches
{
    /**
     * Check game logs for active matches not already processed this tick.
     *
     * @param  array<string>  $excludeTokens  Tokens already processed in first loop
     */
    public static function run(array $excludeTokens = []): void
    {
        $activeMatches = MtgoMatch::whereIn('state', [
            MatchState::InProgress,
            MatchState::Ended,
        ])
            ->whereNull('failed_at')
            ->whereNotIn('token', $excludeTokens)
            ->get();

        foreach ($activeMatches as $match) {
            try {
                ResolveGameResults::run($match);
            } catch (\Throwable $e) {
                self::handleMatchFailure($match, $e);
            }
        }
    }

    private static function handleMatchFailure(MtgoMatch $match, \Throwable $e): void
    {
        $attempts = $match->attempts + 1;
        $updates = ['attempts' => $attempts];

        if ($attempts >= 5) {
            $updates['failed_at'] = now();
            Log::channel('pipeline')->error("Match {$match->mtgo_id}: permanently failed after {$attempts} attempts", [
                'error' => $e->getMessage(),
            ]);
        } else {
            Log::channel('pipeline')->warning("Match {$match->mtgo_id}: attempt {$attempts}/5 failed", [
                'error' => $e->getMessage(),
            ]);
        }

        // Update outside the rolled-back transaction
        $match->update($updates);
    }
}
