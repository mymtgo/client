<?php

namespace App\Actions\Cards;

use App\Jobs\PopulateMissingCardData;
use App\Models\Card;
use Illuminate\Support\Facades\Log;

class CreateMissingCards
{
    /**
     * Highest value accepted as an MTGO catalog id.
     *
     * Catalog ids are five- or six-digit and climb slowly, so this sits well
     * above anything MTGO has issued. It exists to catch values that are not
     * catalog ids at all: a misread log line or a bad decode has put numbers
     * like 89215000000 into this table before, and once a stub exists for one
     * it can never resolve, so it counts as missing card data forever.
     */
    private const MAX_CATALOG_ID = 1_000_000;

    /**
     * @param  array<int, mixed>  $cardIds
     */
    public static function run(array $cardIds): void
    {
        [$accepted, $rejected] = collect($cardIds)
            ->partition(fn ($id) => self::isPlausibleCatalogId($id));

        // A caller handing this anything but catalog ids is a bug in that
        // caller, and the row it would create is unresolvable, so it is worth
        // naming loudly. Stubs for non-card ids have reached this table
        // before and there was no record of where they came from.
        if ($rejected->isNotEmpty()) {
            Log::channel('pipeline')->warning('CreateMissingCards: refused ids that are not MTGO catalog ids.', [
                'rejected' => $rejected->values()->all(),
            ]);
        }

        $cardIds = $accepted
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($cardIds->isEmpty()) {
            return;
        }

        $existing = Card::whereIn('mtgo_id', $cardIds)->pluck('mtgo_id');

        $newCards = $cardIds->diff($existing);

        if ($newCards->isEmpty()) {
            return;
        }

        Card::insert(
            $newCards->map(
                fn ($cardId) => ['mtgo_id' => $cardId, 'created_at' => now(), 'updated_at' => now()]
            )->toArray()
        );

        PopulateMissingCardData::dispatch();
    }

    /**
     * A catalog id is a non-negative whole number inside MTGO's range.
     * Anything else came from somewhere it should not have.
     */
    private static function isPlausibleCatalogId(mixed $id): bool
    {
        if (is_int($id)) {
            return $id >= 0 && $id <= self::MAX_CATALOG_ID;
        }

        if (! is_string($id) || ! ctype_digit($id)) {
            return false;
        }

        return (int) $id <= self::MAX_CATALOG_ID;
    }
}
