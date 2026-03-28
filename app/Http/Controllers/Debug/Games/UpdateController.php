<?php

namespace App\Http\Controllers\Debug\Games;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpdateController extends Controller
{
    public function __invoke(Request $request, Game $game): RedirectResponse
    {
        $allowed = ['match_id', 'mtgo_id', 'won', 'started_at', 'ended_at'];

        $field = collect($request->only($allowed))->keys()->first();

        if (! $field) {
            return back();
        }

        $rules = [
            'match_id' => ['required', 'integer', Rule::exists(MtgoMatch::class, 'id')],
            'mtgo_id' => 'nullable|string',
            'won' => 'required|boolean',
            'started_at' => 'nullable|date',
            'ended_at' => 'nullable|date',
        ];

        $request->validate([$field => $rules[$field]]);

        $value = $request->input($field);
        $value = $value === '' ? null : $value;

        $game->update([$field => $value]);

        return back();
    }
}
