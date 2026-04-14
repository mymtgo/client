<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Cards\GetCardGameStats;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Concerns\HasTimeframeFilter;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckVersion;
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

        $deckVersion = $request->filled('version')
            ? DeckVersion::find($request->input('version'))
            : null;

        return Inertia::render('decks/CardStats', [
            ...$shared,
            'currentVersionId' => $deckVersion?->id,
            'currentPage' => 'card-stats',
            'timeframe' => $timeframe,

            'cardStats' => function () use ($deck, $deckVersion, $request) {
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
                    'stats' => GetCardGameStats::run($deck, $deckVersion, $opponentArchetypeId, $onPlay, $isPostboard),
                    'archetypes' => GetCardGameStats::availableArchetypes($deck, $deckVersion),
                ];
            },
        ]);
    }
}
