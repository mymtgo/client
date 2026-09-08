<?php

namespace App\Actions\Decks;

use App\Models\Deck;
use App\Models\DeckVersion;
use App\Support\MatchRecord;
use Carbon\Carbon;

class GetPeerArchetypeChartData
{
    /**
     * Returns sparse daily wins/losses for every other deck in the same archetype,
     * scoped to the current account and timeframe. Returns null when no archetype
     * is set, no peer decks exist, or peers have no matches in range.
     *
     * @return array{archetypeName: string, deckCount: int, data: array<int, array{date: string, wins: int, losses: int, draws: int}>}|null
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

        $rows = GetDailyMatchResults::run($peerVersionIds, $from, $to);

        if ($rows->isEmpty()) {
            return null;
        }

        $deck->loadMissing('archetype');

        return [
            'archetypeName' => $deck->archetype->name ?? 'Archetype',
            'deckCount' => $peerDeckIds->count(),
            'data' => $rows->map(fn (MatchRecord $record, string $date) => [
                'date' => $date,
                'wins' => $record->wins,
                'losses' => $record->losses,
                'draws' => $record->draws,
            ])->values()->all(),
        ];
    }
}
