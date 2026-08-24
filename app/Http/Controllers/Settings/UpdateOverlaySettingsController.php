<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Leagues\CloseOverlayWindow;
use App\Actions\Leagues\OpenOverlayWindow;
use App\Actions\Overlay\SyncGameOverlayVisibility;
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
            'game_overlay' => 'sometimes|boolean',
            'overlay_show_opponent' => 'sometimes|boolean',
            'overlay_show_draw_odds' => 'sometimes|boolean',
            'overlay_show_sideboard' => 'sometimes|boolean',
        ]);

        if (isset($validated['league_window'])) {
            AppSettings::setShowLeagueWindow($validated['league_window']);

            if ($validated['league_window']) {
                OpenOverlayWindow::run();
            } else {
                CloseOverlayWindow::run();
            }
        }

        if (isset($validated['game_overlay'])) {
            AppSettings::setShowGameOverlay($validated['game_overlay']);

            // Overlay lifecycle is match-driven: enabling only opens the
            // window if a match is currently in progress.
            SyncGameOverlayVisibility::run();
        }

        /**
         * Section toggles are persisted only. The overlay polls its `sections`
         * prop, so an open window adopts the change on its next tick without a
         * reopen.
         */
        if (isset($validated['overlay_show_opponent'])) {
            AppSettings::setOverlayShowOpponent($validated['overlay_show_opponent']);
        }

        if (isset($validated['overlay_show_draw_odds'])) {
            AppSettings::setOverlayShowDrawOdds($validated['overlay_show_draw_odds']);
        }

        if (isset($validated['overlay_show_sideboard'])) {
            AppSettings::setOverlayShowSideboard($validated['overlay_show_sideboard']);
        }

        return back();
    }
}
