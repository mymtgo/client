<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\StoreManualArchetype;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'format' => ['required', 'string'],
            'color_identity' => ['nullable', 'string'],
            'cards' => ['required', 'array', 'min:1'],
            'cards.*.oracle_id' => ['nullable', 'string'],
            'cards.*.mtgo_id' => ['required', 'integer'],
            'cards.*.quantity' => ['required', 'integer', 'min:1'],
            'cards.*.sideboard' => ['required', 'boolean'],
            'source_match_id' => ['nullable', 'integer', 'exists:matches,id'],
            'incomplete' => ['boolean'],
        ]);

        $archetype = StoreManualArchetype::run(
            name: $validated['name'],
            format: $validated['format'],
            colorIdentity: $validated['color_identity'] ?? null,
            resolvedCards: $validated['cards'],
            sourceMatchId: $validated['source_match_id'] ?? null,
            incomplete: $validated['incomplete'] ?? false,
        );

        if (! empty($validated['source_match_id'])) {
            $deckId = MtgoMatch::query()
                ->where('id', $validated['source_match_id'])
                ->whereNotNull('deck_version_id')
                ->with('deckVersion:id,deck_id')
                ->first()?->deckVersion?->deck_id;

            if ($deckId) {
                return to_route('decks.matches', ['deck' => $deckId]);
            }
        }

        return to_route('archetypes.show', $archetype);
    }
}
