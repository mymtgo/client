<?php

namespace App\Http\Controllers\Decks\SideboardGuides;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckArchetypeNote;
use App\Models\SideboardGuide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StoreNoteController extends Controller
{
    public function __invoke(Request $request, Deck $deck, SideboardGuide $sideboardGuide): RedirectResponse
    {
        $validated = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        DeckArchetypeNote::create([
            'deck_id' => $deck->id,
            'archetype_id' => $sideboardGuide->archetype_id,
            'body' => $validated['body'],
        ]);

        return back();
    }
}
