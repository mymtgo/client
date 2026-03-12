<?php

namespace App\Http\Controllers\Updates;

use Illuminate\Support\Facades\Cache;
use Native\Desktop\Facades\AutoUpdater;

class InstallController
{
    public function __invoke()
    {
        Cache::forget('available_update');

        AutoUpdater::quitAndInstall();

        return redirect()->back();
    }
}
