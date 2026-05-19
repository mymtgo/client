<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Decks\GetArchetypeMatchupSpread;
use App\Actions\Reports\GetReportsSharedProps;
use App\Concerns\HasReportsFormatAutoSelection;
use App\Concerns\HasTimeframeFilter;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MatchesController extends Controller
{
    use HasReportsFormatAutoSelection, HasTimeframeFilter;

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $timeframe = $request->input('timeframe', 'alltime');
        $archetypeId = $request->filled('archetype') ? (int) $request->input('archetype') : null;
        $format = $request->input('format');

        if ($redirect = $this->autoSelectSoleFormat($request, $archetypeId, $format, 'reports.matches')) {
            return $redirect;
        }

        [$from, $to] = $this->getTimeRange($timeframe);

        $shared = GetReportsSharedProps::run($archetypeId, $format, $timeframe, $from, $to, 'matches');
        $versionIds = $shared['deckVersionIds'];
        unset($shared['deckVersionIds']);

        return Inertia::render('reports/Matches', [
            ...$shared,
            'matchupSpread' => Inertia::defer(fn () => empty($versionIds)
                ? collect()
                : GetArchetypeMatchupSpread::forVersionIds($versionIds, $from, $to)
            ),
        ]);
    }
}
