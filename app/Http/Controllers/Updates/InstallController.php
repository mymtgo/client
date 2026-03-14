<?php

namespace App\Http\Controllers\Updates;

use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Native\Desktop\Facades\AutoUpdater;

class InstallController
{
    public function __invoke()
    {
        Cache::forget('available_update');

        try {
            AutoUpdater::quitAndInstall();
        } catch (\Throwable $e) {
            report($e);
        }

        return Inertia::render('updates/Install');
    }
}
