<?php

namespace App\Actions\Overlay;

use App\Facades\AppSettings;
use Native\Desktop\Facades\Window as WindowFacade;
use Native\Desktop\Windows\Window;

class OpenGameOverlayWindow
{
    public const ID = 'game-overlay';

    public const DEFAULT_WIDTH = 320;

    public static function run(): void
    {
        if (self::find()) {
            return;
        }

        WindowFacade::open(self::ID)
            ->url(self::overlayUrl())
            ->width(self::DEFAULT_WIDTH)
            ->height(ComputeGameOverlayHeight::fromSettings())
            ->minWidth(300)
            ->maxWidth(400)
            // Low enough for the collapsed strip, which is shorter than any
            // expanded layout when the opponent header is switched off.
            ->minHeight(ComputeGameOverlayHeight::COLLAPSED_MIN_HEIGHT)
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

    public static function find(): ?Window
    {
        return collect(WindowFacade::all())->first(fn (Window $window) => $window->getId() === self::ID);
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
