<?php

namespace App\Http\Controllers\Leagues;

use App\Http\Controllers\Controller;
use App\Models\League;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        $leagues = League::orderByDesc('started_at')->get();

        if ($leagues->isEmpty()) {
            return Inertia::render('leagues/Index', ['leagues' => []]);
        }

        $leagueIds = $leagues->pluck('id');

        // All non-deleted matches per league with deck info
        $matchRows = DB::table('matches as m')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->join('decks as d', 'd.id', '=', 'dv.deck_id')
            ->whereIn('m.league_id', $leagueIds)
            ->whereNull('m.deleted_at')
            ->select('m.id', 'm.league_id', 'm.games_won', 'm.games_lost', 'm.started_at', 'd.id as deck_id', 'd.name as deck_name')
            ->orderBy('m.started_at')
            ->get();

        $matchIds = $matchRows->pluck('id');

        // Opponent name + archetype per match (non-local players only)
        $opponentByMatch = DB::table('match_archetypes as ma')
            ->join('players as p', 'p.id', '=', 'ma.player_id')
            ->leftJoin('archetypes as a', 'a.id', '=', 'ma.archetype_id')
            ->whereIn('ma.mtgo_match_id', $matchIds)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('game_player as gp')
                    ->join('games as g', 'g.id', '=', 'gp.game_id')
                    ->whereRaw('g.match_id = ma.mtgo_match_id')
                    ->whereRaw('gp.player_id = ma.player_id')
                    ->where('gp.is_local', false);
            })
            ->select('ma.mtgo_match_id', 'p.username', 'a.name as archetype_name')
            ->get()
            ->keyBy('mtgo_match_id');

        $matchesByLeague = $matchRows->groupBy('league_id');

        $runs = $leagues->map(function (League $league) use ($matchesByLeague, $opponentByMatch) {
            $matches = $matchesByLeague[$league->id] ?? collect();

            // Use the most common deck across the run's matches
            $deck = $matches->groupBy('deck_id')
                ->map->count()
                ->sortDesc()
                ->keys()
                ->map(fn ($deckId) => $matches->firstWhere('deck_id', $deckId))
                ->map(fn ($row) => ['id' => $row->deck_id, 'name' => $row->deck_name])
                ->first();

            $matchData = $matches->map(function ($row) use ($opponentByMatch) {
                $opp = $opponentByMatch[$row->id] ?? null;
                $won = $row->games_won > $row->games_lost;

                return [
                    'id' => $row->id,
                    'result' => $won ? 'W' : 'L',
                    'opponentName' => $opp?->username,
                    'opponentArchetype' => $opp?->archetype_name,
                    'games' => "{$row->games_won}-{$row->games_lost}",
                    'startedAt' => $row->started_at,
                ];
            })->values()->all();

            $results = array_map(fn ($m) => $m['result'], $matchData);

            // Pad real leagues to 5 slots
            if (! $league->phantom) {
                while (count($results) < 5) {
                    $results[] = null;
                }
            }

            return [
                'id' => $league->id,
                'name' => $league->name,
                'format' => Str::title(strtolower(substr($league->format, 1))),
                'phantom' => (bool) $league->phantom,
                'startedAt' => $league->started_at,
                'deck' => $deck,
                'results' => $results,
                'matches' => $matchData,
            ];
        })->values()->all();

        return Inertia::render('leagues/Index', ['leagues' => $runs]);
    }
}
