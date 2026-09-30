<?php

namespace App\Http\Controllers\Overlay;

use App\Actions\Overlay\SetGameOverlayCollapsed;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CollapseGameOverlayWindowController extends Controller
{
    /**
     * Called with a plain fetch for the same reason as the fit endpoint: an
     * Inertia visit would cancel the overlay's in-flight deferred reloads.
     */
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'collapsed' => 'required|boolean',
            'fixed_height' => 'required|integer|min:0|max:2000',
            'bar_height' => 'required|integer|min:0|max:200',
        ]);

        SetGameOverlayCollapsed::run(
            (bool) $validated['collapsed'],
            (int) $validated['fixed_height'],
            (int) $validated['bar_height'],
        );

        return response()->noContent();
    }
}
