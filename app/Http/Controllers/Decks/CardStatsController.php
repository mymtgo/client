<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Cards\GetCardGameStats;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CardStatsController extends Controller
{
    public function __invoke(Deck $deck, Request $request)
    {
        $shared = GetDeckViewSharedProps::run($deck);

        $deckVersion = $deck->latestVersion;

        return Inertia::render('decks/CardStats', [
            ...$shared,
            'currentPage' => 'card-stats',

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

                return [
                    'stats' => GetCardGameStats::run($deckVersion, $opponentArchetypeId, $onPlay),
                    'archetypes' => GetCardGameStats::availableArchetypes($deckVersion),
                ];
            },
        ]);
    }
}
