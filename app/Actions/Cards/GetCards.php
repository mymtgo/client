<?php

namespace App\Actions\Cards;

use App\Models\Card;

class GetCards
{
    public static function run(array $cards)
    {
        $mappedIds = collect($cards)->map(function ($card) {
            return $card['oracle_id'] ?? $card['mtgo_id'];
        });

        return Card::whereIn('mtgo_id', $mappedIds)->orWhereIn('oracle_id', $mappedIds)->get();
    }
}
