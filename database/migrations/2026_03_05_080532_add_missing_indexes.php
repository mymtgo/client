<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_player', function (Blueprint $table) {
            $table->index('game_id');
            $table->index('player_id');
            $table->index(['game_id', 'is_local']);
        });

        Schema::table('match_archetypes', function (Blueprint $table) {
            $table->index('mtgo_match_id');
            $table->index('player_id');
            $table->index('archetype_id');
        });

        Schema::table('game_timelines', function (Blueprint $table) {
            $table->index('game_id');
        });
    }

    public function down(): void
    {
        Schema::table('game_player', function (Blueprint $table) {
            $table->dropIndex(['game_id']);
            $table->dropIndex(['player_id']);
            $table->dropIndex(['game_id', 'is_local']);
        });

        Schema::table('match_archetypes', function (Blueprint $table) {
            $table->dropIndex(['mtgo_match_id']);
            $table->dropIndex(['player_id']);
            $table->dropIndex(['archetype_id']);
        });

        Schema::table('game_timelines', function (Blueprint $table) {
            $table->dropIndex(['game_id']);
        });
    }
};
