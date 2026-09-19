<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunSyncJob;
use Illuminate\Console\Command;

class SyncRun extends Command
{
    protected $signature = 'sync:run {--full : Force since=null and treat every local row as dirty}';

    protected $description = 'Dispatch a sync run against the MyMTGO API.';

    public function handle(): int
    {
        RunSyncJob::dispatch((bool) $this->option('full'));

        return self::SUCCESS;
    }
}
