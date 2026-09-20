<?php

use App\Actions\Archetypes\AddArchetypeVariant;
use App\Models\Archetype;
use App\Models\MatchArchetype;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Manual archetypes created between the variant rollout (2026-05-07) and now
     * wrote their cards to the deprecated archetype_card pivot only, so they render
     * as empty archetypes. Rebuild a variant for each one from that pivot.
     */
    public function up(): void
    {
        Archetype::query()
            ->where('manual', true)
            ->whereDoesntHave('decks')
            ->whereHas('cards')
            ->with('cards')
            ->chunkById(100, function ($archetypes) {
                foreach ($archetypes as $archetype) {
                    $resolvedCards = $archetype->cards
                        ->map(fn ($card) => [
                            'oracle_id' => $card->oracle_id,
                            'mtgo_id' => $card->mtgo_id,
                            'quantity' => $card->pivot->quantity,
                            'sideboard' => (bool) $card->pivot->sideboard,
                        ])
                        ->all();

                    $deck = AddArchetypeVariant::run($archetype, $resolvedCards);

                    MatchArchetype::query()
                        ->where('archetype_id', $archetype->id)
                        ->whereNull('archetype_deck_id')
                        ->update(['archetype_deck_id' => $deck->id]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible: data backfill only, schema not altered here.
    }
};
