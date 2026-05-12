<?php

namespace App\Http\Controllers\Archetypes\Variants;

use App\Actions\Archetypes\RemoveArchetypeVariant;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use Illuminate\Http\RedirectResponse;

class DestroyController extends Controller
{
    public function __invoke(Archetype $archetype, ArchetypeDeck $deck): RedirectResponse
    {
        if ($archetype->is_fallback) {
            abort(403, 'Fallback archetypes cannot be edited.');
        }

        if ($archetype->decks()->count() === 1) {
            abort(422, 'Cannot delete the last variant.');
        }

        RemoveArchetypeVariant::run($archetype, $deck);

        return to_route('archetypes.edit', $archetype);
    }
}
