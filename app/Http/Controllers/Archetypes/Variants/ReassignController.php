<?php

namespace App\Http\Controllers\Archetypes\Variants;

use App\Actions\Archetypes\ReassignArchetypeVariant;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReassignArchetypeVariantRequest;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use Illuminate\Http\RedirectResponse;

class ReassignController extends Controller
{
    public function __invoke(
        ReassignArchetypeVariantRequest $request,
        Archetype $archetype,
        ArchetypeDeck $deck,
    ): RedirectResponse {
        ReassignArchetypeVariant::run($deck, $request->target());

        return to_route('archetypes.show', $request->target())
            ->with('success', 'Variant reassigned.');
    }
}
