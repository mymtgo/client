<?php

use App\Actions\Cards\GetCards;
use App\Models\Deck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->string('color_identity')->nullable()->after('format');
        });

        // Backfill from latest deck version cards
        $ignoredTypes = ['Artifact', 'Land', 'Basic Land'];
        $minCount = 4;

        Deck::with('latestVersion')->each(function (Deck $deck) use ($ignoredTypes, $minCount) {
            $version = $deck->latestVersion;
            if (! $version || empty($version->cards)) {
                return;
            }

            $cards = GetCards::run($version->cards);
            $colorCounts = [];

            foreach ($version->cards as $ref) {
                $card = $cards->first(fn ($c) => $c->oracle_id == $ref['oracle_id'] || $c->mtgo_id == $ref['oracle_id']);
                if (! $card || ! $card->type || in_array($card->type, $ignoredTypes)) {
                    continue;
                }

                $identity = trim($card->color_identity ?? '');
                $colors = $identity ? explode(',', $identity) : ['C'];
                $qty = (int) ($ref['quantity'] ?? 1);

                foreach ($colors as $color) {
                    $color = trim($color);
                    if ($color) {
                        $colorCounts[$color] = ($colorCounts[$color] ?? 0) + $qty;
                    }
                }
            }

            $identity = collect($colorCounts)
                ->filter(fn ($count) => $count >= $minCount)
                ->keys()
                ->join(',');

            if ($identity) {
                $deck->update(['color_identity' => $identity]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropColumn('color_identity');
        });
    }
};
