<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a card actually was, rather than one flag covering every zone.
     *
     * `seen` counts a card instance appearing in hand, battlefield, graveyard,
     * exile or stack, which is why it cannot answer whether a card rotted in
     * hand: a discarded or milled copy counts exactly like a drawn one. The
     * API reads `hand_seen` as the drawn measure and `battlefield_seen` minus
     * casts as a reanimation proxy.
     *
     * `has_zone_data` is false for a game with no timeline. Imported matches
     * have none, so their zone columns are zero for want of evidence rather
     * than because the card was never in hand, and the API's aggregation skips
     * those rows instead of reading the zeroes as measurements.
     *
     * `cast_turn` comes from the game log alone, by counting `Turn N:` markers
     * while walking messages in order. Turns spent in hand would need snapshot
     * timestamps aligned to those markers, and a snapshot's date component is
     * the date it was parsed rather than the date it was played, so that pair
     * is deliberately not attempted here.
     *
     * See api docs/specs/2026-09-10-win-rate-when-drawn.md.
     */
    public function up(): void
    {
        Schema::table('card_game_stats', function (Blueprint $table) {
            $table->unsignedTinyInteger('hand_seen')->default(0)->after('seen');
            $table->unsignedTinyInteger('graveyard_seen')->default(0)->after('hand_seen');
            $table->unsignedTinyInteger('exile_seen')->default(0)->after('graveyard_seen');
            $table->unsignedTinyInteger('battlefield_seen')->default(0)->after('exile_seen');
            $table->unsignedTinyInteger('discarded')->default(0)->after('battlefield_seen');
            $table->unsignedSmallInteger('cast_turn')->nullable()->after('discarded');
            $table->boolean('has_zone_data')->default(false)->after('cast_turn');
        });
    }

    public function down(): void
    {
        Schema::table('card_game_stats', function (Blueprint $table) {
            $table->dropColumn([
                'hand_seen', 'graveyard_seen', 'exile_seen', 'battlefield_seen',
                'discarded', 'cast_turn', 'has_zone_data',
            ]);
        });
    }
};
