<?php

namespace App\Actions\Overlay;

use App\Facades\AppSettings;
use Native\Desktop\Facades\Window;

class OpenDraftNotesWindow
{
    public const WINDOW_ID = 'draft-notes';

    public static function run(): void
    {
        $alreadyOpen = collect(Window::all())->contains(fn ($w) => $w->getId() === self::WINDOW_ID);

        if ($alreadyOpen) {
            return;
        }

        Window::open(self::WINDOW_ID)
            ->url(self::windowUrl())
            ->width(320)
            ->height(220)
            ->minWidth(280)
            ->maxWidth(480)
            ->minHeight(180)
            ->rememberState()
            ->alwaysOnTop(true, 'screen-saver')
            ->frameless()
            ->resizable()
            ->movable()
            ->maximizable(false)
            ->fullscreenable(false)
            ->hideMenu()
            ->showDevTools(false)
            ->title('Draft notes');
    }

    /**
     * Absolute URL that works from any process. route() alone is only
     * correct inside an HTTP request; in the watch daemon or a queue worker
     * it builds from APP_URL, which points at nothing. Use the server URL
     * captured at boot, exactly as OpenGameOverlayWindow does.
     */
    private static function windowUrl(): string
    {
        $base = AppSettings::appServerUrl();

        if ($base === null) {
            return route('overlay.draft-notes');
        }

        return $base.route('overlay.draft-notes', absolute: false);
    }
}
