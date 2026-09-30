<?php

namespace App\Actions\Tray;

use App\Actions\AutoUpdate\ResolveUpdateStatus;
use Native\Desktop\Facades\MenuBar;

/**
 * Re-applies the badge icon and tooltip to the running tray after the update
 * state changes, so the tray shows an update without a restart.
 *
 * The context menu is deliberately left alone. The tray is created with
 * onlyShowContextMenu, and NativePHP's /menu-bar/context-menu calls
 * tray.setContextMenu(), which on Windows turns left-click into "pop menu"
 * (no more MenuBarClicked, so the window stops opening) while right-click
 * keeps popping the menu captured at creation. So the menu never carries
 * an update item; the badge and tooltip are the tray's update signal.
 */
class SyncTrayUpdateState
{
    public static function run(): void
    {
        if (PHP_OS_FAMILY === 'Linux') {
            return;
        }

        $readyVersion = self::readyVersion();
        $icon = CreateTrayMenuBar::iconPath(updateReady: $readyVersion !== null);

        try {
            if ($icon !== null) {
                MenuBar::icon($icon);
            }

            MenuBar::tooltip(CreateTrayMenuBar::tooltip($readyVersion));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function readyVersion(): ?string
    {
        $status = ResolveUpdateStatus::run();

        return $status['status'] === 'ready' ? $status['available'] : null;
    }
}
