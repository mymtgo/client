<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\AddArchetypeVariant;
use App\Actions\Archetypes\UpdateArchetypeMeta;
use App\Exceptions\DuplicateVariantException;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateController extends Controller
{
    public function __invoke(Request $request, Archetype $archetype): RedirectResponse
    {
        if ($archetype->is_fallback) {
            abort(403, 'Fallback archetypes cannot be edited.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'format' => ['required', 'string'],
            'color_identity' => ['nullable', 'string'],
            'cards' => ['sometimes', 'array', 'min:1'],
            'cards.*.oracle_id' => ['nullable', 'string'],
            'cards.*.mtgo_id' => ['required_with:cards', 'integer'],
            'cards.*.quantity' => ['required_with:cards', 'integer', 'min:1'],
            'cards.*.sideboard' => ['required_with:cards', 'boolean'],
        ]);

        UpdateArchetypeMeta::run(
            archetype: $archetype,
            name: $validated['name'],
            format: $validated['format'],
            colorIdentity: $validated['color_identity'] ?? null,
        );

        if (! empty($validated['cards'])) {
            try {
                AddArchetypeVariant::run($archetype, $validated['cards']);
            } catch (DuplicateVariantException $e) {
                return back()->withErrors(['cards' => $e->getMessage()])->withInput();
            }
        }

        return to_route('archetypes.show', $archetype);
    }
}
