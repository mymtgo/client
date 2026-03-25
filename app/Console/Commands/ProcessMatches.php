<?php

namespace App\Console\Commands;

use App\Actions\Pipeline\RunPipeline;
use Illuminate\Console\Command;

class ProcessMatches extends Command
{
    protected $signature = 'mtgo:process-matches';

    protected $description = 'Unified pipeline: ingest logs, advance matches, resolve game results';

    public function handle(): int
    {
        RunPipeline::run();

        return self::SUCCESS;
    }
}
