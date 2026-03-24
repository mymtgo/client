<?php

namespace App\Providers;

use App\Managers\MtgoManager;
use Illuminate\Support\Facades\DB;
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
        // NativePHP sets busy_timeout=5000 which is too short for our
        // pipeline transactions. Override to 30s to match our config intent.
        if (app()->environment('local') || config('database.default') === 'nativephp') {
            DB::statement('PRAGMA busy_timeout=30000;');
        }

        if (! config('mymtgo_api.verify_ssl')) {
            Http::globalOptions([
                'verify' => false,
            ]);
        }
    }
}
