<?php

namespace App\Http\Controllers\Opponents;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        $activeAccountId = Account::active()->value('id');

        // All matches each opponent appeared in (distinct per player+match)
        $opponentMatches = DB::table('game_player as gp')
            ->join('players as p', 'p.id', '=', 'gp.player_id')
            ->join('games as g', 'g.id', '=', 'gp.game_id')
            ->join('matches as m', 'm.id', '=', 'g.match_id')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->join('decks as d', 'd.id', '=', 'dv.deck_id')
            ->where('gp.is_local', false)
            ->where('m.state', 'complete')
            ->when($activeAccountId, fn ($q, $id) => $q->where('d.account_id', $id))
            ->select('p.id as player_id', 'p.username', 'm.id as match_id', 'm.outcome', 'm.format', 'm.started_at')
            ->distinct()
            ->get();

        // Archetypes assigned to each player across all matches
        $archetypesByPlayer = DB::table('match_archetypes as ma')
            ->join('archetypes as a', 'a.id', '=', 'ma.archetype_id')
            ->select('ma.player_id', 'a.name', 'a.color_identity')
            ->distinct()
            ->get()
            ->groupBy('player_id');

        $opponents = $opponentMatches
            ->groupBy('player_id')
            ->map(function ($rows, $playerId) use ($archetypesByPlayer) {
                $matchesWon = $rows
                    ->filter(fn ($r) => $r->outcome === 'win')
                    ->pluck('match_id')->unique()->count();

                $matchesLost = $rows
                    ->filter(fn ($r) => $r->outcome === 'loss')
                    ->pluck('match_id')->unique()->count();

                $formats = $rows->pluck('format')->unique()
                    ->map(fn ($f) => MtgoMatch::displayFormat($f))
                    ->sort()->values()->all();

                $archetypes = ($archetypesByPlayer[$playerId] ?? collect())
                    ->map(fn ($a) => [
                        'name' => $a->name,
                        'colorIdentity' => $a->color_identity,
                    ])->values()->all();

                return [
                    'playerId' => (int) $playerId,
                    'username' => $rows->first()->username,
                    'matchesWon' => $matchesWon,
                    'matchesLost' => $matchesLost,
                    'formats' => $formats,
                    'archetypes' => $archetypes,
                    'lastPlayedAt' => $rows->max('started_at'),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('opponents/Index', [
            'opponents' => $opponents,
        ]);
    }
}
