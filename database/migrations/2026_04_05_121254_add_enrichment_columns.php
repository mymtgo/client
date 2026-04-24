<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $cgsExisting = Schema::getColumnListing('card_game_stats');

        Schema::table('card_game_stats', function (Blueprint $table) use ($cgsExisting) {
            if (! in_array('played', $cgsExisting, true)) {
                $table->integer('played')->nullable()->after('cast');
            }
            if (! in_array('kicked', $cgsExisting, true)) {
                $table->integer('kicked')->nullable()->after('played');
            }
            if (! in_array('flashback', $cgsExisting, true)) {
                $table->integer('flashback')->nullable()->after('kicked');
            }
            if (! in_array('madness', $cgsExisting, true)) {
                $table->integer('madness')->nullable()->after('flashback');
            }
            if (! in_array('evoked', $cgsExisting, true)) {
                $table->integer('evoked')->nullable()->after('madness');
            }
            if (! in_array('activated', $cgsExisting, true)) {
                $table->integer('activated')->nullable()->after('evoked');
            }
        });

        $gpExisting = Schema::getColumnListing('game_player');

        Schema::table('game_player', function (Blueprint $table) use ($gpExisting) {
            if (! in_array('dice_roll', $gpExisting, true)) {
                $table->integer('dice_roll')->nullable()->after('starting_hand_size');
            }
            if (! in_array('mulligan_count', $gpExisting, true)) {
                $table->integer('mulligan_count')->nullable()->after('dice_roll');
            }
        });

        if (! Schema::hasColumn('games', 'turn_count')) {
            Schema::table('games', function (Blueprint $table) {
                $table->integer('turn_count')->nullable()->after('ended_at');
            });
        }
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
