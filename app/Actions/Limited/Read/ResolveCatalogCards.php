<?php

namespace App\Actions\Limited\Read;

use App\Models\Card;
use Illuminate\Support\Collection;

class ResolveCatalogCards
{
    /**
     * Load Card rows for MTGO catalog ids. Keys are the string catalog id so
     * callers can look up with (string) $id regardless of source type.
     *
     * @param  iterable<int, int|string>  $catalogIds
     * @return Collection<string, Card>
     */
    public static function run(iterable $catalogIds): Collection
    {
        $ids = collect($catalogIds)->map(fn ($id) => (string) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Card::query()
            ->whereIn('mtgo_id', $ids->all())
            ->get()
            ->keyBy(fn (Card $card) => (string) $card->mtgo_id);
    }
}
