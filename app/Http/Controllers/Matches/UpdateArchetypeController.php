<?php

namespace App\Http\Controllers\Matches;

use App\Http\Controllers\Controller;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;

class UpdateArchetypeController extends Controller
{
    public function __invoke(string $id, Request $request)
    {
        $request->validate([
            'archetype_id' => 'nullable|exists:archetypes,id',
        ]);

        $match = MtgoMatch::findOrFail($id);

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
            return back();
        }

        $opponentPlayerId = $opponentPlayerIds->first();

        if ($request->input('archetype_id')) {
            MatchArchetype::updateOrCreate(
                [
                    'mtgo_match_id' => $match->id,
                    'player_id' => $opponentPlayerId,
                ],
                [
                    'archetype_id' => $request->input('archetype_id'),
                    'confidence' => 1.0,
                ]
            );
        } else {
            MatchArchetype::where('mtgo_match_id', $match->id)
                ->where('player_id', $opponentPlayerId)
                ->delete();
        }

        return back();
    }
}
