<?php

namespace App\Providers;

use App\Actions\RegisterDevice;
use App\Managers\MtgoManager;
use App\Models\LogCursor;
use App\Observers\LogCursorObserver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Native\Desktop\Facades\Settings;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('mtgo', function ($app) {
            return new MtgoManager;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureNativephpDatabase();

        LogCursor::observe(LogCursorObserver::class);

        if (! config('mymtgo_api.verify_ssl')) {
            Http::globalOptions([
                'verify' => false,
            ]);
        }

        Http::macro('mymtgoApi', fn () => Http::withHeaders([
            'X-Device-Id' => Settings::get('device_id'),
            'X-Api-Key' => RegisterDevice::retrieveKey(),
        ])->baseUrl(config('mymtgo_api.url')));

        Carbon::macro('toLocal', function () {
            /** @var Carbon $this */
            return $this->copy()->setTimezone(Settings::get('system_tz', 'UTC'));
        });
    }

    /**
     * Augment NativePHP's dynamic SQLite connection with proper settings.
     *
     * NativePHP creates the 'nativephp' connection at runtime without busy_timeout,
     * journal_mode, or synchronous config keys. Without these, Laravel's SQLite
     * connector defaults to 0ms busy_timeout — causing "database is locked" errors
     * when 5+ queue workers and the pipeline contend for writes.
     */
    private function configureNativephpDatabase(): void
    {
        $this->app->booted(function () {
            if (! config('database.connections.nativephp')) {
                return;
            }

            config([
                'database.connections.nativephp.busy_timeout' => 30000,
                'database.connections.nativephp.journal_mode' => 'WAL',
                'database.connections.nativephp.synchronous' => 'NORMAL',
            ]);

            // The connection may already be resolved by NativePHP's boot.
            // Override its 5000ms PRAGMA with our 30000ms value.
            try {
                DB::connection('nativephp')->statement('PRAGMA busy_timeout=30000;');
            } catch (\Throwable) {
                // Connection not yet available — config keys will apply on creation.
            }
        });
    }
}
