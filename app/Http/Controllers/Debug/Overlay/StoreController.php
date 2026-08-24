<?php

namespace App\Http\Controllers\Debug\Overlay;

use App\Actions\Debug\CreateFakeOverlayMatch;
use App\Actions\Debug\TeardownFakeOverlayMatches;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Deck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'deck_id' => 'required|exists:decks,id',
            'archetype_id' => 'required|exists:archetypes,id',
            'opponent_name' => 'nullable|string|max:64',
        ]);

        // One fake match at a time keeps the overlay's live-match probe
        // deterministic.
        TeardownFakeOverlayMatches::run();

        CreateFakeOverlayMatch::run(
            Deck::query()->findOrFail($validated['deck_id']),
            Archetype::query()->findOrFail($validated['archetype_id']),
            $validated['opponent_name'] ?? null,
        );

        return back();
    }
}
