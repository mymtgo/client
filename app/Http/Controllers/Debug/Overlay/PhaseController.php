<?php

namespace App\Http\Controllers\Debug\Overlay;

use App\Actions\Debug\AdvanceFakeOverlayPhase;
use App\Actions\Debug\CreateFakeOverlayMatch;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PhaseController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phase' => 'required|in:sideboarding,game2',
        ]);

        $fake = MtgoMatch::query()
            ->where('token', 'like', CreateFakeOverlayMatch::TOKEN_PREFIX.'%')
            ->latest('id')
            ->first();

        if ($fake) {
            AdvanceFakeOverlayPhase::run($fake, $validated['phase']);
        }

        return back();
    }
}
