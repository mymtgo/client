<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('card_game_stats', 'opponent')) {
            return;
        }

        Schema::table('card_game_stats', function (Blueprint $table) {
            $table->boolean('opponent')->index()->default(false);
        });

        Schema::table('card_game_stats', function (Blueprint $table) {
            $table->dropUnique(['oracle_id', 'game_id']);
            $table->unique(['oracle_id', 'game_id', 'opponent']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('card_game_stats', function (Blueprint $table) {
            $table->dropUnique(['oracle_id', 'game_id', 'opponent']);
            $table->unique(['oracle_id', 'game_id']);
        });

        Schema::table('card_game_stats', function (Blueprint $table) {
            $table->dropColumn('opponent');
        });
    }
};
