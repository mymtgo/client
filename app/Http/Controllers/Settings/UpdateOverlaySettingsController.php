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
            'overlay_enabled' => 'sometimes|boolean',
            'overlay_opponent_enabled' => 'sometimes|boolean',
            'deck_popout_enabled' => 'sometimes|boolean',
        ]);

        if (isset($validated['overlay_enabled'])) {
            Settings::set('overlay_enabled', $validated['overlay_enabled'] ? 1 : 0);
        }

        if (isset($validated['overlay_opponent_enabled'])) {
            Settings::set('overlay_opponent_enabled', $validated['overlay_opponent_enabled'] ? 1 : 0);
        }

        if (isset($validated['deck_popout_enabled'])) {
            Settings::set('deck_popout_enabled', $validated['deck_popout_enabled'] ? 1 : 0);
        }

        return back();
    }
}
