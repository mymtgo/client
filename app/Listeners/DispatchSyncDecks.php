<?php

namespace App\Listeners;

use App\Events\AccountCreated;
use App\Jobs\SyncDecks;

class DispatchSyncDecks
{
    public function handle(AccountCreated $event): void
    {
        SyncDecks::dispatch();
    }
}
