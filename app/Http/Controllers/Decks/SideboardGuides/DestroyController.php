<?php

namespace App\Http\Controllers\Decks\SideboardGuides;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckArchetypeNote;
use App\Models\SideboardGuide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class DestroyController extends Controller
{
    /**
     * Deleting a guide removes the whole matchup record, notes included: the
     * notes are keyed on the same (deck, archetype) pair and would otherwise
     * silently recreate an empty guide the next time the overlay saw them.
     */
    public function __invoke(Deck $deck, SideboardGuide $sideboardGuide): RedirectResponse
    {
        DB::transaction(function () use ($deck, $sideboardGuide): void {
            DeckArchetypeNote::query()
                ->where('deck_id', $deck->id)
                ->where('archetype_id', $sideboardGuide->archetype_id)
                ->delete();

            $sideboardGuide->delete();
        });

        return redirect()->route('decks.sideboard-guides.index', $deck);
    }
}
