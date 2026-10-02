<?php

namespace App\Actions\Limited\Read;

use App\Models\League;
use App\Models\LimitedDeckSnapshot;

class ReadSealedPool
{
    /**
     * The cards a sealed player opened, read off their registered decks.
     * Sealed has no picks, but a limited registered deck holds the whole
     * pool: the sideboard is every pool card not in the main deck. So the
     * pool is the most copies of each card any registered deck held, and
     * whatever the first deck did not hold was added later (the booster
     * MTGO lets a sealed player add after match 3). Basics are free, not
     * opened, so they are left out.
     *
     * Uses the league's loaded deckSnapshots when present so a listing that
     * eager loads them pays no extra snapshot query per league.
     *
     * @return array{pool: array<int, int>, added: array<int, int>}
     */
    public static function run(League $league): array
    {
        $snapshots = ($league->relationLoaded('deckSnapshots') ? $league->deckSnapshots : $league->deckSnapshots()->get())
            ->where('source', 'registered')
            ->sortBy('captured_at')
            ->values();

        $totals = $snapshots->map(fn (LimitedDeckSnapshot $snapshot) => self::totals($snapshot->cards));

        $pool = [];
        foreach ($totals as $total) {
            foreach ($total as $id => $quantity) {
                $pool[$id] = max($pool[$id] ?? 0, $quantity);
            }
        }

        $basics = self::basics(array_keys($pool));
        $pool = array_diff_key($pool, array_flip($basics));
        ksort($pool);

        $first = $totals->first() ?? [];
        $added = [];
        foreach ($pool as $id => $quantity) {
            if ($quantity > ($first[$id] ?? 0)) {
                $added[$id] = $quantity - ($first[$id] ?? 0);
            }
        }

        return ['pool' => $pool, 'added' => $added];
    }

    /**
     * Copies of each card across both zones of one registered deck.
     *
     * @param  array<int, array{catalog_id?: int|string, quantity?: int|string}>  $cards
     * @return array<int, int>
     */
    private static function totals(array $cards): array
    {
        $total = [];
        foreach ($cards as $card) {
            if (! isset($card['catalog_id'], $card['quantity'])) {
                continue;
            }
            $id = (int) $card['catalog_id'];
            $total[$id] = ($total[$id] ?? 0) + (int) $card['quantity'];
        }

        return array_filter($total);
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private static function basics(array $ids): array
    {
        return ResolveCatalogCards::run(collect($ids))
            ->filter(fn ($card) => $card?->type === 'Basic Land')
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
