<?php

namespace App\Http\Controllers\Debug\Leagues;

use App\Enums\LeagueState;
use App\Http\Controllers\Controller;
use App\Models\League;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpdateController extends Controller
{
    public function __invoke(Request $request, int $id): RedirectResponse
    {
        $league = League::withTrashed()->findOrFail($id);

        $allowed = [
            'token', 'event_id', 'name', 'format',
            'state', 'phantom', 'deck_change_detected', 'deck_version_id',
        ];

        $field = collect($request->only($allowed))->keys()->first();

        if (! $field) {
            return back();
        }

        $rules = [
            'token' => 'required|string',
            'event_id' => 'nullable|integer',
            'name' => 'required|string',
            'format' => 'required|string',
            'state' => ['required', Rule::enum(LeagueState::class)],
            'phantom' => 'boolean',
            'deck_change_detected' => 'boolean',
            'deck_version_id' => 'nullable|integer|exists:deck_versions,id',
        ];

        $request->validate([$field => $rules[$field]]);

        $value = $request->input($field);
        $value = $value === '' ? null : $value;

        $league->update([$field => $value]);

        return back();
    }
}
