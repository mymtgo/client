<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Cards\GetCardGameStats;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Concerns\HasTimeframeFilter;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CardStatsController extends Controller
{
    use HasTimeframeFilter;

    public function __invoke(Deck $deck, Request $request)
    {
        $timeframe = $request->input('timeframe', 'alltime');
        [$from, $to] = $this->getTimeRange($timeframe);

        $shared = GetDeckViewSharedProps::run($deck, $from, $to);

        $deckVersion = $deck->latestVersion;

        return Inertia::render('decks/CardStats', [
            ...$shared,
            'currentPage' => 'card-stats',
            'timeframe' => $timeframe,

            'cardStats' => function () use ($deckVersion, $request) {
                if (! $deckVersion) {
                    return ['stats' => [], 'archetypes' => []];
                }

                $opponentArchetypeId = $request->filled('card_stats_archetype')
                    ? (int) $request->input('card_stats_archetype')
                    : null;

                $onPlay = $request->filled('card_stats_play_draw')
                    ? $request->input('card_stats_play_draw') === 'play'
                    : null;

                $isPostboard = $request->filled('card_stats_board')
                    ? $request->input('card_stats_board') === 'postboard'
                    : null;

                return [
                    'stats' => GetCardGameStats::run($deckVersion, $opponentArchetypeId, $onPlay, $isPostboard),
                    'archetypes' => GetCardGameStats::availableArchetypes($deckVersion),
                ];
            },
        ]);
    }
}
