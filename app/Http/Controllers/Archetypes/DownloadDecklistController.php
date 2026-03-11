<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\DownloadArchetypeDecklist;
use App\Models\Archetype;
use Illuminate\Http\RedirectResponse;

class DownloadDecklistController
{
    public function __invoke(Archetype $archetype): RedirectResponse
    {
        try {
            DownloadArchetypeDecklist::run($archetype);
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('archetypes.show', $archetype);
    }
}
