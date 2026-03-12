<?php

namespace App\Http\Controllers\Debug\DeckVersions;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpdateController extends Controller
{
    public function __invoke(Request $request, DeckVersion $deckVersion): RedirectResponse
    {
        $allowed = ['deck_id', 'signature', 'modified_at'];

        $field = collect($request->only($allowed))->keys()->first();

        if (! $field) {
            return back();
        }

        $rules = [
            'deck_id' => ['required', 'integer', Rule::exists(Deck::class, 'id')],
            'signature' => 'required|string',
            'modified_at' => 'required|date',
        ];

        $request->validate([$field => $rules[$field]]);

        $deckVersion->update([$field => $request->input($field)]);

        return back();
    }
}
