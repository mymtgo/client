<?php

namespace App\Providers;

use App\Events\LeagueJoined;
use App\Events\LeagueJoinRequested;
use App\Listeners\Pipeline\ProcessLeagueJoin;
use App\Managers\MtgoManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Native\Desktop\Events\ChildProcess\ErrorReceived;
use Native\Desktop\Events\ChildProcess\MessageReceived;
use Native\Desktop\Events\ChildProcess\ProcessExited;
use Native\Desktop\Events\ChildProcess\ProcessSpawned;

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
        // ProcessLeagueJoin accepts object in handle(), so auto-discovery can't map it.
        Event::listen(LeagueJoined::class, [ProcessLeagueJoin::class, 'handle']);
        Event::listen(LeagueJoinRequested::class, [ProcessLeagueJoin::class, 'handle']);

        if (! config('mymtgo_api.verify_ssl')) {
            Http::globalOptions([
                'verify' => false,
            ]);
        }

        // Log all NativePHP child process events to trace the IPC chain
        Event::listen(ProcessSpawned::class, function (ProcessSpawned $event) {
            Log::channel('pipeline')->info('NativePHP:ProcessSpawned', ['alias' => $event->alias]);
        });
        Event::listen(ProcessExited::class, function (ProcessExited $event) {
            Log::channel('pipeline')->warning('NativePHP:ProcessExited', ['alias' => $event->alias, 'code' => $event->code]);
        });
        Event::listen(MessageReceived::class, function (MessageReceived $event) {
            Log::channel('pipeline')->debug('NativePHP:MessageReceived', [
                'alias' => $event->alias,
                'data_preview' => is_string($event->data) ? mb_substr($event->data, 0, 150) : '[non-string]',
            ]);
        });
        Event::listen(ErrorReceived::class, function (ErrorReceived $event) {
            Log::channel('pipeline')->error('NativePHP:ErrorReceived', [
                'alias' => $event->alias ?? 'unknown',
                'data' => is_string($event->data ?? null) ? mb_substr($event->data, 0, 300) : '[non-string]',
            ]);
        });
    }
}
