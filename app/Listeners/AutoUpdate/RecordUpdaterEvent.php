<?php

namespace App\Listeners\AutoUpdate;

use App\Actions\AutoUpdate\ResolveUpdateStatus;
use App\Actions\Tray\SyncTrayUpdateState;
use Native\Desktop\Events\AutoUpdater\CheckingForUpdate;
use Native\Desktop\Events\AutoUpdater\Error;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Native\Desktop\Events\AutoUpdater\UpdateNotAvailable;

/**
 * Records every electron-updater lifecycle event so the banner, status bar,
 * tray and settings all read one state. Registered by event discovery.
 */
class RecordUpdaterEvent
{
    public function handle(CheckingForUpdate|UpdateAvailable|UpdateDownloaded|UpdateNotAvailable|Error $event): void
    {
        match (true) {
            $event instanceof CheckingForUpdate => ResolveUpdateStatus::recordCheck('checking'),
            $event instanceof UpdateAvailable => ResolveUpdateStatus::recordCheck('downloading', $event->version),
            $event instanceof UpdateNotAvailable => ResolveUpdateStatus::recordCheck('up_to_date'),
            $event instanceof Error => ResolveUpdateStatus::recordCheck('error', error: $event->message),
            $event instanceof UpdateDownloaded => $this->downloaded($event),
        };
    }

    private function downloaded(UpdateDownloaded $event): void
    {
        ResolveUpdateStatus::recordDownloaded($event->version);
        ResolveUpdateStatus::recordCheck('up_to_date');

        SyncTrayUpdateState::run();
    }
}
