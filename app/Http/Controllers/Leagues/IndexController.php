<?php

namespace App\Http\Controllers\Leagues;

use App\Actions\Leagues\FormatLeagueRuns;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\League;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $activeAccountId = Account::currentId();
        $format = $request->input('format');

        $leagues = League::query()
            ->when($format, fn ($q, $f) => $q->whereHas('matches', fn ($mq) => $mq->where('format', $f)->where('state', 'complete')))
            ->when(! $format, fn ($q) => $q->whereHas('matches', fn ($mq) => $mq->where('state', 'complete')))
            ->with(['deckVersion.deck.cover'])
            ->orderByDesc('started_at')
            ->paginate(20)
            ->withQueryString();

        $pageLeagues = collect($leagues->items());
        $formattedRuns = FormatLeagueRuns::run($pageLeagues, $activeAccountId);
        $runsByLeagueId = collect($formattedRuns)->keyBy('id');

        $leagues->through(function (League $league) use ($runsByLeagueId) {
            return $runsByLeagueId[$league->id] ?? null;
        });

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
            'allFormats' => $allFormats,
            'filters' => [
                'format' => $format ?? '',
            ],
        ]);
    }
}
