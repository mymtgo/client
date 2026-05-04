<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original add_opponent_flag migration short-circuited via hasColumn,
     * so existing installs got the column but kept the old (oracle_id, game_id)
     * unique. That collides on the second pass when opp rows share an oracle
     * with local rows. Idempotently bring every install onto the 3-column key.
     */
    public function up(): void
    {
        $indexes = collect(Schema::getIndexes('card_game_stats'));

        $hasOldUnique = $indexes->contains(
            fn ($i) => $i['name'] === 'card_game_stats_oracle_id_game_id_unique' && ($i['unique'] ?? false)
        );

        $hasNewUnique = $indexes->contains(
            fn ($i) => $i['name'] === 'card_game_stats_oracle_id_game_id_opponent_unique' && ($i['unique'] ?? false)
        );

        if ($hasOldUnique) {
            Schema::table('card_game_stats', function (Blueprint $table) {
                $table->dropUnique(['oracle_id', 'game_id']);
            });
        }

        if (! $hasNewUnique) {
            Schema::table('card_game_stats', function (Blueprint $table) {
                $table->unique(['oracle_id', 'game_id', 'opponent']);
            });
        }
    }

    public function down(): void
    {
        $indexes = collect(Schema::getIndexes('card_game_stats'));

        $hasNewUnique = $indexes->contains(
            fn ($i) => $i['name'] === 'card_game_stats_oracle_id_game_id_opponent_unique' && ($i['unique'] ?? false)
        );

        if ($hasNewUnique) {
            Schema::table('card_game_stats', function (Blueprint $table) {
                $table->dropUnique(['oracle_id', 'game_id', 'opponent']);
                $table->unique(['oracle_id', 'game_id']);
            });
        }
    }
};
