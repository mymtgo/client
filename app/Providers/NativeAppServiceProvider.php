<?php

namespace App\Providers;

use App\Actions\AutoUpdate\ResolveUpdateStatus;
use App\Actions\Tray\CreateTrayMenuBar;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        // The farewell release (0.47.0): MyMTGO 1.0 replaces this app. Boot
        // keeps the updater, the tray and the main window, and nothing else:
        // no app updates, initial setup, match submission or overlay windows,
        // and no settings write, so the settings file and the database stay
        // as 0.46.0 left them for MyMTGO 1.0 to import. Launch at login is
        // left as the user set it.
        ResolveUpdateStatus::startSession();

        CreateTrayMenuBar::run();

        Window::open()->width(1600)
            ->height(900)
            ->minHeight(800)
            ->minWidth(1200)
            ->movable()
            ->hideOnClose()
            ->title('mymtgo');
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
