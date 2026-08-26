<?php

namespace App\Providers;

use App\Actions\Leagues\OpenOverlayWindow;
use App\Actions\Overlay\SyncDraftNotesWindowVisibility;
use App\Actions\Overlay\SyncGameOverlayVisibility;
use App\Actions\Tray\CreateTrayMenuBar;
use App\Actions\Updates\RunAppUpdates;
use App\Facades\AppSettings;
use App\Facades\Mtgo;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\App as NativeApp;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\System;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        $detected = System::timezone();
        AppSettings::setSystemTimezone($detected ?? AppSettings::systemTimezone());

        // Capture the live server URL for background processes: boot runs in
        // an HTTP request so url('/') is the real 127.0.0.1:<port> address,
        // which queue workers can't otherwise resolve (their APP_URL points
        // nowhere). Windows opened from the pipeline build URLs from this.
        AppSettings::setAppServerUrl(url('/'));

        RunAppUpdates::run();

        if (PHP_OS_FAMILY !== 'Linux') {
            NativeApp::openAtLogin(AppSettings::autostartEnabled());
        }

        CreateTrayMenuBar::run();

        if (app()->isProduction()) {
            Menu::create();
        }

        Window::open()->width(1600)
            ->height(900)
            ->minHeight(800)
            ->minWidth(1200)
            ->movable()
            ->hideOnClose()
            ->title('mymtgo');

        Mtgo::runInitialSetup();
        Mtgo::retryUnsubmittedMatches();

        if (AppSettings::showLeagueWindow()) {
            OpenOverlayWindow::run();
        }

        // Match-driven: opens only if a match is already in progress
        // (e.g. app restarted mid-match). Otherwise stays closed until
        // the pipeline advances a match to InProgress.
        SyncGameOverlayVisibility::run();

        // Draft-driven: reopens only if a draft is mid-pick at launch
        // (app restarted during a draft). Otherwise stays closed until
        // the pipeline sees a PendingPick. Forced: the memo the tick-driven
        // sync keeps is empty here, and a window remembered from the last
        // session may already be on screen.
        SyncDraftNotesWindowVisibility::run(force: true);
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
            'memory_limit' => '2056M',
        ];
    }
}
