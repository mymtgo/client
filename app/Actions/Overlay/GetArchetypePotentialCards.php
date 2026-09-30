<?php

namespace App\Actions\Overlay;

use App\Data\Front\PotentialCardData;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use Illuminate\Support\Collection;
use Spatie\LaravelData\DataCollection;

/**
 * Cards the opponent's archetype plays that they have not shown yet, drawn
 * from every downloaded list for that archetype. A card any list plays main
 * is a maindeck suggestion; a card the lists only ever sideboard is a
 * sideboard one. Each carries the copies the lists usually play, less the
 * copies already revealed, and drops out once all of those are seen. Most
 * common first, so the likely threats lead and the one-of flex slots trail.
 * Basic lands tell the player nothing and are left out.
 */
class GetArchetypePotentialCards
{
    /**
     * @param  array<string, int>  $revealedQuantities  card name => copies revealed
     * @return array{maindeck: DataCollection<int, PotentialCardData>, sideboard: DataCollection<int, PotentialCardData>}
     */
    public static function run(Archetype $archetype, array $revealedQuantities): array
    {
        $revealed = collect($revealedQuantities)->mapWithKeys(fn (int $quantity, string $name) => [mb_strtolower($name) => $quantity]);

        $rows = ArchetypeDeck::query()
            ->where('archetype_id', $archetype->id)
            ->with('cards:id,mtgo_id,name,type,image,local_image,art_crop,local_art_crop')
            ->get()
            ->toBase()
            ->flatMap(fn (ArchetypeDeck $list) => $list->cards->map(fn (Card $card) => [
                'list' => $list->id,
                'card' => $card,
                'quantity' => (int) $card->pivot->quantity,
                'sideboard' => (bool) $card->pivot->sideboard,
            ]))
            ->reject(fn (array $row) => str_contains((string) $row['card']->type, 'Basic'));

        $mainNames = $rows->reject(fn (array $row) => $row['sideboard'])->pluck('card.name')->unique()->flip();

        return [
            'maindeck' => self::rank($rows->filter(fn (array $row) => ! $row['sideboard']), $revealed),
            'sideboard' => self::rank($rows->filter(fn (array $row) => $row['sideboard'] && ! $mainNames->has($row['card']->name)), $revealed),
        ];
    }

    /**
     * @param  Collection<int, array{list: int, card: Card, quantity: int, sideboard: true}>|Collection<int, array{list: int, card: Card, quantity: int, sideboard: false}>  $rows
     * @param  Collection<string, int>  $revealed
     * @return DataCollection<int, PotentialCardData>
     */
    private static function rank(Collection $rows, Collection $revealed): DataCollection
    {
        $cards = $rows
            ->groupBy(fn (array $row) => $row['card']->name)
            ->map(fn (Collection $group) => [
                'lists' => $group->pluck('list')->unique()->count(),
                'card' => $group->first()['card'],
                'remaining' => self::typicalQuantity($group->pluck('quantity')) - (int) $revealed->get(mb_strtolower((string) $group->first()['card']->name), 0),
            ])
            ->filter(fn (array $entry) => $entry['remaining'] > 0)
            ->sortBy([
                fn (array $a, array $b) => $b['lists'] <=> $a['lists'],
                fn (array $a, array $b) => strcasecmp($a['card']->name, $b['card']->name),
            ])
            ->map(fn (array $entry) => new PotentialCardData(
                mtgoId: $entry['card']->mtgo_id ? (int) $entry['card']->mtgo_id : null,
                name: (string) $entry['card']->name,
                type: $entry['card']->type ?? 'Unknown',
                image: $entry['card']->image_url,
                artCrop: $entry['card']->art_crop_url,
                quantity: $entry['remaining'],
            ))
            ->values();

        return PotentialCardData::collect($cards->all(), DataCollection::class);
    }

    /**
     * The copy count most lists play; an even split takes the higher count,
     * since overestimating a threat costs the player less than missing one.
     *
     * @param  Collection<int, int>  $quantities
     */
    private static function typicalQuantity(Collection $quantities): int
    {
        return (int) $quantities->countBy()
            ->sortKeysDesc()
            ->sortDesc()
            ->keys()
            ->first();
    }
}
