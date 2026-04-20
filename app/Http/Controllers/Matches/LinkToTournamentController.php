<?php

namespace App\Http\Controllers\Matches;

use App\Actions\Tournaments\ManuallyLinkMatchToTournament;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LinkToTournamentController
{
    public function store(MtgoMatch $match, Request $request)
    {
        $validated = $request->validate([
            'tournament_id' => [
                'required',
                'integer',
                Rule::exists('tournaments', 'id')->where('participated', true),
            ],
            'round' => ['required', 'integer', 'min:1'],
        ]);

        $tournament = Tournament::findOrFail($validated['tournament_id']);

        if ($tournament->max_rounds !== null && $validated['round'] > $tournament->max_rounds) {
            return back()->withErrors(['round' => 'Round must not exceed the tournament\'s max rounds.']);
        }

        ManuallyLinkMatchToTournament::link($match, $tournament, $validated['round']);

        return back();
    }

    public function destroy(MtgoMatch $match)
    {
        ManuallyLinkMatchToTournament::unlink($match);

        return back();
    }
}
