<?php

namespace App\Providers;

use App\Actions\Auth\RefreshAccessToken;
use App\Actions\Device\ResolveDeviceId;
use App\Actions\RegisterDevice;
use App\Facades\AppSettings;
use App\Listeners\Auth\HandleAuthCallback;
use App\Listeners\Tray\HandleTrayClick;
use App\Managers\MtgoManager;
use App\Settings\AppSettings as ConcreteAppSettings;
use App\Settings\MigrateSettingsToJson;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Native\Desktop\Events\App\OpenedFromURL;
use Native\Desktop\Events\MenuBar\MenuBarClicked;

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

        $this->app->singleton(ConcreteAppSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureNativephpDatabase();

        Event::listen(
            MenuBarClicked::class,
            HandleTrayClick::class,
        );

        Event::listen(
            OpenedFromURL::class,
            HandleAuthCallback::class,
        );

        if (! Storage::disk()->exists('settings.json')) {
            (new MigrateSettingsToJson)->run();
        }

        if (! config('mymtgo_api.verify_ssl')) {
            Http::globalOptions([
                'verify' => false,
            ]);
        }

        Http::macro('mymtgoApi', fn () => Http::withHeaders([
            'X-Device-Id' => AppSettings::deviceId(),
            'X-Api-Key' => RegisterDevice::retrieveKey(),
        ])->baseUrl(config('mymtgo_api.url')));

        // v1 Bearer client: OAuth access token + silent refresh-and-retry on
        // a 401 (revoked/expired access token). A failed refresh clears the
        // session and returns the 401 unchanged — the caller re-auths.
        Http::macro('mymtgoAuthed', fn () => Http::baseUrl(config('mymtgo_api.url'))
            ->acceptJson()
            ->withHeader('X-Device-Id', app(ResolveDeviceId::class)->run())
            ->withToken(AppSettings::oauthTokens()?->accessToken ?? '')
            ->retry(2, 0, function ($exception, $request) {
                if (! $exception instanceof RequestException || $exception->response->status() !== 401) {
                    return false;
                }

                if (! app(RefreshAccessToken::class)->run()) {
                    return false;
                }

                $request->withToken(AppSettings::oauthTokens()?->accessToken ?? '');

                return true;
            }, throw: false));

        Carbon::macro('toLocal', function () {
            /** @var Carbon $this */
            return $this->copy()->setTimezone(AppSettings::systemTimezone());
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
