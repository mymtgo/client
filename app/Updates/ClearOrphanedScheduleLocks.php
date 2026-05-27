<?php

namespace App\Updates;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClearOrphanedScheduleLocks extends AppUpdate
{
    /**
     * Release orphaned scheduler mutexes left behind when NativePHP SIGKILLs
     * the `schedule:run` process every 60s while a `withoutOverlapping` task is
     * mid-run. The lock is never released, lives in the database cache store,
     * and survives app restarts — so it silently blocks the task (e.g. the
     * every-2s `process_matches` pipeline) until its multi-hour TTL expires.
     *
     * Runs once on launch via RunAppUpdates, before the scheduler starts.
     */
    public function run(): void
    {
        foreach (['cache_locks', 'cache'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('key', 'like', '%framework/schedule-%')->delete();
            }
        }
    }
}
