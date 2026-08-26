<?php

namespace App\Actions\Limited\Read;

use App\Actions\Cards\GetCardGameStats;
use App\Actions\Limited\Analytics\ComputeCrossDraftCardStats;
use App\Actions\Limited\Analytics\ComputeSeenWheel;
use App\Data\Front\LimitedCardData;
use App\Models\Deck;
use App\Models\DraftPick;
use App\Models\League;
use Illuminate\Support\Collection;

/**
 * One row per distinct card drafted in a limited league: when it was taken,
 * where it ended up in the registered deck, how it performed in games, and
 * what the same card did in the user's other drafts of the set.
 */
class BuildLimitedCardRows
{
    /**
     * @return array{rows: array<int, array<string, mixed>>, summary: array{distinct:int, games:int, otherDrafts:int}, cards: array<string, LimitedCardData>}
     */
    public static function run(League $league): array
    {
        $league->loadMissing(['draft']);
        $draft = $league->draft;

        if (! $draft) {
            return ['rows' => [], 'summary' => ['distinct' => 0, 'games' => 0, 'otherDrafts' => 0], 'cards' => []];
        }

        $picks = $draft->picks()->get();
        $seenWheel = ComputeSeenWheel::run($picks, (int) ($draft->seat_count ?: 8));
        $status = BuildDeckEvolution::poolStatuses($league);
        $cross = ComputeCrossDraftCardStats::run($league);

        $picked = $picks->whereNotNull('picked_catalog_id')->groupBy(fn (DraftPick $pick) => (int) $pick->picked_catalog_id);
        $cards = ResolveCatalogCards::run($picked->keys());

        $deck = self::deck($league);
        $gameStats = self::gameStats($deck);
        $gamesPlayed = $deck ? (int) $deck->matches()->withCount('games')->get()->sum('games_count') : 0;

        $rows = $picked->map(function (Collection $group, int $catalogId) use ($cards, $seenWheel, $status, $cross, $gameStats) {
            $card = $cards->get((string) $catalogId);
            $oracle = $card?->oracle_id;
            $stat = $oracle !== null ? $gameStats->get($oracle) : null;
            $castWon = (int) ($stat['castWon'] ?? 0);
            $castLost = (int) ($stat['castLost'] ?? 0);
            $decided = $castWon + $castLost;
            $prior = $oracle !== null ? ($cross[$oracle] ?? null) : null;
            $facts = $seenWheel[$catalogId] ?? null;

            return [
                'catalogId' => $catalogId,
                'oracleId' => $oracle,
                'ordinals' => $group->pluck('ordinal')->map(fn ($ordinal) => (int) $ordinal)->sort()->values()->all(),
                'labels' => $group->sortBy('ordinal')->map(fn (DraftPick $pick) => "P{$pick->pack_number}p{$pick->pick_number}")->values()->all(),
                'status' => $status[$catalogId] ?? 'cut',
                'gamesCast' => (int) ($stat['castGames'] ?? 0),
                'castWon' => $castWon,
                'castLost' => $castLost,
                'winPctCast' => $decided > 0 ? (int) round($castWon / $decided * 100) : null,
                'seenCount' => (int) ($facts['seen_count'] ?? 0),
                'wheeled' => (bool) ($facts['wheeled'] ?? false),
                'priorTaken' => (int) ($prior['timesTaken'] ?? 0),
                'priorAvgOrdinal' => $prior['avgOrdinal'] ?? null,
                'priorWheeled' => (int) ($prior['timesWheeled'] ?? 0),
                'priorDrafts' => (int) ($prior['drafts'] ?? 0),
            ];
        })->sortBy(fn (array $row) => $row['ordinals'][0] ?? PHP_INT_MAX)->values()->all();

        return [
            'rows' => $rows,
            'summary' => [
                'distinct' => count($rows),
                'games' => $gamesPlayed,
                'otherDrafts' => (int) (collect($cross)->max('drafts') ?? 0),
            ],
            'cards' => $picked->keys()
                ->mapWithKeys(fn (int $id) => [(string) $id => LimitedCardData::fromCatalog($id, $cards->get((string) $id))])
                ->all(),
        ];
    }

    /**
     * The synthetic limited deck EnsureLimitedDeckVersion writes, keyed the
     * same way, or null when no deck was ever registered for this league.
     */
    private static function deck(League $league): ?Deck
    {
        $key = $league->draft?->draft_token ?? "league-{$league->id}";

        return Deck::withTrashed()->where('mtgo_id', "limited:{$key}")->first();
    }

    /**
     * Per oracle id card game stats across every registered version of this
     * league's deck. Sideboard flagging is irrelevant here: the row's status
     * already comes from the registered deck itself.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private static function gameStats(?Deck $deck): Collection
    {
        $versionIds = $deck?->versions()->pluck('id')->all() ?? [];

        if ($versionIds === []) {
            return collect();
        }

        return GetCardGameStats::forVersionIds($versionIds, collect())->keyBy('oracleId');
    }
}
