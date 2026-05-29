<?php

namespace App\Actions\Logs;

use App\Enums\MatchState;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class PruneProcessedLogEvents
{
    /**
     * Hard-cap age for any log event, regardless of processed/match state.
     *
     * A stalled pipeline that fails to set processed_at will silently inflate
     * log_events forever — one such stall produced a 400k-row table that
     * pushed every pipeline tick into a multi-second covering scan. The
     * normal happy-path prune below only deletes events for matches that
     * reached Complete, so this cap is the only thing that bounds growth
     * when the projection breaks.
     */
    private const HARD_CAP_DAYS = 30;

    public static function run(): void
    {
        self::pruneCompleted();
        self::pruneStale();
    }

    /**
     * Delete processed log events for completed matches.
     *
     * Once a match is Complete, its log events have been fully projected
     * into match/game/league records and the .dat file has decoded_entries
     * stored. The raw log events are no longer needed.
     */
    private static function pruneCompleted(): void
    {
        $completedTokens = MtgoMatch::where('state', MatchState::Complete)->pluck('token');

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

    /**
     * Drop any event older than the hard cap, processed or not, so a
     * silent pipeline failure cannot inflate the table unbounded.
     * Logged at warning so the cap firing surfaces as a signal that
     * the upstream projection has stalled.
     */
    private static function pruneStale(): void
    {
        $cutoff = now()->subDays(self::HARD_CAP_DAYS);

        $deleted = LogEvent::where('ingested_at', '<', $cutoff)->delete();

        if ($deleted > 0) {
            Log::channel('pipeline')->warning("PruneProcessedLogEvents: hard-cap deleted {$deleted} events older than {$cutoff->toDateTimeString()}");
        }
    }
}
