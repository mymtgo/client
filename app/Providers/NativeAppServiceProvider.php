<?php

namespace App\Providers;

use App\Actions\Decks\OpenMostRecentDeckPopout;
use App\Actions\Leagues\OpenOpponentScoutWindow;
use App\Actions\Leagues\OpenOverlayWindow;
use App\Actions\Updates\RunAppUpdates;
use App\Facades\Mtgo;
use App\Models\AppSetting;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\Settings;
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
        RunAppUpdates::run();

        $this->configureTimezone();

        if (app()->isProduction()) {
            Menu::create();
        }

        Window::open()->width(1600)
            ->height(900)
            ->minHeight(800)
            ->minWidth(1200)
            ->movable()
            ->title('mymtgo')
            ->hideMenu()
            ->trafficLightsHidden();

        Mtgo::runInitialSetup();
        Mtgo::retryUnsubmittedMatches();

        if (Settings::get('league_window')) {
            OpenOverlayWindow::run();
        }

        if (Settings::get('opponent_window')) {
            OpenOpponentScoutWindow::run();
        }

        if (Settings::get('deck_window')) {
            OpenMostRecentDeckPopout::run();
        }
    }

    private function configureTimezone(): void
    {
        $settings = AppSetting::resolve();

        // Prefer the user's explicit choice, fall back to NativePHP detection
        $timezone = $settings->timezone
            ?? Settings::get('timezone')
            ?: System::timezone();

        if ($timezone) {
            $settings->updateQuietly(['timezone' => $timezone]);

            date_default_timezone_set($timezone);
            config(['app.timezone' => $timezone]);
        }
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
            'memory_limit' => '512M',
        ];
    }
}
