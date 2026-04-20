<?php

namespace App\Http\Controllers\Decks;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\Settings;

class ToggleGroupingController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'grouped' => 'required|boolean',
        ]);

        Settings::set('decks_grouped_by_archetype', $request->boolean('grouped') ? 1 : 0);

        return back();
    }
}
