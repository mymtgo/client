<?php

namespace App\Http\Controllers\Matches;

use App\Actions\DetermineMatchArchetypes;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;

class DetectArchetypeController extends Controller
{
    public function __invoke(string $id)
    {
        $match = MtgoMatch::with('games.decks')->findOrFail($id);

        DetermineMatchArchetypes::run($match);

        return back();
    }
}
