<?php

namespace App\Actions\Limited\Analytics;

use App\Models\DraftPick;
use Illuminate\Support\Collection;

class ComputePickTimings
{
    public const INDECISIVE_RESERVATIONS = 2;

    /**
     * @return array{elapsed_seconds:?int, margin_seconds:?int, reservation_count:int, indecisive:bool}
     */
    public static function forPick(DraftPick $pick): array
    {
        $reservations = count($pick->reservations ?? []);

        return [
            'elapsed_seconds' => $pick->shown_at && $pick->picked_at ? (int) $pick->shown_at->diffInSeconds($pick->picked_at, true) : null,
            'margin_seconds' => $pick->picked_at && $pick->deadline_at ? (int) $pick->picked_at->diffInSeconds($pick->deadline_at, false) : null,
            'reservation_count' => $reservations,
            'indecisive' => $reservations >= self::INDECISIVE_RESERVATIONS,
        ];
    }

    /**
     * @param  Collection<int, DraftPick>  $picks
     * @return array{avg_seconds:?int, avg_margin_seconds:?int, indecisive_count:int, fastest_pack:?int, slowest_pack:?int}
     */
    public static function summary(Collection $picks): array
    {
        $all = $picks->map(fn (DraftPick $p) => ['pack' => (int) $p->pack_number, ...self::forPick($p)]);
        $timed = $all->filter(fn (array $t) => $t['elapsed_seconds'] !== null);
        $withMargin = $all->filter(fn (array $t) => $t['margin_seconds'] !== null);

        $byPack = $timed->groupBy('pack')->map(fn (Collection $g) => $g->avg('elapsed_seconds'))->sort();

        return [
            'avg_seconds' => $timed->isEmpty() ? null : (int) round($timed->avg('elapsed_seconds')),
            'avg_margin_seconds' => $withMargin->isEmpty() ? null : (int) round($withMargin->avg('margin_seconds')),
            'indecisive_count' => $picks->filter(fn (DraftPick $p) => count($p->reservations ?? []) >= self::INDECISIVE_RESERVATIONS)->count(),
            'fastest_pack' => $byPack->isEmpty() ? null : (int) $byPack->keys()->first(),
            'slowest_pack' => $byPack->isEmpty() ? null : (int) $byPack->keys()->last(),
        ];
    }
}
