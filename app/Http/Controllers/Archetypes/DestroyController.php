<?php

namespace App\Http\Controllers\Archetypes;

use App\Http\Controllers\Controller;
use App\Models\Archetype;
use Illuminate\Http\RedirectResponse;

class DestroyController extends Controller
{
    public function __invoke(Archetype $archetype): RedirectResponse
    {
        if (! $archetype->manual) {
            abort(403, 'Only manual archetypes can be deleted.');
        }

        $archetype->delete();

        return to_route('archetypes.index');
    }
}
