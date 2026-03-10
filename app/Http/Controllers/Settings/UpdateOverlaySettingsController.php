<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\Settings;

class UpdateOverlaySettingsController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'league_window' => 'sometimes|boolean',
            'opponent_window' => 'sometimes|boolean',
            'deck_window' => 'sometimes|boolean',
        ]);

        if (isset($validated['league_window'])) {
            Settings::set('league_window', $validated['league_window'] ? 1 : 0);
        }

        if (isset($validated['opponent_window'])) {
            Settings::set('opponent_window', $validated['opponent_window'] ? 1 : 0);
        }

        if (isset($validated['deck_window'])) {
            Settings::set('deck_window', $validated['deck_window'] ? 1 : 0);
        }

        return back();
    }
}
