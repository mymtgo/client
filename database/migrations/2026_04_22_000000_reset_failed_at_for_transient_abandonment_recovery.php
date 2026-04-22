<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Recover matches that were permanently abandoned by the old
     * ProcessMatchEvents::handleMatchFailure logic. That code treated only the
     * literal string 'database is locked' as transient; readonly-database and
     * I/O errors consumed the 5-attempt retry budget and set failed_at,
     * skipping the match forever. With the classifier fix in place, those
     * matches are safe to un-abandon — their LogEvents are still unprocessed
     * (the failing transactions rolled back), so the pipeline will re-discover
     * and replay them on the next tick.
     *
     * Accepted trade-off: matches that were failed for legitimate non-transient
     * reasons are also reset. They will re-fail on the next tick under the new
     * classifier and return to failed_at with the proper error logged. The cost
     * is log churn, not data corruption.
     */
    public function up(): void
    {
        DB::table('matches')
            ->whereNotNull('failed_at')
            ->update([
                'failed_at' => null,
                'attempts' => 0,
            ]);
    }

    /**
     * Not reversible — we don't know which matches originally had failed_at set.
     */
    public function down(): void
    {
        //
    }
};
