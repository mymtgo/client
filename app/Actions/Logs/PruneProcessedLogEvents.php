<?php

namespace App\Actions\Logs;

use App\Enums\MatchState;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class PruneProcessedLogEvents
{
    /**
     * Delete processed log events for completed matches.
     *
     * Once a match is Complete or Voided, its log events have been fully
     * projected into match/game/league records and the .dat file has
     * decoded_entries stored. The raw log events are no longer needed.
     */
    public static function run(): void
    {
        $completedTokens = MtgoMatch::whereIn('state', [
            MatchState::Complete,
            MatchState::Voided,
        ])->pluck('token');

        if ($completedTokens->isEmpty()) {
            return;
        }

        $deleted = LogEvent::whereNotNull('processed_at')
            ->whereIn('match_token', $completedTokens)
            ->delete();

        if ($deleted > 0) {
            Log::channel('pipeline')->info("PruneProcessedLogEvents: deleted {$deleted} events for {$completedTokens->count()} completed matches");
        }
    }
}
