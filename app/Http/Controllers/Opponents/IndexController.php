<?php

namespace App\Http\Controllers\Opponents;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $activeAccountId = Account::active()->value('id');
        $search = $request->input('search');
        $sort = $request->input('sort', 'most_played');
        $format = $request->input('format');

        $query = DB::table('game_player as gp')
            ->join('players as p', 'p.id', '=', 'gp.player_id')
            ->join('games as g', 'g.id', '=', 'gp.game_id')
            ->join('matches as m', 'm.id', '=', 'g.match_id')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->join('decks as d', 'd.id', '=', 'dv.deck_id')
            ->where('gp.is_local', false)
            ->where('m.state', 'complete')
            ->when($activeAccountId, fn ($q, $id) => $q->where('d.account_id', $id))
            ->when($format, fn ($q, $f) => $q->where('m.format', $f))
            ->when($search, fn ($q, $s) => $q->where('p.username', 'like', "%{$s}%"))
            ->groupBy('p.id', 'p.username')
            ->selectRaw("
                p.id as player_id,
                p.username,
                COUNT(DISTINCT CASE WHEN m.outcome = 'win' THEN m.id END) as matches_won,
                COUNT(DISTINCT CASE WHEN m.outcome = 'loss' THEN m.id END) as matches_lost,
                COUNT(DISTINCT m.id) as total_matches,
                MAX(m.started_at) as last_played_at
            ");

        $query = match ($sort) {
            'winrate_asc' => $query->orderByRaw('CAST(matches_won AS REAL) / NULLIF(matches_won + matches_lost, 0) ASC'),
            'winrate_desc' => $query->orderByRaw('CAST(matches_won AS REAL) / NULLIF(matches_won + matches_lost, 0) DESC'),
            'most_recent' => $query->orderByDesc('last_played_at'),
            default => $query->orderByDesc('total_matches'),
        };

        $opponents = $query->paginate(25)->withQueryString();

        // Batch load archetypes for the current page only
        $playerIds = collect($opponents->items())->pluck('player_id');
        $archetypesByPlayer = collect();
        $formatsByPlayer = collect();

        if ($playerIds->isNotEmpty()) {
            $archetypesByPlayer = DB::table('match_archetypes as ma')
                ->join('archetypes as a', 'a.id', '=', 'ma.archetype_id')
                ->whereIn('ma.player_id', $playerIds)
                ->select('ma.player_id', 'a.name', 'a.color_identity')
                ->distinct()
                ->get()
                ->groupBy('player_id');

            $formatsByPlayer = DB::table('game_player as gp')
                ->join('games as g', 'g.id', '=', 'gp.game_id')
                ->join('matches as m', 'm.id', '=', 'g.match_id')
                ->where('gp.is_local', false)
                ->where('m.state', 'complete')
                ->whereIn('gp.player_id', $playerIds)
                ->selectRaw('DISTINCT gp.player_id, m.format')
                ->get()
                ->groupBy('player_id');
        }

        // Transform paginated results
        $opponents->through(function ($row) use ($archetypesByPlayer, $formatsByPlayer) {
            $archetypes = ($archetypesByPlayer[$row->player_id] ?? collect())
                ->map(fn ($a) => [
                    'name' => $a->name,
                    'colorIdentity' => $a->color_identity,
                ])->values()->all();

            $formats = ($formatsByPlayer[$row->player_id] ?? collect())
                ->pluck('format')->unique()
                ->map(fn ($f) => MtgoMatch::displayFormat($f))
                ->sort()->values()->all();

            return [
                'playerId' => (int) $row->player_id,
                'username' => $row->username,
                'matchesWon' => (int) $row->matches_won,
                'matchesLost' => (int) $row->matches_lost,
                'formats' => $formats,
                'archetypes' => $archetypes,
                'lastPlayedAt' => $row->last_played_at,
                'lastPlayedAtHuman' => $row->last_played_at
                    ? Carbon::parse($row->last_played_at)->toLocal()->diffForHumans()
                    : null,
            ];
        });

        // Format options for filter
        $allFormats = DB::table('game_player as gp')
            ->join('games as g', 'g.id', '=', 'gp.game_id')
            ->join('matches as m', 'm.id', '=', 'g.match_id')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->join('decks as d', 'd.id', '=', 'dv.deck_id')
            ->where('gp.is_local', false)
            ->where('m.state', 'complete')
            ->when($activeAccountId, fn ($q, $id) => $q->where('d.account_id', $id))
            ->distinct()
            ->pluck('m.format')
            ->sort()
            ->values()
            ->all();

        return Inertia::render('opponents/Index', [
            'opponents' => $opponents,
            'filters' => [
                'search' => $search ?? '',
                'sort' => $sort,
                'format' => $format ?? '',
            ],
            'allFormats' => $allFormats,
        ]);
    }
}
