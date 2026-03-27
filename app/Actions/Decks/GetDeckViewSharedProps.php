<?php

namespace App\Actions\Decks;

use App\Data\Front\DeckData;
use App\Enums\MatchOutcome;
use App\Models\Deck;
use Carbon\Carbon;

class GetDeckViewSharedProps
{
    /**
     * Return the props shared by all deck view sub-pages (sidebar data).
     *
     * @return array{deck: DeckData, versions: array, currentVersionId: int|null, trophies: int}
     */
    public static function run(Deck $deck, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $deck->loadCount(['wonMatches', 'lostMatches', 'matches']);
        $deck->loadMax('matches', 'started_at');

        $from ??= now()->subMonths(2)->startOfDay();
        $to ??= now()->endOfDay();

        $versions = GetDeckVersionStats::run($deck, $from, $to);

        // Find the current version ID (latest real version)
        $realVersions = array_values(array_filter($versions, fn ($v) => $v['id'] !== null));
        $currentVersion = collect($realVersions)->firstWhere('isCurrent', true)
            ?? end($realVersions) ?: null;

        $trophies = $deck->matches()
            ->select('matches.*')
            ->whereNotNull('league_id')
            ->whereHas('league', fn ($q) => $q->where('phantom', false)->where('state', 'complete'))
            ->get()
            ->groupBy('league_id')
            ->filter(fn ($matches) => $matches->every(fn ($m) => $m->outcome === MatchOutcome::Win))
            ->count();

        return [
            'deck' => DeckData::from($deck),
            'versions' => $versions,
            'currentVersionId' => $currentVersion['id'] ?? null,
            'trophies' => $trophies,
        ];
    }
}
