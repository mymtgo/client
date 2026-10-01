<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Leagues\CloseOverlayWindow;
use App\Actions\Leagues\OpenOverlayWindow;
use App\Actions\Leagues\ResolveOverlayArt;
use App\Actions\Overlay\ClearPublishedOverlay;
use App\Actions\Overlay\SyncDraftNotesWindowVisibility;
use App\Actions\Overlay\SyncGameOverlayVisibility;
use App\Events\LeagueOverlayChanged;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Jobs\PublishOverlayStateJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Native\Desktop\Facades\Window;

class UpdateOverlaySettingsController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'league_window' => 'sometimes|boolean',
            'game_overlay' => 'sometimes|boolean',
            'draft_notes_window' => 'sometimes|boolean',
            'overlay_show_opponent' => 'sometimes|boolean',
            'overlay_show_draw_odds' => 'sometimes|boolean',
            'overlay_show_sideboard' => 'sometimes|boolean',
            'overlay_show_reveals' => 'sometimes|boolean',
            'overlay_artwork' => ['sometimes', Rule::in(ResolveOverlayArt::MODES)],
            'overlay_size' => ['sometimes', Rule::in(array_keys(OpenOverlayWindow::SIZES))],
            'overlay_publish' => 'sometimes|boolean',
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

        if (isset($validated['draft_notes_window'])) {
            AppSettings::setShowDraftNotesWindow($validated['draft_notes_window']);

            // Draft-driven, like the game overlay: enabling only opens the
            // window when a draft is live. Forced, because the desired state
            // did not change here, the setting behind it did.
            SyncDraftNotesWindowVisibility::run(force: true);
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

        if (isset($validated['overlay_show_reveals'])) {
            AppSettings::setOverlayShowReveals($validated['overlay_show_reveals']);
        }

        if (isset($validated['overlay_artwork'])) {
            AppSettings::setOverlayArtwork($validated['overlay_artwork']);
        }

        if (isset($validated['overlay_publish'])) {
            AppSettings::setOverlayPublish($validated['overlay_publish']);

            if ($validated['overlay_publish']) {
                PublishOverlayStateJob::dispatch();
            } else {
                ClearPublishedOverlay::run();
            }
        }

        if (isset($validated['overlay_size'])) {
            AppSettings::setOverlaySize($validated['overlay_size']);

            if (OpenOverlayWindow::find()) {
                [$width, $height] = OpenOverlayWindow::windowSize($validated['overlay_size']);
                Window::resize($width, $height, OpenOverlayWindow::ID);
            }
        }

        // The league overlay shows artwork and size; push instead of waiting for its poll.
        if (isset($validated['overlay_artwork']) || isset($validated['overlay_size'])) {
            LeagueOverlayChanged::dispatch();
        }

        return back();
    }
}
