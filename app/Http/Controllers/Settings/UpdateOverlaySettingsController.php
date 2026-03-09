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
            'overlay_enabled' => 'required|boolean',
        ]);

        Settings::set('overlay_enabled', $validated['overlay_enabled'] ? 1 : 0);

        return back();
    }
}
