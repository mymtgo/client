<?php

namespace App\Actions\Limited\Analytics;

use App\Actions\Limited\Read\ResolveCatalogCards;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use App\Models\LimitedDeckSnapshot;
use Illuminate\Support\Collection;

class ComputeCrossDraftCardStats
{
    /**
     * How every card of this set fared in the user's other drafts of the
     * same set, keyed by oracle id so reprints merge.
     *
     * @return array<string, array{oracleId:string, drafts:int, timesTaken:int, avgOrdinal:?float, timesPassed:int, timesWheeled:int, madeDeck:int}>
     */
    public static function run(League $league): array
    {
        if (! $league->set_code) {
            return [];
        }

        $drafts = Draft::query()
            ->whereHas('league', fn ($q) => $q->where('set_code', $league->set_code)->where('id', '!=', $league->id))
            ->with('picks')
            ->get();

        if ($drafts->isEmpty()) {
            return [];
        }

        $snapshots = LimitedDeckSnapshot::query()
            ->whereIn('league_id', $drafts->pluck('league_id')->filter())
            ->where('source', 'registered')
            ->orderBy('captured_at')
            ->get()
            ->groupBy('league_id')
            ->map(fn (Collection $g) => $g->last());

        $allIds = $drafts->flatMap(fn (Draft $d) => $d->picks->flatMap(fn (DraftPick $p) => array_map('intval', $p->cards_available ?? [])))
            ->merge($snapshots->flatMap(fn (LimitedDeckSnapshot $s) => collect($s->cards)->pluck('catalog_id')))
            ->unique();

        $cards = ResolveCatalogCards::run($allIds);
        $oracleOf = fn (int $id): ?string => $cards->get((string) $id)?->oracle_id;

        $stats = [];
        $ensure = function (string $oracle) use (&$stats): void {
            $stats[$oracle] ??= ['oracleId' => $oracle, 'drafts' => 0, 'timesTaken' => 0, 'ordinals' => [], 'timesPassed' => 0, 'timesWheeled' => 0, 'madeDeck' => 0];
        };

        foreach ($drafts as $draft) {
            $facts = ComputeSeenWheel::run($draft->picks, (int) ($draft->seat_count ?: 8));
            $seenOracles = [];

            foreach ($facts as $catalogId => $f) {
                $oracle = $oracleOf((int) $catalogId);
                if ($oracle === null) {
                    continue;
                }
                $ensure($oracle);
                $seenOracles[$oracle] = true;
                $stats[$oracle]['timesTaken'] += $f['picked_count'];
                $stats[$oracle]['timesPassed'] += $f['passed_count'];
                $stats[$oracle]['timesWheeled'] += $f['wheeled'] ? 1 : 0;
            }

            foreach ($draft->picks as $pick) {
                if ($pick->picked_catalog_id === null) {
                    continue;
                }
                $oracle = $oracleOf((int) $pick->picked_catalog_id);
                if ($oracle !== null) {
                    /**
                     * A committed pick can name a card that never appeared in
                     * the pack it recorded, so the seen/wheel pass above may
                     * not have created this oracle's row yet.
                     */
                    $ensure($oracle);
                    $stats[$oracle]['ordinals'][] = (int) $pick->ordinal;
                }
            }

            foreach (array_keys($seenOracles) as $oracle) {
                $stats[$oracle]['drafts']++;
            }

            $snapshot = $draft->league_id ? $snapshots->get($draft->league_id) : null;
            if ($snapshot) {
                $mainOracles = collect($snapshot->cards)
                    ->where('sideboard', false)
                    ->filter(fn ($c) => isset($c['catalog_id']))
                    ->map(fn ($c) => $oracleOf((int) $c['catalog_id']))
                    ->filter()
                    ->unique();
                foreach ($mainOracles as $oracle) {
                    $ensure($oracle);
                    $stats[$oracle]['madeDeck']++;
                }
            }
        }

        return collect($stats)->map(function (array $s) {
            $ordinals = $s['ordinals'];
            unset($s['ordinals']);

            return [...$s, 'avgOrdinal' => $ordinals === [] ? null : round(array_sum($ordinals) / count($ordinals), 1)];
        })->all();
    }
}
