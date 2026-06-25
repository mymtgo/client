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
        $deckSource = $game?->localDeck()?->deck_json
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
        $localInstanceId = (int) ($game?->local_instance ?? 1);

        /** @var array<string, mixed> $snapshotContent */
        $snapshotContent = $snapshot->content ?? [];

        // Aggregate quantities per mtgo_id (deck signature lists each card once).
        $deckByMtgoId = $maindeck
            ->filter(fn ($c) => ! empty($c['mtgo_id']))
            ->mapWithKeys(fn ($c) => [(int) $c['mtgo_id'] => (int) $c['quantity']]);

        $cardMeta = Card::whereIn('mtgo_id', $deckByMtgoId->keys())
            ->get(['mtgo_id', 'name', 'type', 'color_identity', 'image', 'local_image'])
            ->keyBy(fn ($c) => (int) $c->mtgo_id);

        // Copies of each mtgo_id seen outside the local player's library.
        $seenOutside = self::seenOutsideLibrary($snapshotContent, $localInstanceId);
        $liveLibraryCount = self::liveLibraryCount($snapshotContent, $localInstanceId);

        $remainingByMtgoId = $deckByMtgoId->map(
            fn (int $qty, int $mtgoId) => max(0, $qty - ($seenOutside[$mtgoId] ?? 0))
        );

        $librarySize = (int) $remainingByMtgoId->sum();

        $cards = $deckByMtgoId->map(function (int $total, int $mtgoId) use ($cardMeta, $remainingByMtgoId) {
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
     * @param  array<string, mixed>  $snapshotContent
     * @return array<int, int>
     */
    private static function seenOutsideLibrary(array $snapshotContent, int $localInstanceId): array
    {
        // Exclude `Library` (still in deck) and `Sideboard` (never in deck).
        // Also exclude `Stack` — activated abilities create stack entries sharing
        // the source card's CatalogID, so a Lembas on Battlefield + its ability
        // on Stack would double-count. The corollary: a spell briefly on the
        // Stack while being cast won't decrement remaining until it resolves,
        // but that window is sub-second and self-corrects.
        return collect($snapshotContent['Cards'] ?? [])
            ->filter(fn ($c) => (int) ($c['Owner'] ?? -1) === $localInstanceId
                && ! in_array($c['Zone'] ?? null, ['Library', 'Sideboard', 'Stack'], true))
            ->groupBy(fn ($c) => (int) $c['CatalogID'])
            ->map(fn ($group) => $group->count())
            ->all();
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
