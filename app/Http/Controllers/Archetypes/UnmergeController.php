<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\UnmergeArchetype;
use App\Models\Archetype;
use Illuminate\Http\RedirectResponse;

class UnmergeController
{
    public function __invoke(Archetype $archetype): RedirectResponse
    {
        UnmergeArchetype::run($archetype);

        return redirect()
            ->route('archetypes.show', ['archetype' => $archetype->id])
            ->with('success', 'Archetype unmerged.');
    }
}
