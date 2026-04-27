<?php

use App\Facades\AppSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove phantom leagues and every trace of the concept.
     *
     * 1. Null matches.league_id for matches attached to a phantom league.
     * 2. Delete the phantom league rows.
     * 3. Drop the `phantom` column from leagues.
     * 4. Forget the `hide_phantom_leagues` settings key.
     *
     * Forward-only: down() is a no-op because the data is gone.
     */
    public function up(): void
    {
        if (Schema::hasColumn('leagues', 'phantom')) {
            $phantomIds = DB::table('leagues')->where('phantom', true)->pluck('id')->all();

            if (! empty($phantomIds)) {
                DB::table('matches')->whereIn('league_id', $phantomIds)->update(['league_id' => null]);
                DB::table('leagues')->whereIn('id', $phantomIds)->delete();
            }

            Schema::table('leagues', function (Blueprint $table) {
                $table->dropIndex('leagues_phantom_index');
                $table->dropColumn('phantom');
            });
        }

        AppSettings::forget('hide_phantom_leagues');
    }

    /**
     * No-op: the data deleted in up() cannot be restored. Recreating the
     * column would give the wrong impression of reversibility.
     */
    public function down(): void
    {
        // intentionally left blank
    }
};
