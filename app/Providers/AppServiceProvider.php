<?php

namespace App\Providers;

use App\Actions\RegisterDevice;
use App\Managers\MtgoManager;
use App\Models\LogCursor;
use App\Observers\LogCursorObserver;
use Carbon\Carbon;
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
}
