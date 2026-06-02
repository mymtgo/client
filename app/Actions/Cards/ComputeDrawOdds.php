<?php

namespace App\Actions\Cards;

use App\Data\Front\DrawOddsCardData;
use App\Data\Front\DrawOddsData;
use App\Data\Front\DrawOddsTypeData;
use App\Models\Card;
use App\Models\GameTimeline;
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
            ->get(['mtgo_id', 'name', 'type', 'color_identity', 'image', 'local_image'])
            ->keyBy(fn ($c) => (int) $c->mtgo_id);

        // Copies of each mtgo_id seen outside the local player's library.
        $seenOutside = self::seenOutsideLibrary($match);
        $liveLibraryCount = self::liveLibraryCount($match);

        $remainingByMtgoId = $deckByMtgoId->map(
            fn (int $qty, int $mtgoId) => max(0, $qty - ($seenOutside[$mtgoId] ?? 0))
        );

        $librarySize = (int) $remainingByMtgoId->sum();

        $cards = $deckByMtgoId->map(function (int $total, int $mtgoId) use ($cardMeta, $remainingByMtgoId, $librarySize) {
            $remaining = $remainingByMtgoId[$mtgoId];
            $meta = $cardMeta->get($mtgoId);

            return new DrawOddsCardData(
                mtgoId: $mtgoId,
                name: $meta?->name ?? "#{$mtgoId}",
                type: $meta?->type ?? 'Unknown',
                identity: $meta?->color_identity,
                image: $meta?->image_url,
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
        $snapshot = self::latestSnapshot($match);

        if (! $snapshot) {
            return [];
        }

        $localInstanceId = self::localInstanceId($match);

        return collect($snapshot->content['Cards'] ?? [])
            ->filter(fn ($c) => (int) ($c['Owner'] ?? -1) === $localInstanceId
                && ($c['Zone'] ?? null) !== 'Library')
            ->groupBy(fn ($c) => (int) $c['CatalogID'])
            ->map(fn ($group) => $group->count())
            ->all();
    }

    private static function liveLibraryCount(MtgoMatch $match): int
    {
        $snapshot = self::latestSnapshot($match);

        if (! $snapshot) {
            return 0;
        }

        $localInstanceId = self::localInstanceId($match);

        $local = collect($snapshot->content['Players'] ?? [])
            ->first(fn ($p) => (int) ($p['Id'] ?? -1) === $localInstanceId);

        return (int) ($local['LibraryCount'] ?? 0);
    }

    private static function latestSnapshot(MtgoMatch $match): ?GameTimeline
    {
        $game = $match->games()->latest('started_at')->first();

        return $game?->timeline->sortBy('timestamp')->last();
    }

    private static function localInstanceId(MtgoMatch $match): int
    {
        $game = $match->games()->latest('started_at')->first();

        return (int) ($game?->localPlayers->first()?->pivot->instance_id ?? 1);
    }

    /**
     * @param  Collection<int, DrawOddsCardData>  $cards
     */
    private static function topFive(Collection $cards, int $librarySize): DataCollection
    {
        if ($librarySize <= 0) {
            return DrawOddsTypeData::collect([], DataCollection::class);
        }

        $byType = $cards
            ->groupBy(fn (DrawOddsCardData $c) => self::bucket($c->type))
            ->map(fn (Collection $group) => (int) $group->sum(fn (DrawOddsCardData $c) => $c->remaining));

        $rows = $byType
            ->map(fn (int $remaining, string $type) => new DrawOddsTypeData(
                type: $type,
                probability: self::atLeastOneInSample($librarySize, $remaining, 5),
            ))
            ->filter(fn (DrawOddsTypeData $t) => $t->probability > 0)
            ->sortByDesc(fn (DrawOddsTypeData $t) => $t->probability)
            ->values();

        return DrawOddsTypeData::collect($rows->all(), DataCollection::class);
    }

    /**
     * P(X >= 1) for drawing a sample of $n from a population of $population
     * containing $successes successes. Computed as 1 - P(X = 0) via the running
     * product of (non-success)/(remaining) ratios. Safe for deck-sized populations.
     */
    private static function atLeastOneInSample(int $population, int $successes, int $n): float
    {
        if ($population <= 0 || $successes <= 0) {
            return 0.0;
        }

        if ($successes >= $population) {
            return 1.0;
        }

        $n = min($n, $population);
        $pZero = 1.0;

        for ($i = 0; $i < $n; $i++) {
            $pZero *= ($population - $successes - $i) / ($population - $i);
        }

        return min(1.0, max(0.0, 1.0 - $pZero));
    }

    private static function bucket(string $type): string
    {
        if (str_contains($type, 'Land')) {
            return 'Land';
        }

        foreach (['Creature', 'Instant', 'Sorcery', 'Artifact', 'Enchantment', 'Planeswalker'] as $known) {
            if (str_contains($type, $known)) {
                return $known;
            }
        }

        return 'Other';
    }
}
