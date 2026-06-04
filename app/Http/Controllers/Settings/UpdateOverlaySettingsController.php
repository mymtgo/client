<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Decks\CloseDeckPopoutWindow;
use App\Actions\Decks\OpenDeckPopoutWindow;
use App\Actions\Leagues\CloseOpponentScoutWindow;
use App\Actions\Leagues\CloseOverlayWindow;
use App\Actions\Leagues\OpenOpponentScoutWindow;
use App\Actions\Leagues\OpenOverlayWindow;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
            AppSettings::setShowLeagueWindow($validated['league_window']);

            if ($validated['league_window']) {
                OpenOverlayWindow::run();
            } else {
                CloseOverlayWindow::run();
            }
        }

        if (isset($validated['opponent_window'])) {
            AppSettings::setShowOpponentWindow($validated['opponent_window']);

            if ($validated['opponent_window']) {
                OpenOpponentScoutWindow::run();
            } else {
                CloseOpponentScoutWindow::run();
            }
        }

        if (isset($validated['deck_window'])) {
            AppSettings::setShowDeckWindow($validated['deck_window']);

            if ($validated['deck_window']) {
                OpenDeckPopoutWindow::run();
            } else {
                CloseDeckPopoutWindow::run();
            }
        }

        return back();
    }
}
