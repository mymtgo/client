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

        // Check the match has an opponent (via games or existing archetype row)
        $hasOpponent = $match->games()->whereNotNull('opp_instance')->exists()
            || $match->opponentArchetypes()->exists();

        if (! $hasOpponent) {
            return back();
        }

        if ($request->input('archetype_id')) {
            MatchArchetype::updateOrCreate(
                [
                    'mtgo_match_id' => $match->id,
                    'is_opponent' => true,
                ],
                [
                    'archetype_id' => $request->input('archetype_id'),
                    'confidence' => 1.0,
                ]
            );
        } else {
            MatchArchetype::where('mtgo_match_id', $match->id)
                ->where('is_opponent', true)
                ->delete();
        }

        return back();
    }
}
