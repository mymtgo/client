<?php

namespace App\Actions\Cards;

use App\Jobs\PopulateMissingCardData;
use App\Models\Card;

/**
 * Send every stored token back through the card lookup.
 *
 * Tokens resolved by name before the API knew MTGO's token catalog hold
 * whichever printing came first (a green 2/2 Cat for a white 1/1). Clearing
 * their printing makes the next lookup ask by catalog id, which answers with
 * the exact printing. Selected by type line, not rarity: most resolved tokens
 * no longer carry rarity "token".
 */
class ReresolveTokenPrintings
{
    /** How many token rows were cleared. */
    public static function run(): int
    {
        $cleared = Card::query()->where('type', 'like', 'Token%')->update(['scryfall_id' => null]);

        PopulateMissingCardData::dispatch();

        return $cleared;
    }
}
