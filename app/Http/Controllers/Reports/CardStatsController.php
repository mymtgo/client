<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Cards\GetCardGameStats;
use App\Actions\Reports\GetReportSideboardOracles;
use App\Actions\Reports\GetReportsSharedProps;
use App\Concerns\HasReportsFormatAutoSelection;
use App\Concerns\HasTimeframeFilter;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CardStatsController extends Controller
{
    use HasReportsFormatAutoSelection, HasTimeframeFilter;

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $timeframe = $request->input('timeframe', 'alltime');
        $archetypeId = $request->filled('archetype') ? (int) $request->input('archetype') : null;
        $format = $request->input('format');

        if ($redirect = $this->autoSelectSoleFormat($request, $archetypeId, $format, 'reports.card-stats')) {
            return $redirect;
        }

        [$from, $to] = $this->getTimeRange($timeframe);

        $shared = GetReportsSharedProps::run($archetypeId, $format, $timeframe, $from, $to, 'card-stats');
        $versionIds = $shared['deckVersionIds'];
        unset($shared['deckVersionIds']);

        $opponentArchetypeId = $request->filled('card_stats_archetype')
            ? (int) $request->input('card_stats_archetype')
            : null;
        $onPlay = $request->filled('card_stats_play_draw')
            ? $request->input('card_stats_play_draw') === 'play'
            : null;
        $isPostboard = $request->filled('card_stats_board')
            ? $request->input('card_stats_board') === 'postboard'
            : null;
        $opponent = $request->input('card_stats_perspective') === 'theirs';

        return Inertia::render('reports/CardStats', [
            ...$shared,
            'cardStats' => function () use ($versionIds, $opponentArchetypeId, $onPlay, $isPostboard, $opponent) {
                if (empty($versionIds)) {
                    return [
                        'stats' => collect(),
                        'archetypes' => collect(),
                        'perspective' => $opponent ? 'theirs' : 'mine',
                    ];
                }

                $sideboardOracles = $opponent ? collect() : GetReportSideboardOracles::run($versionIds);

                return [
                    'stats' => GetCardGameStats::forVersionIds(
                        $versionIds,
                        $sideboardOracles,
                        $opponentArchetypeId,
                        $onPlay,
                        $isPostboard,
                        $opponent,
                    ),
                    'archetypes' => GetCardGameStats::availableArchetypesForVersionIds($versionIds),
                    'perspective' => $opponent ? 'theirs' : 'mine',
                ];
            },
        ]);
    }
}
