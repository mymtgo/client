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
                ->where('payload', 'like', '%DetermineMatchArchetypesJob%')
                ->delete();
        }

        if (Schema::hasColumn('matches', 'archetype_detection_queued_at')) {
            DB::table('matches')->update(['archetype_detection_queued_at' => null]);
        }
    }

    public function down(): void
    {
        // No-op: this migration only purges transient queue state.
    }
};
