<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_game_stats', function (Blueprint $table) {
            $table->integer('played')->nullable()->after('cast');
            $table->integer('kicked')->nullable()->after('played');
            $table->integer('flashback')->nullable()->after('kicked');
            $table->integer('madness')->nullable()->after('flashback');
            $table->integer('evoked')->nullable()->after('madness');
            $table->integer('activated')->nullable()->after('evoked');
        });

        Schema::table('game_player', function (Blueprint $table) {
            $table->integer('dice_roll')->nullable()->after('starting_hand_size');
            $table->integer('mulligan_count')->nullable()->after('dice_roll');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->integer('turn_count')->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('card_game_stats', function (Blueprint $table) {
            $table->dropColumn(['played', 'kicked', 'flashback', 'madness', 'evoked', 'activated']);
        });

        Schema::table('game_player', function (Blueprint $table) {
            $table->dropColumn(['dice_roll', 'mulligan_count']);
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('turn_count');
        });
    }
};
