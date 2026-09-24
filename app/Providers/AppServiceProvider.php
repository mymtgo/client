<?php

namespace App\Providers;

use App\Actions\Database\ConfigureNativephpConnection;
use App\Actions\RegisterDevice;
use App\Actions\Sidecar\StartSidecarSupervisor;
use App\Actions\Sync\Auth\EnsureAccessToken;
use App\Dashboard\WidgetRegistry;
use App\Dashboard\Widgets\ArchetypeStatsWidget;
use App\Dashboard\Widgets\DeckPerformanceWidget;
use App\Dashboard\Widgets\DeckStatsWidget;
use App\Dashboard\Widgets\FormatStatsWidget;
use App\Dashboard\Widgets\KpiStripWidget;
use App\Dashboard\Widgets\LastSessionWidget;
use App\Dashboard\Widgets\LeagueResultsWidget;
use App\Dashboard\Widgets\LimitedLeagueWidget;
use App\Dashboard\Widgets\LimitedPicksWidget;
use App\Dashboard\Widgets\MatchupSpreadWidget;
use App\Dashboard\Widgets\RecentMatchesWidget;
use App\Dashboard\Widgets\RollingFormWidget;
use App\Events\TrayOpenRequested;
use App\Exceptions\OfflineModeException;
use App\Exceptions\Sync\NotLinkedException;
use App\Facades\AppSettings;
use App\Listeners\Sync\HandleSyncAuthCallback;
use App\Listeners\Tray\HandleTrayClick;
use App\Managers\MtgoManager;
use App\Services\Sync\SyncTokens;
use App\Settings\AppSettings as ConcreteAppSettings;
use App\Settings\MigrateSettingsToJson;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Native\Desktop\Events\App\OpenedFromURL;
use Native\Desktop\Events\ChildProcess\ProcessExited;
use Native\Desktop\Events\ChildProcess\ProcessSpawned;
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

        $this->app->singleton(WidgetRegistry::class, fn () => new WidgetRegistry([
            new KpiStripWidget,
            new LeagueResultsWidget,
            new RollingFormWidget,
            new LastSessionWidget,
            new DeckPerformanceWidget,
            new MatchupSpreadWidget,
            new RecentMatchesWidget,
            new DeckStatsWidget,
            new ArchetypeStatsWidget,
            new LimitedLeagueWidget,
            new LimitedPicksWidget,
            new FormatStatsWidget,
        ]));
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
            TrayOpenRequested::class,
            HandleTrayClick::class,
        );

        Event::listen(
            OpenedFromURL::class,
            HandleSyncAuthCallback::class,
        );

        Event::listen(ProcessExited::class, function ($event) {
            if ($event->alias === StartSidecarSupervisor::ALIAS) {
                StartSidecarSupervisor::handleExit($event->code);
            }
        });

        Event::listen(ProcessSpawned::class, function ($event) {
            if ($event->alias === StartSidecarSupervisor::ALIAS) {
                StartSidecarSupervisor::handleSpawn();
            }
        });

        if (! Storage::disk()->exists('settings.json')) {
            (new MigrateSettingsToJson)->run();
        }

        if (! config('mymtgo_api.verify_ssl')) {
            Http::globalOptions([
                'verify' => false,
            ]);
        }

        // One gate, two credentials. A linked client speaks for its user
        // and sends the Passport bearer; nothing else, so the device key is
        // never minted or rotated for it. An unlinked client sends the
        // device key as before. Offline, the stored token is used as is:
        // a refresh is a network round trip this macro must not make.
        Http::macro('mymtgoReference', function () {
            $tokens = app(SyncTokens::class);

            if ($tokens->linked()) {
                try {
                    $bearer = AppSettings::isOffline()
                        ? $tokens->accessToken()
                        : app(EnsureAccessToken::class)->run();

                    if ($bearer !== null) {
                        return Http::withToken($bearer)->baseUrl(config('mymtgo_api.url'));
                    }
                } catch (NotLinkedException) {
                    // Unlinked between the check and the read: device mode below.
                }
            }

            RegisterDevice::ensureFresh();

            return Http::withHeaders([
                'X-Device-Id' => AppSettings::deviceId(),
                'X-Api-Key' => RegisterDevice::retrieveKey(),
            ])->baseUrl(config('mymtgo_api.url'));
        });

        Http::macro('mymtgoApi', function () {
            if (AppSettings::isOffline()) {
                throw new OfflineModeException;
            }

            return Http::mymtgoReference();
        });

        // A bare base URL for the token endpoints and the sync API, which
        // attach their own bearer. They must not ride mymtgoReference: that
        // macro asks EnsureAccessToken for a token, and a refresh routed
        // back through it would recurse. This macro still honours offline
        // mode.
        Http::macro('mymtgoSync', function () {
            if (AppSettings::isOffline()) {
                throw new OfflineModeException;
            }

            return Http::baseUrl(config('mymtgo_api.url'));
        });

        Carbon::macro('toLocal', function () {
            /** @var Carbon $this */
            return $this->copy()->setTimezone(AppSettings::systemTimezone());
        });
    }

    /**
     * Augment NativePHP's dynamic SQLite connection with this app's settings.
     *
     * Deferred to booted() because NativePHP writes the connection during its
     * own provider boot; anything earlier is simply overwritten.
     */
    private function configureNativephpDatabase(): void
    {
        $this->app->booted(fn () => ConfigureNativephpConnection::run());
    }
}
