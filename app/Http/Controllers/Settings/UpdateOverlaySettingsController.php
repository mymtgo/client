<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Decks\CloseDeckPopoutWindow;
use App\Actions\Decks\OpenMostRecentDeckPopout;
use App\Actions\Leagues\CloseOpponentScoutWindow;
use App\Actions\Leagues\CloseOverlayWindow;
use App\Actions\Leagues\OpenOpponentScoutWindow;
use App\Actions\Leagues\OpenOverlayWindow;
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

            if ($validated['league_window']) {
                OpenOverlayWindow::run();
            } else {
                CloseOverlayWindow::run();
            }
        }

        if (isset($validated['opponent_window'])) {
            Settings::set('opponent_window', $validated['opponent_window'] ? 1 : 0);

            if ($validated['opponent_window']) {
                OpenOpponentScoutWindow::run();
            } else {
                CloseOpponentScoutWindow::run();
            }
        }

        if (isset($validated['deck_window'])) {
            Settings::set('deck_window', $validated['deck_window'] ? 1 : 0);

            if ($validated['deck_window']) {
                OpenMostRecentDeckPopout::run();
            } else {
                CloseDeckPopoutWindow::run();
            }
        }

        return back();
    }
}
