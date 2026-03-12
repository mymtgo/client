<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Cache;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;

class StoreAvailableUpdate
{
    public function handle(UpdateDownloaded $event): void
    {
        Cache::put('available_update', [
            'version' => $event->version,
            'releaseName' => $event->releaseName,
            'releaseNotes' => $event->releaseNotes,
        ]);
    }
}
