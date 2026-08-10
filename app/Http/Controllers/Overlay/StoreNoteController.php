<?php

namespace App\Http\Controllers\Overlay;

use App\Actions\Overlay\ResolveOverlayOpponent;
use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Models\DeckArchetypeNote;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StoreNoteController extends Controller
{
    /**
     * The deck and archetype are derived server-side from the live match, never
     * taken from the request: an overlay left open across matches would
     * otherwise file a note against whatever it last rendered.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $match = MtgoMatch::whereIn('state', [MatchState::Started, MatchState::InProgress])
            ->latest('started_at')
            ->first();

        $deck = $match?->deckVersion?->deck;
        $archetypeId = $match ? ResolveOverlayOpponent::run($match)?->archetypeId : null;

        if (! $deck || ! $archetypeId) {
            return back();
        }

        DeckArchetypeNote::create([
            'deck_id' => $deck->id,
            'archetype_id' => $archetypeId,
            'body' => $validated['body'],
        ]);

        return back();
    }
}
