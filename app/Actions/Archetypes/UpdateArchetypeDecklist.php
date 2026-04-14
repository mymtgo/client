<?php

namespace App\Actions\Archetypes;

use App\Models\Archetype;
use App\Models\Card;

class UpdateArchetypeDecklist
{
    /**
     * @param  array<int, array{oracle_id: string|null, mtgo_id: int, quantity: int, sideboard: bool}>  $resolvedCards
     */
    public static function run(
        Archetype $archetype,
        array $resolvedCards,
        string $name,
        string $format,
        ?string $colorIdentity,
    ): void {
        $archetype->update([
            'name' => $name,
            'format' => strtolower($format),
            'color_identity' => $colorIdentity,
            'manual' => true,
            'decklist_downloaded_at' => now(),
        ]);

        $pivotData = [];

        foreach ($resolvedCards as $cardData) {
            if (empty($cardData['oracle_id'])) {
                continue;
            }

            $card = Card::where('oracle_id', $cardData['oracle_id'])->first();

            if (! $card) {
                continue;
            }

            $pivotData[$card->id] = [
                'quantity' => $cardData['quantity'],
                'sideboard' => $cardData['sideboard'],
            ];
        }

        $archetype->cards()->sync($pivotData);
    }
}
