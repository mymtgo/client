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

        $matchIds = $request->input('match_ids');
        $matches = MtgoMatch::whereIn('id', $matchIds)->get();

        foreach ($matches as $match) {
            // Skip matches with no opponent game data (no games with opp_instance set)
            $hasOpponent = $match->games()->whereNotNull('opp_instance')->exists();

            if (! $hasOpponent && ! $match->opponentArchetypes()->exists()) {
                continue;
            }

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
        }

        return back();
    }
}
