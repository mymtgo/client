<?php

namespace App\Providers;

use App\Events\LeagueJoined;
use App\Events\LeagueJoinRequested;
use App\Listeners\Pipeline\ProcessLeagueJoin;
use App\Managers\MtgoManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
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
    }
}
