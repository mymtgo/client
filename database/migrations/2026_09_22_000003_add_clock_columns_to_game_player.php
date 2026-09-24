<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chess clock summary per player per game, sidecar-only. Null when the
     * sidecar did not cover the game. The match clock carries across games,
     * so start and end are both kept. sideboard_ms_used is the sideboarding
     * time spent before this game (null for game 1).
     */
    public function up(): void
    {
        Schema::table('game_player', function (Blueprint $table) {
            $table->unsignedInteger('clock_remaining_ms_start')->nullable();
            $table->unsignedInteger('clock_remaining_ms_end')->nullable();
            $table->unsignedInteger('clock_remaining_ms_min')->nullable();
            $table->unsignedInteger('sideboard_ms_used')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('game_player', function (Blueprint $table) {
            $table->dropColumn(['clock_remaining_ms_start', 'clock_remaining_ms_end', 'clock_remaining_ms_min', 'sideboard_ms_used']);
        });
    }
};
