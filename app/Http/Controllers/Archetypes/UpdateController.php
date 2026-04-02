<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\UpdateArchetypeDecklist;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateController extends Controller
{
    public function __invoke(Request $request, Archetype $archetype): RedirectResponse
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
        ]);

        UpdateArchetypeDecklist::run(
            archetype: $archetype,
            resolvedCards: $validated['cards'],
            name: $validated['name'],
            format: $validated['format'],
            colorIdentity: $validated['color_identity'] ?? null,
        );

        return to_route('archetypes.show', $archetype);
    }
}
