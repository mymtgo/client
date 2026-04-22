<?php

namespace App\Http\Controllers\Decks;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ToggleGroupingController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'grouped' => 'required|boolean',
        ]);

        AppSettings::setDecksGroupedByArchetype($request->boolean('grouped'));

        return back();
    }
}
