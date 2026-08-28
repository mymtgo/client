<?php

namespace App\Http\Controllers\Overlay;

use App\Actions\Overlay\FitGameOverlayWindow;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FitGameOverlayWindowController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'fixed_height' => 'required|integer|min:0|max:2000',
        ]);

        FitGameOverlayWindow::run((int) $validated['fixed_height']);

        return back();
    }
}
