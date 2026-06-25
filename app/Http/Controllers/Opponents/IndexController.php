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
        $activeAccountId = Account::currentId();
        $search = $request->input('search');
        $sort = $request->input('sort', 'most_played');
        $format = $request->input('format');

        $query = DB::table('opponents as o')
            ->join('matches as m', 'm.opponent_id', '=', 'o.id')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->join('decks as d', 'd.id', '=', 'dv.deck_id')
            ->where('m.state', 'complete')
            ->when($activeAccountId, fn ($q, $id) => $q->where('d.account_id', $id))
            ->when($format, fn ($q, $f) => $q->where('m.format', $f))
            ->when($search, fn ($q, $s) => $q->where('o.username', 'like', "%{$s}%"))
            ->groupBy('o.id', 'o.username')
            ->selectRaw("
                o.id as opponent_id,
                o.username,
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
        $opponentIds = collect($opponents->items())->pluck('opponent_id');
        $archetypesByOpponent = collect();
        $formatsByOpponent = collect();

        if ($opponentIds->isNotEmpty()) {
            $archetypesByOpponent = DB::table('match_archetypes as ma')
                ->join('archetypes as a', 'a.id', '=', 'ma.archetype_id')
                ->join('matches as m', 'm.id', '=', 'ma.mtgo_match_id')
                ->whereIn('m.opponent_id', $opponentIds)
                ->where('ma.is_opponent', true)
                ->select('m.opponent_id', 'a.name', 'a.color_identity')
                ->distinct()
                ->get()
                ->groupBy('opponent_id');

            $formatsByOpponent = DB::table('matches as m')
                ->whereIn('m.opponent_id', $opponentIds)
                ->where('m.state', 'complete')
                ->selectRaw('DISTINCT m.opponent_id, m.format')
                ->get()
                ->groupBy('opponent_id');
        }

        // Transform paginated results
        $opponents->through(function ($row) use ($archetypesByOpponent, $formatsByOpponent) {
            $archetypes = ($archetypesByOpponent[$row->opponent_id] ?? collect())
                ->map(fn ($a) => [
                    'name' => $a->name,
                    'colorIdentity' => $a->color_identity,
                ])->values()->all();

            $formats = ($formatsByOpponent[$row->opponent_id] ?? collect())
                ->pluck('format')->unique()
                ->map(fn ($f) => MtgoMatch::displayFormat($f))
                ->sort()->values()->all();

            return [
                'playerId' => (int) $row->opponent_id,
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
        $allFormats = DB::table('matches as m')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->join('decks as d', 'd.id', '=', 'dv.deck_id')
            ->whereNotNull('m.opponent_id')
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
