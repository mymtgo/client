<?php

namespace App\Providers;

use App\Actions\Decks\OpenMostRecentDeckPopout;
use App\Actions\Leagues\OpenOpponentScoutWindow;
use App\Actions\Leagues\OpenOverlayWindow;
use App\Facades\Mtgo;
use Illuminate\Support\Facades\Event;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Events\ChildProcess\ProcessSpawned;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\Settings;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
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

        // Parse any existing log data — covers case where MTGO was opened
        // before the tracker, or data exists from a previous session.
        // IngestLog is cursor-based so this is safe to call on every boot.
        Mtgo::ingestLogs();

        // Start file watcher for real-time log ingestion
        if (Mtgo::canRun()) {
            ChildProcess::node('resources/js/file-watcher.js', alias: 'file-watcher', persistent: true);

            // Send watch paths after process spawns
            Event::listen(ProcessSpawned::class, function (ProcessSpawned $event) {
                if ($event->alias === 'file-watcher') {
                    ChildProcess::message(json_encode([
                        'type' => 'configure',
                        'paths' => [
                            Mtgo::getLogPath(),
                            Mtgo::getLogDataPath(),
                        ],
                    ]), 'file-watcher');
                }
            });
        }

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
