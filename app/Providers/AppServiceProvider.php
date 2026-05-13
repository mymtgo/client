<?php

namespace App\Providers;

use App\Actions\Pipeline\ApplyLogEvents;
use App\Actions\Pipeline\Handlers\HandleGameManagementJson;
use App\Actions\Pipeline\Handlers\HandleGameStateUpdate;
use App\Actions\Pipeline\Handlers\HandleMatchStateChanged;
use App\Actions\Pipeline\MetaMessage\ApplyCardCast;
use App\Actions\Pipeline\MetaMessage\ApplyCardPlay;
use App\Actions\Pipeline\MetaMessage\ApplyDeckList;
use App\Actions\Pipeline\MetaMessage\ApplyDieRoll;
use App\Actions\Pipeline\MetaMessage\ApplyGameWinner;
use App\Actions\Pipeline\MetaMessage\ApplyJoined;
use App\Actions\Pipeline\MetaMessage\ApplyMulligan;
use App\Actions\Pipeline\MetaMessage\ApplyPlayChoice;
use App\Actions\Pipeline\MetaMessage\ApplyStartingHand;
use App\Actions\Pipeline\MetaMessage\ApplyTurnStart;
use App\Actions\RegisterDevice;
use App\Enums\MetaMessageKind;
use App\Facades\AppSettings;
use App\Managers\MtgoManager;
use App\Models\LogCursor;
use App\Observers\LogCursorObserver;
use App\Settings\AppSettings as ConcreteAppSettings;
use App\Settings\MigrateSettingsToJson;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

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

        LogCursor::observe(LogCursorObserver::class);

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

        Carbon::macro('toLocal', function () {
            /** @var Carbon $this */
            return $this->copy()->setTimezone(AppSettings::systemTimezone());
        });

        $this->registerPipelineHandlers();
    }

    /**
     * Map LogEvent.event_type values to handler classes for the ApplyLogEvents walker.
     * Empty until Phase 4/5 handlers ship; populated per the metamessage-pipeline plan.
     */
    private function registerPipelineHandlers(): void
    {
        ApplyLogEvents::$handlers = [
            'match_state_changed' => HandleMatchStateChanged::class,
            'game_state_update' => HandleGameStateUpdate::class,
            'game_management_json' => HandleGameManagementJson::class,
        ];

        HandleGameManagementJson::$subHandlers = [
            MetaMessageKind::DeckList->value => ApplyDeckList::class,
            MetaMessageKind::DieRoll->value => ApplyDieRoll::class,
            MetaMessageKind::PlayChoice->value => ApplyPlayChoice::class,
            MetaMessageKind::Mulligan->value => ApplyMulligan::class,
            MetaMessageKind::StartingHand->value => ApplyStartingHand::class,
            MetaMessageKind::TurnStart->value => ApplyTurnStart::class,
            MetaMessageKind::GameWinner->value => ApplyGameWinner::class,
            MetaMessageKind::Joined->value => ApplyJoined::class,
            MetaMessageKind::CastCard->value => ApplyCardCast::class,
            MetaMessageKind::PlayCard->value => ApplyCardPlay::class,
        ];
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
