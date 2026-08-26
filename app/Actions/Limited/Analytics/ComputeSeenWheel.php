<?php

namespace App\Actions\Limited\Analytics;

use App\Models\DraftPick;
use Illuminate\Support\Collection;

class ComputeSeenWheel
{
    /**
     * Per catalog id facts derived from the packs shown at every pick.
     *
     * A card "wheels" when the same physical pack (pack_id) comes back
     * seat_count picks later and the card is still in it.
     *
     * @param  Collection<int, DraftPick>  $picks
     * @return array<int, array{seen_count:int, first_seen_ordinal:int, wheeled:bool, wheeled_to_me:bool, picked_count:int, passed_count:int}>
     */
    public static function run(Collection $picks, int $seatCount): array
    {
        $byOrdinal = $picks->keyBy('ordinal');
        $facts = [];

        foreach ($picks->sortBy('ordinal') as $pick) {
            $available = array_map('intval', $pick->cards_available ?? []);
            $picked = $pick->picked_catalog_id !== null ? (int) $pick->picked_catalog_id : null;
            $return = $byOrdinal->get($pick->ordinal + $seatCount);
            $returnSamePack = $return && $pick->pack_id !== null && (int) $return->pack_id === (int) $pick->pack_id;
            $returnAvailable = $returnSamePack ? array_map('intval', $return->cards_available ?? []) : [];

            foreach ($available as $id) {
                $facts[$id] ??= ['seen_count' => 0, 'first_seen_ordinal' => $pick->ordinal, 'wheeled' => false, 'wheeled_to_me' => false, 'picked_count' => 0, 'passed_count' => 0];
                $facts[$id]['seen_count']++;

                if ($picked === $id) {
                    $facts[$id]['picked_count']++;
                } else {
                    $facts[$id]['passed_count']++;
                }

                if ($returnSamePack && in_array($id, $returnAvailable, true)) {
                    $facts[$id]['wheeled'] = true;
                    if ($return->picked_catalog_id !== null && (int) $return->picked_catalog_id === $id) {
                        $facts[$id]['wheeled_to_me'] = true;
                    }
                }
            }
        }

        return $facts;
    }

    /**
     * What came back when this pick's pack wheeled, or null if it never did.
     *
     * @param  Collection<int, DraftPick>  $picks
     * @return array{return_ordinal:int, survived:array<int,int>, taken:array<int,int>}|null
     */
    public static function wheelForPick(Collection $picks, DraftPick $pick, int $seatCount): ?array
    {
        $return = $picks->firstWhere('ordinal', $pick->ordinal + $seatCount);

        if (! $return || $pick->pack_id === null || (int) $return->pack_id !== (int) $pick->pack_id) {
            return null;
        }

        $shown = array_map('intval', $pick->cards_available ?? []);
        $picked = $pick->picked_catalog_id !== null ? (int) $pick->picked_catalog_id : null;
        $back = array_map('intval', $return->cards_available ?? []);

        $candidates = array_values(array_filter($shown, fn (int $id) => $id !== $picked));

        return [
            'return_ordinal' => (int) $return->ordinal,
            'survived' => array_values(array_filter($candidates, fn (int $id) => in_array($id, $back, true))),
            'taken' => array_values(array_filter($candidates, fn (int $id) => ! in_array($id, $back, true))),
        ];
    }
}
