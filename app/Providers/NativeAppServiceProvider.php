<?php

namespace App\Providers;

use App\Facades\Mtgo;
use Illuminate\Support\Facades\Log;
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
        Window::open()->width(1600)
            ->height(900)
            ->minHeight(800)
            ->minWidth(1200)
            ->movable()
            ->title('mymtgo')
            ->hideMenu()
            ->afterOpen(function () {
                Mtgo::runInitialSetup();
                Mtgo::retryUnsubmittedMatches();
            })
            ->trafficLightsHidden();
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
            'expose_php' => '0',
            'display_errors' => '0',
            'session.use_strict_mode' => '1',
            'session.cookie_httponly' => '1',
            'session.cookie_secure' => '1',
        ];
    }
}
