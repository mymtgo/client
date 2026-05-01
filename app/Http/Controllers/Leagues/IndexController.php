<?php

namespace App\Http\Controllers\Leagues;

use App\Actions\Leagues\FormatLeagueRuns;
use App\Actions\Leagues\GetLeagueKpis;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Deck;
use App\Models\League;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $activeAccountId = Account::currentId();

        $format = $request->input('format');
        $state = $request->input('state', 'all');
        $deckId = $request->input('deck');
        $search = $request->input('q');
        $sort = $request->input('sort', 'newest');

        $base = $this->buildQuery($format, $state, $deckId, $search);

        $leaguesQuery = (clone $base)->with(['deckVersion.deck.cover']);

        $leaguesQuery = match ($sort) {
            'oldest' => $leaguesQuery->orderBy('started_at'),
            'best' => $leaguesQuery
                ->withCount(['matches as wins_in_run' => fn ($q) => $q->where('outcome', 'win')->where('state', 'complete')])
                ->orderByDesc('wins_in_run')
                ->orderByDesc('started_at'),
            'worst' => $leaguesQuery
                ->withCount(['matches as wins_in_run' => fn ($q) => $q->where('outcome', 'win')->where('state', 'complete')])
                ->orderBy('wins_in_run')
                ->orderByDesc('started_at'),
            default => $leaguesQuery->orderByDesc('started_at'),
        };

        $leagues = $leaguesQuery->paginate(20)->withQueryString();

        $pageLeagues = collect($leagues->items());
        $formatted = FormatLeagueRuns::run($pageLeagues, $activeAccountId);
        $byId = collect($formatted)->keyBy('id');
        $leagues->through(fn (League $l) => $byId[$l->id] ?? null);

        $allFormats = League::query()
            ->whereHas('matches', fn ($q) => $q->where('state', 'complete'))
            ->join('matches', 'matches.league_id', '=', 'leagues.id')
            ->where('matches.state', 'complete')
            ->distinct()
            ->pluck('matches.format')
            ->sort()
            ->values()
            ->all();

        $allDecks = Deck::query()
            ->whereIn('id', function ($q) {
                $q->select('dv.deck_id')
                    ->from('deck_versions as dv')
                    ->join('leagues as l', 'l.deck_version_id', '=', 'dv.id')
                    ->whereNull('l.deleted_at');
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])
            ->values()
            ->all();

        $kpis = GetLeagueKpis::run($base);

        return Inertia::render('leagues/Index', [
            'leagues' => $leagues,
            'kpis' => $kpis,
            'allFormats' => $allFormats,
            'allDecks' => $allDecks,
            'filters' => [
                'format' => $format ?? '',
                'state' => $state ?? 'all',
                'deck' => $deckId ? (int) $deckId : null,
                'q' => $search ?? '',
                'sort' => $sort,
            ],
        ]);
    }

    private function buildQuery(?string $format, ?string $state, ?string $deckId, ?string $search): Builder
    {
        return League::query()
            ->whereHas('matches', fn ($mq) => $mq->where('state', 'complete'))
            ->when($format, fn ($q, $f) => $q->whereHas('matches', fn ($mq) => $mq->where('format', $f)->where('state', 'complete')))
            ->when($state === 'live', fn ($q) => $q->where('state', 'active'))
            ->when($state === 'trophies', fn ($q) => $q
                ->where('state', 'complete')
                ->whereHas('matches', fn ($mq) => $mq->where('outcome', 'win')->where('state', 'complete'), '=', 5))
            ->when($state === 'cash', fn ($q) => $q
                ->where('state', 'complete')
                ->whereHas('matches', fn ($mq) => $mq->where('outcome', 'win')->where('state', 'complete'), '>=', 4))
            ->when($state === 'finish', fn ($q) => $q
                ->where('state', 'complete')
                ->whereHas('matches', fn ($mq) => $mq->where('outcome', 'win')->where('state', 'complete'), '<', 4))
            ->when($state === 'bricks', fn ($q) => $q->where('state', 'dropped'))
            ->when($deckId, fn ($q, $id) => $q->whereHas('deckVersion', fn ($dq) => $dq->where('deck_id', $id)))
            ->when($search, fn ($q, $term) => $q->whereHas('matches', function ($mq) use ($term) {
                $mq->where('state', 'complete')
                    ->where(function ($w) use ($term) {
                        $w->whereExists(function ($sub) use ($term) {
                            $sub->select(DB::raw(1))
                                ->from('match_archetypes as ma')
                                ->join('archetypes as a', 'a.id', '=', 'ma.archetype_id')
                                ->whereColumn('ma.mtgo_match_id', 'matches.id')
                                ->where('a.name', 'like', '%'.$term.'%');
                        })
                            ->orWhereExists(function ($sub) use ($term) {
                                $sub->select(DB::raw(1))
                                    ->from('game_player as gp')
                                    ->join('games as g', 'g.id', '=', 'gp.game_id')
                                    ->join('players as p', 'p.id', '=', 'gp.player_id')
                                    ->whereColumn('g.match_id', 'matches.id')
                                    ->where('gp.is_local', false)
                                    ->where('p.username', 'like', '%'.$term.'%');
                            });
                    });
            }));
    }
}
