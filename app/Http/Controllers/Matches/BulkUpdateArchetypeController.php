<?php

namespace App\Http\Controllers\Matches;

use App\Http\Controllers\Controller;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BulkUpdateArchetypeController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'match_ids' => ['required', 'array', 'min:1'],
            'match_ids.*' => ['required', 'integer', 'exists:matches,id'],
            'archetype_id' => ['required', 'exists:archetypes,id'],
        ]);

        $matchIds = $request->input('match_ids');
        $matches = MtgoMatch::whereIn('id', $matchIds)
            ->with('opponentArchetypes')
            ->get();

        // Batch-load fallback opponent player IDs for matches without existing archetypes
        $matchesWithoutArchetypes = $matches->filter(fn ($m) => $m->opponentArchetypes->isEmpty());
        $fallbackOpponents = collect();

        if ($matchesWithoutArchetypes->isNotEmpty()) {
            $fallbackOpponents = DB::table('game_player as gp')
                ->join('games as g', 'g.id', '=', 'gp.game_id')
                ->whereIn('g.match_id', $matchesWithoutArchetypes->pluck('id'))
                ->where('gp.is_local', false)
                ->select('g.match_id', 'gp.player_id')
                ->distinct()
                ->get()
                ->groupBy('match_id');
        }

        foreach ($matches as $match) {
            $opponentPlayerIds = $match->opponentArchetypes->pluck('player_id');

            if ($opponentPlayerIds->isEmpty()) {
                $opponentPlayerIds = ($fallbackOpponents[$match->id] ?? collect())->pluck('player_id');
            }

            if ($opponentPlayerIds->isEmpty()) {
                continue;
            }

            MatchArchetype::updateOrCreate(
                [
                    'mtgo_match_id' => $match->id,
                    'player_id' => $opponentPlayerIds->first(),
                ],
                [
                    'archetype_id' => $request->input('archetype_id'),
                    'confidence' => 1.0,
                ]
            );
        }

        return back();
    }
}
