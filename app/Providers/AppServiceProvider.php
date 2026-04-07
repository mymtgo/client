<?php

namespace App\Providers;

use App\Actions\RegisterDevice;
use App\Managers\MtgoManager;
use App\Models\AppSetting;
use App\Models\LogCursor;
use App\Observers\LogCursorObserver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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
        $this->configureTimezone();

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
    }

    private function configureTimezone(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        $timezone = AppSetting::resolve()->timezone;

        if ($timezone) {
            date_default_timezone_set($timezone);
            config(['app.timezone' => $timezone]);
        }
    }
}
