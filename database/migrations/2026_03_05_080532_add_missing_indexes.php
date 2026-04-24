<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_player', function (Blueprint $table) {
            if (! Schema::hasIndex('game_player', ['game_id'])) {
                $table->index('game_id');
            }
            if (! Schema::hasIndex('game_player', ['player_id'])) {
                $table->index('player_id');
            }
            if (! Schema::hasIndex('game_player', ['game_id', 'is_local'])) {
                $table->index(['game_id', 'is_local']);
            }
        });

        Schema::table('match_archetypes', function (Blueprint $table) {
            if (! Schema::hasIndex('match_archetypes', ['mtgo_match_id'])) {
                $table->index('mtgo_match_id');
            }
            if (! Schema::hasIndex('match_archetypes', ['player_id'])) {
                $table->index('player_id');
            }
            if (! Schema::hasIndex('match_archetypes', ['archetype_id'])) {
                $table->index('archetype_id');
            }
        });

        Schema::table('game_timelines', function (Blueprint $table) {
            if (! Schema::hasIndex('game_timelines', ['game_id'])) {
                $table->index('game_id');
            }
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
