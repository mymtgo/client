<?php

namespace App\Actions\Cards;

use App\Data\Front\DrawOddsCardData;
use App\Data\Front\DrawOddsData;
use App\Data\Front\DrawOddsTypeData;
use App\Models\MtgoMatch;
use Illuminate\Support\Collection;
use Spatie\LaravelData\DataCollection;

class ComputeDrawOdds
{
    public static function run(MtgoMatch $match): ?DrawOddsData
    {
        $deckVersion = $match->deckVersion;

        if (! $deckVersion) {
            return null;
        }

        $maindeck = collect($deckVersion->cards)
            ->reject(fn ($card) => (bool) ($card['sideboard'] ?? false))
            ->values();

        if ($maindeck->isEmpty()) {
            return null;
        }

        return self::build($match, $maindeck);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $maindeck
     */
    private static function build(MtgoMatch $match, Collection $maindeck): DrawOddsData
    {
        // Implemented incrementally in Tasks 3–5.
        return new DrawOddsData(
            cards: DrawOddsCardData::collect([], DataCollection::class),
            topFive: DrawOddsTypeData::collect([], DataCollection::class),
            librarySize: 0,
            liveLibraryCount: 0,
        );
    }
}
