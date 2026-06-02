<?php

namespace App\Actions\Cards;

use App\Data\Front\DrawOddsCardData;
use App\Data\Front\DrawOddsData;
use App\Data\Front\DrawOddsTypeData;
use App\Models\Card;
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
        // Aggregate quantities per mtgo_id (deck signature lists each card once).
        $deckByMtgoId = $maindeck
            ->filter(fn ($c) => ! empty($c['mtgo_id']))
            ->mapWithKeys(fn ($c) => [(int) $c['mtgo_id'] => (int) $c['quantity']]);

        $cardMeta = Card::whereIn('mtgo_id', $deckByMtgoId->keys())
            ->get(['mtgo_id', 'name', 'type'])
            ->keyBy(fn ($c) => (int) $c->mtgo_id);

        // How many copies of each mtgo_id have left the local player's library.
        $seenOutside = self::seenOutsideLibrary($match); // [mtgoId => count]; empty for now (Task 4)
        $liveLibraryCount = self::liveLibraryCount($match); // 0 for now (Task 4)

        $remainingByMtgoId = $deckByMtgoId->map(
            fn (int $qty, int $mtgoId) => max(0, $qty - ($seenOutside[$mtgoId] ?? 0))
        );

        $librarySize = (int) $remainingByMtgoId->sum();

        $cards = $deckByMtgoId->map(function (int $total, int $mtgoId) use ($cardMeta, $remainingByMtgoId, $librarySize) {
            $remaining = $remainingByMtgoId[$mtgoId];
            $meta = $cardMeta->get($mtgoId);

            return new DrawOddsCardData(
                name: $meta?->name ?? "#{$mtgoId}",
                type: $meta?->type ?? 'Unknown',
                remaining: $remaining,
                total: $total,
                drawChance: $librarySize > 0 ? $remaining / $librarySize : 0.0,
            );
        })
            ->values()
            ->sortByDesc(fn (DrawOddsCardData $c) => $c->drawChance)
            ->values();

        return new DrawOddsData(
            cards: DrawOddsCardData::collect($cards->all(), DataCollection::class),
            topFive: self::topFive($cards, $librarySize),
            librarySize: $librarySize,
            liveLibraryCount: $liveLibraryCount,
        );
    }

    /**
     * @return array<int, int>
     */
    private static function seenOutsideLibrary(MtgoMatch $match): array
    {
        return []; // Task 4
    }

    private static function liveLibraryCount(MtgoMatch $match): int
    {
        return 0; // Task 4
    }

    /**
     * @param  Collection<int, DrawOddsCardData>  $cards
     */
    private static function topFive(Collection $cards, int $librarySize): DataCollection
    {
        return DrawOddsTypeData::collect([], DataCollection::class); // Task 5
    }
}
