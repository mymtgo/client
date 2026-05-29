<?php

namespace App\Jobs;

use App\Facades\Mtgo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Background dispatch of log ingestion. Used by the debug log-events
 * "Ingest" button and the settings "Run Ingest" button so neither blocks
 * a web request long enough to hit PHP-CGI's 30s ceiling.
 */
class IngestLogs implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Mtgo::ingestLogs();
    }
}
