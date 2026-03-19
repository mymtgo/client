<?php

namespace App\Http\Controllers\Debug\Matches;

use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpdateController extends Controller
{
    public function __invoke(Request $request, int $id): RedirectResponse
    {
        $match = MtgoMatch::findOrFail($id);

        $allowed = [
            'token', 'mtgo_id', 'league_id', 'deck_version_id',
            'format', 'match_type', 'state',
            'games_won', 'games_lost', 'started_at', 'ended_at', 'submitted_at',
        ];

        $field = collect($request->only($allowed))->keys()->first();

        if (! $field) {
            return back();
        }

        $rules = [
            'token' => 'nullable|string',
            'mtgo_id' => 'nullable|string',
            'league_id' => 'nullable|integer|exists:leagues,id',
            'deck_version_id' => 'nullable|integer|exists:deck_versions,id',
            'format' => 'nullable|string',
            'match_type' => 'nullable|string',
            'state' => ['nullable', Rule::enum(MatchState::class)],
            'games_won' => 'integer|min:0',
            'games_lost' => 'integer|min:0',
            'started_at' => 'nullable|date',
            'ended_at' => 'nullable|date',
            'submitted_at' => 'nullable|date',
        ];

        $request->validate([$field => $rules[$field]]);

        $match->update([$field => $request->input($field)]);

        return back();
    }
}
