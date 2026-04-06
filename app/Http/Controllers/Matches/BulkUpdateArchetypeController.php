<?php

namespace App\Http\Controllers\Matches;

use App\Http\Controllers\Controller;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;

class BulkUpdateArchetypeController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'match_ids' => ['required', 'array', 'min:1'],
            'match_ids.*' => ['required', 'integer', 'exists:matches,id'],
            'archetype_id' => ['required', 'exists:archetypes,id'],
        ]);

        $matches = MtgoMatch::whereIn('id', $request->input('match_ids'))->get();

        foreach ($matches as $match) {
            $opponentPlayerIds = $match->opponentArchetypes()->pluck('player_id');

            if ($opponentPlayerIds->isEmpty()) {
                $opponentPlayerIds = \DB::table('game_player as gp')
                    ->join('games as g', 'g.id', '=', 'gp.game_id')
                    ->where('g.match_id', $match->id)
                    ->where('gp.is_local', false)
                    ->distinct()
                    ->pluck('gp.player_id');
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
