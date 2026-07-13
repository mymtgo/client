<?php

namespace App\Providers;

use App\Actions\Auth\CloseAuthWindowOpenMain;
use App\Actions\Auth\OpenAuthWindow;
use App\Actions\Auth\ResolveSession;
use App\Actions\Tray\CreateTrayMenuBar;
use App\Enums\SessionState;
use App\Facades\AppSettings;
use App\Facades\Mtgo;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\App as NativeApp;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\System;

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

        if (PHP_OS_FAMILY !== 'Linux') {
            NativeApp::openAtLogin(AppSettings::autostartEnabled());
        }

        CreateTrayMenuBar::run();

        if (app()->isProduction()) {
            Menu::create();
        }

        if (app(ResolveSession::class)->run() === SessionState::Authenticated) {
            app(CloseAuthWindowOpenMain::class)->run();
        } else {
            app(OpenAuthWindow::class)->run();
        }

        Mtgo::runInitialSetup();

        // Overlay windows (deck odds, league, opponent scout) return with the
        // v1 UI rebuild — the 0.x implementations live on the 0.x branch.
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
