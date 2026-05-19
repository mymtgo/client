<?php

namespace App\Actions\Reports;

use App\Models\DeckVersion;
use Illuminate\Support\Collection;

class GetReportSideboardOracles
{
    /**
     * Union of oracle_ids flagged as sideboard across the given deck versions.
     *
     * Handles the string values returned by the DeckVersion cards accessor:
     * 'true'/'false' (old oracle-id format) and '1'/'0' (new numeric format).
     * Also handles bool true for robustness.
     *
     * @param  array<int, int>  $deckVersionIds
     * @return Collection<string, true> oracle_id => true (flipped map for has() checks)
     */
    public static function run(array $deckVersionIds): Collection
    {
        if (empty($deckVersionIds)) {
            return collect();
        }

        return DeckVersion::query()
            ->whereIn('id', $deckVersionIds)
            ->get()
            ->flatMap(fn (DeckVersion $version) => collect($version->cards)
                ->filter(fn ($card) => self::isSideboard($card['sideboard'] ?? false))
                ->pluck('oracle_id')
                ->filter(fn ($id) => $id !== null))
            ->unique()
            ->flip()
            ->map(fn () => true);
    }

    private static function isSideboard(mixed $sideboard): bool
    {
        return $sideboard === true
            || $sideboard === 'true'
            || $sideboard === '1'
            || $sideboard === 1;
    }
}
