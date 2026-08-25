<?php

namespace App\Actions\Overlay;

use App\Actions\Archetypes\AggregateOpponentCards;
use App\Data\Front\RevealedCardData;
use App\Models\Card;
use App\Models\MtgoMatch;
use Illuminate\Support\Collection;
use Spatie\LaravelData\DataCollection;

class GetOpponentReveals
{
    /**
     * Every card the opponent has revealed this match, with metadata resolved
     * for display. Quantities are summed per printing by
     * AggregateOpponentCards, then printings sharing a name collapse into one
     * row (a player sees one card, not four printings), capped at the deck
     * limit of 4. Cheap enough for the overlay's 5s poll — one deck_json read
     * plus one card lookup.
     *
     * @return DataCollection<int, RevealedCardData>
     */
    public static function run(MtgoMatch $match): DataCollection
    {
        // 1v1: flatten every non-local player's aggregate into one list.
        $revealed = collect(AggregateOpponentCards::run($match))->flatten(1);

        $cardMeta = Card::whereIn('mtgo_id', $revealed->pluck('mtgo_id'))
            ->get(['mtgo_id', 'name', 'type', 'color_identity', 'image', 'local_image', 'art_crop', 'local_art_crop'])
            ->keyBy(fn ($c) => (int) $c->mtgo_id);

        $cards = $revealed
            ->map(fn (array $card) => [
                'mtgoId' => $card['mtgo_id'],
                'meta' => $cardMeta->get($card['mtgo_id']),
                'quantity' => $card['quantity'],
            ])
            ->groupBy(fn (array $card) => $card['meta']?->name ?? "#{$card['mtgoId']}")
            ->map(function (Collection $printings, string $name) {
                $first = $printings->first();

                return new RevealedCardData(
                    mtgoId: $first['mtgoId'],
                    name: $name,
                    type: $first['meta']?->type ?? 'Unknown',
                    identity: $first['meta']?->color_identity,
                    image: $first['meta']?->image_url,
                    artCrop: $printings->pluck('meta')->first(fn ($meta) => $meta?->art_crop_url)?->art_crop_url,
                    quantity: min(4, (int) $printings->sum('quantity')),
                );
            })
            ->sortBy(fn (RevealedCardData $card) => $card->name)
            ->values();

        return RevealedCardData::collect($cards->all(), DataCollection::class);
    }
}
