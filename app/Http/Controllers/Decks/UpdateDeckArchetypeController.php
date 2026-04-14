<?php

namespace App\Http\Controllers\Decks;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateDeckArchetypeController extends Controller
{
    public function __invoke(Deck $deck, Request $request): RedirectResponse
    {
        $request->validate([
            'archetype_id' => 'nullable|exists:archetypes,id',
        ]);

        $deck->update(['archetype_id' => $request->input('archetype_id')]);

        return back();
    }
}
