<?php

namespace App\Actions\Cards;

use Illuminate\Support\Facades\DB;

class CreateMissingCardsFromTimelines
{
    /**
     * Scan game timelines for CatalogIDs that don't have card stubs.
     *
     * Tokens and other permanents only appear in timeline game state data,
     * not in deck lists, so their CatalogIDs may not have Card records.
     */
    public static function run(): void
    {
        $missingIds = DB::table('game_timelines')
            ->crossJoin(DB::raw("json_each(json_extract(game_timelines.content, '$.Cards')) as je"))
            ->leftJoin('cards', 'cards.mtgo_id', '=', DB::raw("json_extract(je.value, '$.CatalogID')"))
            ->whereNull('cards.id')
            ->selectRaw("DISTINCT json_extract(je.value, '$.CatalogID') as catalog_id")
            ->pluck('catalog_id')
            ->filter()
            ->values()
            ->toArray();

        if (! empty($missingIds)) {
            CreateMissingCards::run($missingIds);
        }
    }
}
