<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->string('outcome')->nullable()->index()->after('state');
        });

        // Backfill outcome from games_won/games_lost
        DB::table('matches')->where('games_won', '>', DB::raw('games_lost'))->update(['outcome' => 'win']);
        DB::table('matches')->where('games_lost', '>', DB::raw('games_won'))->update(['outcome' => 'loss']);
        DB::table('matches')
            ->where('games_won', '=', DB::raw('games_lost'))
            ->where('games_won', '>', 0)
            ->update(['outcome' => 'draw']);
        // Leave the rest as null (unknown) — they'll get set to 'unknown' when accessed

        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex('matches_games_won_index');
            $table->dropIndex('matches_games_lost_index');
            $table->dropColumn(['games_won', 'games_lost']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->integer('games_won')->default(0)->after('state');
            $table->integer('games_lost')->default(0)->after('games_won');
            $table->dropColumn('outcome');
        });
    }
};
