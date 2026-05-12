<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\MergeArchetype;
use App\Http\Requests\MergeArchetypeRequest;
use App\Models\Archetype;
use Illuminate\Http\RedirectResponse;

class MergeController
{
    public function __invoke(MergeArchetypeRequest $request, Archetype $archetype): RedirectResponse
    {
        MergeArchetype::run($archetype, $request->parent());

        return redirect()
            ->route('archetypes.show', ['archetype' => $archetype->id])
            ->with('success', 'Archetype merged.');
    }
}
