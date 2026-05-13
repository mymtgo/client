<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_game_stats', function (Blueprint $table): void {
            $table->integer('turn_number')->nullable()->after('played');
            $table->dropUnique('card_game_stats_oracle_id_game_id_opponent_unique');
            $table->unique(['oracle_id', 'game_id', 'opponent', 'turn_number'], 'card_game_stats_oracle_id_game_id_opponent_turn_unique');
        });
    }

    public function down(): void
    {
        Schema::table('card_game_stats', function (Blueprint $table): void {
            $table->dropUnique('card_game_stats_oracle_id_game_id_opponent_turn_unique');
            $table->unique(['oracle_id', 'game_id', 'opponent'], 'card_game_stats_oracle_id_game_id_opponent_unique');
            $table->dropColumn('turn_number');
        });
    }
};
