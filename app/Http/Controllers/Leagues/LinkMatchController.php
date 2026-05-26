<?php

namespace App\Http\Controllers\Leagues;

use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;

class LinkMatchController extends Controller
{
    public function __invoke(Request $request, League $league)
    {
        $request->validate([
            'match_id' => ['required', 'integer', 'exists:matches,id'],
        ]);

        if (! $league->manual) {
            abort(422, 'League is not manual.');
        }

        if ($league->matches()->count() >= 5) {
            abort(422, 'League already has 5 matches.');
        }

        $match = MtgoMatch::query()->findOrFail($request->integer('match_id'));

        if ($match->league_id !== null) {
            abort(422, 'Match already belongs to a league.');
        }

        if ($match->state !== MatchState::Complete) {
            abort(422, 'Match is not complete.');
        }

        $leagueDeckId = DeckVersion::query()->whereKey($league->deck_version_id)->value('deck_id');
        $matchDeckId = DeckVersion::query()->whereKey($match->deck_version_id)->value('deck_id');

        if ($leagueDeckId === null || $matchDeckId === null || $leagueDeckId !== $matchDeckId) {
            abort(422, 'Match deck does not match league deck.');
        }

        $match->update(['league_id' => $league->id]);

        return redirect()->back();
    }
}
