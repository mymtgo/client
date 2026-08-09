<?php

namespace App\Updates;

use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Repair end times that were dated a day forward by the midnight rollover bug.
 *
 * MTGO log lines carry only a time, so the date came from the log file's mtime
 * at ingest. A line written at 23:59:58 and read seconds later — past local
 * midnight — was stamped with the following day, turning a seven-minute match
 * into a 1447-minute one. ConvertMtgoTimestamp no longer does this, but the
 * rows it already wrote are only repairable by inspection: the log events they
 * were derived from are long pruned.
 */
class FixMidnightRolloverEndTimes extends AppUpdate
{
    /**
     * No MTGO match or game runs anywhere near this long, so a duration at or
     * above it is corruption rather than a marathon.
     */
    private const IMPOSSIBLE_DURATION_HOURS = 12;

    public function run(): void
    {
        $this->repairAll(MtgoMatch::query()->whereNotNull('started_at')->whereNotNull('ended_at'));
        $this->repairAll(Game::query()->whereNotNull('started_at')->whereNotNull('ended_at'));
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function repairAll($query): void
    {
        // The threshold is interpolated rather than bound: SQLite binds it as
        // TEXT, and TEXT always sorts above REAL, so a bound comparison never
        // matches. The value is derived from a constant, never from input.
        $thresholdInDays = self::IMPOSSIBLE_DURATION_HOURS / 24;

        $query
            ->whereRaw("julianday(ended_at) - julianday(started_at) >= {$thresholdInDays}")
            ->each(function (Model $record) {
                $rolledBack = $record->ended_at->copy()->subDay();

                // Only accept the correction when it produces a plausible run.
                // Anything else is a different fault and left for manual repair
                // through the debug screens.
                if ($rolledBack->lessThan($record->started_at)) {
                    return;
                }

                $record->forceFill(['ended_at' => $rolledBack])->save();
            });
    }
}
