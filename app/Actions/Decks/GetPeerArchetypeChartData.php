<?php

namespace App\Actions\Decks;

use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Carbon\Carbon;

class GetPeerArchetypeChartData
{
    /**
     * Returns sparse daily wins/losses for every other deck in the same archetype,
     * scoped to the current account and timeframe. Returns null when no archetype
     * is set, no peer decks exist, or peers have no matches in range.
     *
     * @return array{archetypeName: string, deckCount: int, data: array<int, array{date: string, wins: int, losses: int}>}|null
     */
    public static function run(Deck $deck, Carbon $from, Carbon $to): ?array
    {
        if ($deck->archetype_id === null) {
            return null;
        }

        $peerDeckIds = Deck::query()
            ->forActiveAccount()
            ->where('archetype_id', $deck->archetype_id)
            ->where('id', '!=', $deck->id)
            ->pluck('id');

        if ($peerDeckIds->isEmpty()) {
            return null;
        }

        $peerVersionIds = DeckVersion::query()
            ->whereIn('deck_id', $peerDeckIds)
            ->pluck('id');

        if ($peerVersionIds->isEmpty()) {
            return null;
        }

        $rows = MtgoMatch::complete()
            ->selectRaw("strftime('%Y-%m-%d', started_at) as period, SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) as wins, COUNT(*) as total")
            ->whereIn('deck_version_id', $peerVersionIds)
            ->where('state', 'complete')
            ->whereBetween('started_at', [$from, $to])
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $deck->loadMissing('archetype');

        return [
            'archetypeName' => $deck->archetype?->name ?? 'Archetype',
            'deckCount' => $peerDeckIds->count(),
            'data' => $rows->map(fn ($row) => [
                'date' => $row->period,
                'wins' => (int) $row->wins,
                'losses' => (int) ($row->total - $row->wins),
            ])->all(),
        ];
    }
}
