<?php

namespace App\Listeners;

use App\Events\LeagueOverlayChanged;
use App\Facades\AppSettings;
use App\Jobs\PublishOverlayStateJob;

class PublishOverlayOnChange
{
    public function handle(LeagueOverlayChanged $event): void
    {
        if (AppSettings::overlayPublish()) {
            PublishOverlayStateJob::dispatch();
        }
    }
}
