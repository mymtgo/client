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
        $existing = Schema::getColumnListing('card_game_stats');

        Schema::table('card_game_stats', function (Blueprint $table) use ($existing) {
            if (! in_array('pregame_revealed', $existing, true)) {
                $table->boolean('pregame_revealed')->default(false)->after('activated');
            }
            if (! in_array('pregame_played', $existing, true)) {
                $table->boolean('pregame_played')->default(false)->after('pregame_revealed');
            }
        });
    }

    public function down(): void
    {
        Schema::table('card_game_stats', function (Blueprint $table) {
            $table->dropColumn(['pregame_revealed', 'pregame_played']);
        });
    }
};
