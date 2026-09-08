<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dev machines may carry the earlier kept_hand_json shape. It never
        // shipped, so drop it rather than migrate its contents.
        if (Schema::hasColumn('game_player', 'kept_hand_json')) {
            Schema::table('game_player', function (Blueprint $table) {
                $table->dropColumn('kept_hand_json');
            });
        }

        if (Schema::hasColumn('game_player', 'opening_hand_json')) {
            return;
        }

        Schema::table('game_player', function (Blueprint $table) {
            $table->json('opening_hand_json')->nullable()->after('deck_json');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('game_player', 'opening_hand_json')) {
            return;
        }

        Schema::table('game_player', function (Blueprint $table) {
            $table->dropColumn('opening_hand_json');
        });
    }
};
