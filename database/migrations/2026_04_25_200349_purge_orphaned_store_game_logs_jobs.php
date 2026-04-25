<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jobs')) {
            DB::table('jobs')
                ->where('payload', 'like', '%StoreGameLogs%')
                ->delete();
        }

        if (Schema::hasTable('failed_jobs')) {
            DB::table('failed_jobs')
                ->where('payload', 'like', '%StoreGameLogs%')
                ->delete();
        }
    }

    public function down(): void
    {
        // Irreversible cleanup of orphaned serialized jobs.
    }
};
