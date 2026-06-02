<?php

namespace App\Actions;

use App\Jobs\ComputeCardGameStats;
use App\Models\Deck;
use App\Models\MtgoMatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class RegenerateCardGameStats
{
    /**
     * Recompute card_game_stats for all complete matches.
     *
     * @return array{queued: int}
     */
    public static function run(): array
    {
        return self::regenerate();
    }

    /**
     * Recompute card_game_stats for every complete match tied to this deck.
     *
     * @return array{queued: int}
     */
    public static function forDeck(Deck $deck): array
    {
        $deckVersionIds = $deck->versions()->pluck('id')->all();

        if (empty($deckVersionIds)) {
            return ['queued' => 0];
        }

        $result = self::regenerate(fn (Builder $query) => $query->whereIn('deck_version_id', $deckVersionIds));

        Log::info("RegenerateCardGameStats: scoped to deck {$deck->id} — queued {$result['queued']} matches");

        return $result;
    }

    /**
     * @param  callable(Builder): Builder|null  $scope
     * @return array{queued: int}
     */
    private static function regenerate(?callable $scope = null): array
    {
        $query = MtgoMatch::query()
            ->where('state', 'complete')
            ->whereNotNull('deck_version_id')
            ->whereHas('games');

        if ($scope) {
            $scope($query);
        }

        $queued = 0;
        foreach ($query->pluck('id') as $matchId) {
            ComputeCardGameStats::dispatch($matchId, fromGameLog: true)->onQueue('default');
            $queued++;
        }

        return ['queued' => $queued];
    }
}
