<?php

namespace App\Providers;

use App\Actions\Decks\OpenDeckPopoutWindow;
use App\Actions\Leagues\OpenOpponentScoutWindow;
use App\Actions\Leagues\OpenOverlayWindow;
use App\Actions\Tray\CreateTrayMenuBar;
use App\Actions\Updates\RunAppUpdates;
use App\Facades\AppSettings;
use App\Facades\Mtgo;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\App as NativeApp;
use Native\Desktop\Facades\ChildProcess;
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

        // Resident ingestion watcher — the canonical hot path. persistent:true
        // restarts it on crash or self-recycle; RunPipelineJob backstops the gap.
        ChildProcess::artisan('mtgo:watch', 'ingest-watcher', persistent: true);

        Mtgo::retryUnsubmittedMatches();

        if (AppSettings::showLeagueWindow()) {
            OpenOverlayWindow::run();
        }

        if (AppSettings::showOpponentWindow()) {
            OpenOpponentScoutWindow::run();
        }

        if (AppSettings::showDeckWindow()) {
            OpenDeckPopoutWindow::run();
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
