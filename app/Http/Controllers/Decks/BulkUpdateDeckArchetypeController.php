<?php

namespace App\Http\Controllers\Decks;

use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Deck;
use App\Support\MtgoFormat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BulkUpdateDeckArchetypeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'deck_ids' => ['required', 'array', 'min:1'],
            'deck_ids.*' => ['integer', 'distinct', 'exists:decks,id'],
            'archetype_id' => ['nullable', 'integer', 'exists:archetypes,id'],
        ]);

        // forActiveAccount() includes trashed rows on purpose: a deck deleted
        // on MTGO still has a match history worth classifying, so archived
        // decks are reassigned along with live ones.
        $decks = Deck::forActiveAccount()->whereIn('id', $validated['deck_ids']);

        $archetypeId = $validated['archetype_id'] ?? null;

        if ($archetypeId !== null) {
            $this->guardFormats($decks->pluck('format'), Archetype::findOrFail($archetypeId));
        }

        $decks->update(['archetype_id' => $archetypeId]);

        return back();
    }

    /**
     * Same-named archetypes exist once per format, so an assignment must keep
     * a deck inside its own format. Fallback archetypes (Homebrew, Rogue)
     * belong to every format. Clearing to unclassified skips this entirely.
     *
     * @param  Collection<int, string>  $deckFormats
     */
    private function guardFormats($deckFormats, Archetype $archetype): void
    {
        $formats = $deckFormats->map(fn (string $format) => MtgoFormat::key($format))->unique();

        if ($formats->count() > 1) {
            throw ValidationException::withMessages([
                'deck_ids' => 'Selected decks must all be the same format.',
            ]);
        }

        if (! $archetype->is_fallback && $archetype->format !== null && $archetype->format !== $formats->first()) {
            throw ValidationException::withMessages([
                'archetype_id' => 'That archetype belongs to a different format.',
            ]);
        }
    }
}
