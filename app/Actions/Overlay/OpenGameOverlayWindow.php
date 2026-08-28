<?php

namespace App\Actions\Overlay;

use App\Facades\AppSettings;
use Native\Desktop\Facades\Window;

class OpenGameOverlayWindow
{
    public const ID = 'game-overlay';

    public const DEFAULT_WIDTH = 320;

    public static function run(): void
    {
        $alreadyOpen = collect(Window::all())->contains(fn ($w) => $w->getId() === self::ID);

        if ($alreadyOpen) {
            return;
        }

        Window::open(self::ID)
            ->url(self::overlayUrl())
            ->width(self::DEFAULT_WIDTH)
            ->height(ComputeGameOverlayHeight::fromSettings())
            ->minWidth(300)
            ->maxWidth(400)
            ->minHeight(ComputeGameOverlayHeight::MIN_HEIGHT)
            ->rememberState()
            ->alwaysOnTop(true, 'screen-saver')
            ->frameless()
            ->resizable()
            ->movable()
            ->maximizable(false)
            ->fullscreenable(false)
            ->hideMenu()
            ->showDevTools(false)
            ->title('Game overlay');
    }

    /**
     * Absolute overlay URL that works from any process. route() alone is only
     * correct inside an HTTP request — in a queue worker or the watch daemon
     * it builds from APP_URL, which points at nothing (or, on dev machines,
     * at Herd), giving a blank window. Use the server URL captured at boot.
     */
    private static function overlayUrl(): string
    {
        $base = AppSettings::appServerUrl();

        if ($base === null) {
            return route('overlay.game');
        }

        return $base.route('overlay.game', absolute: false);
    }
}
