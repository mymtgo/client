<?php

namespace App\Http\Controllers\Leagues;

use App\Actions\Leagues\FormatLeagueRuns;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\League;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Native\Desktop\Facades\Settings;

class IndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $hidePhantom = (bool) Settings::get('hide_phantom_leagues');
        $activeAccountId = Account::active()->value('id');
        $format = $request->input('format');

        $leagues = League::query()
            ->when($hidePhantom, fn ($q) => $q->where('phantom', false))
            ->when($format, fn ($q, $f) => $q->whereHas('matches', fn ($mq) => $mq->where('format', $f)->where('state', 'complete')))
            ->when(! $format, fn ($q) => $q->whereHas('matches', fn ($mq) => $mq->where('state', 'complete')))
            ->with(['deckVersion.deck.cover'])
            ->orderByDesc('started_at')
            ->paginate(20)
            ->withQueryString();

        // Format all leagues on the current page at once (batch queries)
        $pageLeagues = collect($leagues->items());
        $formattedRuns = FormatLeagueRuns::run($pageLeagues, $activeAccountId);
        $runsByLeagueId = collect($formattedRuns)->keyBy('id');

        $leagues->through(function (League $league) use ($runsByLeagueId) {
            return $runsByLeagueId[$league->id] ?? null;
        });

        // Available formats for filter buttons
        $allFormats = League::query()
            ->whereHas('matches', fn ($q) => $q->where('state', 'complete'))
            ->join('matches', 'matches.league_id', '=', 'leagues.id')
            ->where('matches.state', 'complete')
            ->distinct()
            ->pluck('matches.format')
            ->sort()
            ->values()
            ->all();

        return Inertia::render('leagues/Index', [
            'leagues' => $leagues,
            'hidePhantomLeagues' => $hidePhantom,
            'allFormats' => $allFormats,
            'filters' => [
                'format' => $format ?? '',
            ],
        ]);
    }
}
