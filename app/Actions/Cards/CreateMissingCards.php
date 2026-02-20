<?php

namespace App\Actions\Cards;

use App\Jobs\PopulateMissingCardData;
use App\Models\Card;

class CreateMissingCards
{
    public static function run(array $cardIds)
    {
        $cardIds = collect($cardIds)->unique()->values();

        $cardModels = Card::whereIn('mtgo_id', $cardIds)->get();

        $newCards = $cardIds->diff($cardModels->pluck('mtgo_id'));

        Card::insert(
            $newCards->map(
                fn ($cardId) => ['mtgo_id' => $cardId, 'created_at' => now(), 'updated_at' => now()]
            )->toArray()
        );

        if ($newCards->isNotEmpty()) {
            PopulateMissingCardData::dispatchSync();
        }
    }
}
