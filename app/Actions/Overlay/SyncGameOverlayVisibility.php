<?php

namespace App\Actions\Overlay;

use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\MtgoMatch;

class SyncGameOverlayVisibility
{
    /**
     * Reconcile the game overlay window against current match state:
     * open while a live match is in progress (and the setting is on),
     * closed otherwise. Idempotent — safe to call on every pipeline
     * tick or re-projection; historical replays settle on a terminal
     * state so the overlay never flashes for old matches.
     */
    public static function run(): void
    {
        $shouldShow = AppSettings::showGameOverlay()
            && MtgoMatch::query()
                ->where('state', MatchState::InProgress)
                ->whereNull('failed_at')
                ->exists();

        if ($shouldShow) {
            OpenGameOverlayWindow::run();
        } else {
            CloseGameOverlayWindow::run();
        }
    }
}
