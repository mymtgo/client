<?php

namespace App\Actions\Cards;

use App\Data\Front\DrawOddsCardData;
use App\Data\Front\DrawOddsData;
use App\Models\Card;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Support\Collection;
use Spatie\LaravelData\DataCollection;

class ComputeDrawOdds
{
    public static function run(MtgoMatch $match): ?DrawOddsData
    {
        // Prefer the per-game `deck_json` on the local player pivot: it reflects
        // the actual maindeck/sideboard split for *this* game, so sided-in cards
        // appear in games 2/3 and sided-out cards drop out. Fall back to the
        // match-level deck version when no game has been recorded yet.
        $game = $match->games()->latest('started_at')->first();
        $deckSource = $game?->localPlayers->first()?->pivot->deck_json
            ?: $match->deckVersion?->cards
            ?? [];

        $maindeck = collect($deckSource)
            ->reject(fn ($card) => filter_var($card['sideboard'] ?? false, FILTER_VALIDATE_BOOLEAN))
            ->values();

        if ($maindeck->isEmpty()) {
            return null;
        }

        return self::build($match, $maindeck, $game);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $maindeck
     */
    private static function build(MtgoMatch $match, Collection $maindeck, ?Game $game): DrawOddsData
    {
        $snapshot = $game?->timeline()->latest('timestamp')->first();
        $localInstanceId = (int) ($game?->localPlayers->first()?->pivot->instance_id ?? 1);

        /** @var array<string, mixed> $snapshotContent */
        $snapshotContent = $snapshot->content ?? [];

        // Aggregate quantities per mtgo_id (deck signature lists each card once).
        $deckByMtgoId = $maindeck
            ->filter(fn ($c) => ! empty($c['mtgo_id']))
            ->mapWithKeys(fn ($c) => [(int) $c['mtgo_id'] => (int) $c['quantity']]);

        $cardMeta = Card::whereIn('mtgo_id', $deckByMtgoId->keys())
            ->get(['mtgo_id', 'oracle_id', 'name', 'type', 'color_identity', 'image', 'local_image', 'art_crop', 'local_art_crop'])
            ->keyBy(fn ($c) => (int) $c->mtgo_id);

        // Copies of each mtgo_id seen outside the local player's library.
        $seenOutside = self::seenOutsideLibrary($snapshotContent, $localInstanceId, $deckByMtgoId, $cardMeta);
        $liveLibraryCount = self::liveLibraryCount($snapshotContent, $localInstanceId);

        $remainingByMtgoId = $deckByMtgoId->map(
            fn (int $qty, int $mtgoId) => max(0, $qty - ($seenOutside[$mtgoId] ?? 0))
        );

        $librarySize = (int) $remainingByMtgoId->sum();

        // Merge printings: the remaining math runs per CatalogID (snapshot
        // zones reference the exact printing), but the player sees one card —
        // a deck with four different Urza's Mine printings is one row of 4.
        $cards = $deckByMtgoId
            ->map(fn (int $total, int $mtgoId) => [
                'mtgoId' => $mtgoId,
                'meta' => $cardMeta->get($mtgoId),
                'remaining' => $remainingByMtgoId[$mtgoId],
                'total' => $total,
            ])
            ->groupBy(fn (array $card) => $card['meta']?->name ?? "#{$card['mtgoId']}")
            ->map(function (Collection $printings, string $name) {
                $first = $printings->first();

                return new DrawOddsCardData(
                    mtgoId: $first['mtgoId'],
                    name: $name,
                    type: $first['meta']?->type ?? 'Unknown',
                    identity: $first['meta']?->color_identity,
                    image: $first['meta']?->image_url,
                    artCrop: $printings->pluck('meta')->first(fn ($meta) => $meta?->art_crop_url)?->art_crop_url,
                    remaining: (int) $printings->sum('remaining'),
                    total: (int) $printings->sum('total'),
                );
            })
            ->values()
            ->sortByDesc(fn (DrawOddsCardData $c) => $c->remaining)
            ->values();

        return new DrawOddsData(
            cards: DrawOddsCardData::collect($cards->all(), DataCollection::class),
            librarySize: $librarySize,
            liveLibraryCount: $liveLibraryCount,
        );
    }

    /**
     * Copies of each deck card seen outside the local player's library, keyed
     * by the deck's CatalogID.
     *
     * @param  array<string, mixed>  $snapshotContent
     * @param  Collection<int, int>  $deckByMtgoId
     * @param  Collection<int, Card>  $cardMeta
     * @return array<int, int>
     */
    private static function seenOutsideLibrary(array $snapshotContent, int $localInstanceId, Collection $deckByMtgoId, Collection $cardMeta): array
    {
        // Exclude `Library` (still in deck) and `Sideboard` (never in deck).
        // Also exclude `Stack` — activated abilities create stack entries sharing
        // the source card's CatalogID, so a Lembas on Battlefield + its ability
        // on Stack would double-count. The corollary: a spell briefly on the
        // Stack while being cast won't decrement remaining until it resolves,
        // but that window is sub-second and self-corrects.
        $outside = collect($snapshotContent['Cards'] ?? [])
            ->filter(fn ($c) => (int) ($c['Owner'] ?? -1) === $localInstanceId
                && ! in_array($c['Zone'] ?? null, ['Library', 'Sideboard', 'Stack'], true));

        $resolve = self::deckCardResolver($outside, $deckByMtgoId, $cardMeta);
        $seen = [];

        foreach ($outside as $card) {
            $candidates = $resolve((int) $card['CatalogID'], $card['Name'] ?? null);

            if ($candidates === []) {
                continue;
            }

            // Several printings of one card: charge the first that still has copies left.
            $target = collect($candidates)->first(fn (int $id) => ($seen[$id] ?? 0) < $deckByMtgoId[$id]) ?? $candidates[0];
            $seen[$target] = ($seen[$target] ?? 0) + 1;
        }

        return $seen;
    }

    /**
     * Snapshot zones do not always use the deck's CatalogID. A modal
     * double-faced card played as its back face (Witch Enchanter as
     * Witch-Blessed Meadow) carries the face's own id, and other printings of
     * a card carry theirs. Match on the exact id, then on a shared oracle id,
     * then on the snapshot's face name against the deck card's faces.
     *
     * @param  Collection<int, array<string, mixed>>  $outside
     * @param  Collection<int, int>  $deckByMtgoId
     * @param  Collection<int, Card>  $cardMeta
     * @return callable(int, ?string): array<int, int>
     */
    private static function deckCardResolver(Collection $outside, Collection $deckByMtgoId, Collection $cardMeta): callable
    {
        $unknownIds = $outside->pluck('CatalogID')->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $deckByMtgoId->has($id))
            ->unique()->values();

        $oracleByUnknownId = $unknownIds->isEmpty()
            ? collect()
            : Card::whereIn('mtgo_id', $unknownIds)->whereNotNull('oracle_id')->pluck('oracle_id', 'mtgo_id')
                ->mapWithKeys(fn ($oracle, $id) => [(int) $id => $oracle]);

        $deckIdsByOracle = [];
        $deckIdsByFaceName = [];

        foreach ($deckByMtgoId->keys() as $mtgoId) {
            $meta = $cardMeta->get($mtgoId);

            if ($meta?->oracle_id) {
                $deckIdsByOracle[$meta->oracle_id][] = $mtgoId;
            }

            foreach (array_unique([$meta?->name, ...explode(' // ', (string) $meta?->name)]) as $face) {
                if ($face) {
                    $deckIdsByFaceName[mb_strtolower($face)][] = $mtgoId;
                }
            }
        }

        return function (int $catalogId, ?string $name) use ($deckByMtgoId, $oracleByUnknownId, $deckIdsByOracle, $deckIdsByFaceName): array {
            if ($deckByMtgoId->has($catalogId)) {
                return [$catalogId];
            }

            $oracle = $oracleByUnknownId->get($catalogId);

            if ($oracle !== null && isset($deckIdsByOracle[$oracle])) {
                return $deckIdsByOracle[$oracle];
            }

            return $name ? ($deckIdsByFaceName[mb_strtolower($name)] ?? []) : [];
        };
    }

    /**
     * @param  array<string, mixed>  $snapshotContent
     */
    private static function liveLibraryCount(array $snapshotContent, int $localInstanceId): int
    {
        $local = collect($snapshotContent['Players'] ?? [])
            ->first(fn ($p) => (int) ($p['Id'] ?? -1) === $localInstanceId);

        return (int) ($local['LibraryCount'] ?? 0);
    }
}
