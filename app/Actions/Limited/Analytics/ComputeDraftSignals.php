<?php

namespace App\Actions\Limited\Analytics;

use App\Models\Card;
use Illuminate\Support\Collection;

class ComputeDraftSignals
{
    public const COLORS = ['W', 'U', 'B', 'R', 'G'];

    /**
     * Per colour, how many distinct cards wheeled or were seen twice or more.
     * Multicolour cards count once per colour. Ranked by score descending;
     * share is score relative to the top colour (1.0 for the leader).
     *
     * @param  array<int, array{seen_count:int, wheeled:bool}>  $seenWheel
     * @param  Collection<string, Card>  $cards
     * @return array<int, array{color:string, wheeled:int, seen_twice:int, score:int, share:float}>
     */
    public static function run(array $seenWheel, Collection $cards): array
    {
        $tally = array_fill_keys(self::COLORS, ['wheeled' => 0, 'seen_twice' => 0]);

        foreach ($seenWheel as $catalogId => $facts) {
            $card = $cards->get((string) $catalogId);
            $colors = $card ? array_intersect(str_split((string) $card->colors), self::COLORS) : [];

            foreach ($colors as $color) {
                if ($facts['wheeled']) {
                    $tally[$color]['wheeled']++;
                }
                if ($facts['seen_count'] >= 2) {
                    $tally[$color]['seen_twice']++;
                }
            }
        }

        $rows = collect($tally)->map(fn (array $t, string $color) => [
            'color' => $color,
            'wheeled' => $t['wheeled'],
            'seen_twice' => $t['seen_twice'],
            'score' => $t['wheeled'] + $t['seen_twice'],
            'share' => 0.0,
        ])->sortByDesc('score')->values();

        $top = max(1, (int) $rows->first()['score']);

        return $rows->map(fn (array $r) => [...$r, 'share' => round($r['score'] / $top, 2)])->all();
    }
}
