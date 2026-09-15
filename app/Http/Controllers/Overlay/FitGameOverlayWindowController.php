<?php

namespace App\Http\Controllers\Overlay;

use App\Actions\Overlay\FitGameOverlayWindow;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FitGameOverlayWindowController extends Controller
{
    /**
     * The overlay page calls this with a plain fetch, outside the Inertia
     * router: an Inertia visit to this URL would cancel the page's in-flight
     * async requests (Inertia 3 cancels current-page async requests on any
     * cross-URL visit), which killed the deferred `archetypes`/`drawOdds`
     * reload at boot. Resizing the window is a native command, not page
     * state, so there is nothing to redirect back to.
     */
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'fixed_height' => 'required|integer|min:0|max:2000',
        ]);

        FitGameOverlayWindow::run((int) $validated['fixed_height']);

        return response()->noContent();
    }
}
