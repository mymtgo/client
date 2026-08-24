<?php

namespace App\Providers;

use App\Actions\Leagues\OpenOverlayWindow;
use App\Actions\Overlay\OpenGameOverlayWindow;
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

        if (AppSettings::showGameOverlay()) {
            OpenGameOverlayWindow::run();
        }
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
