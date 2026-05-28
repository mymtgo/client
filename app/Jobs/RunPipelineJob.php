<?php

namespace App\Jobs;

use App\Actions\Pipeline\RunPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Background dispatch of the unified pipeline tick. Used by the debug
 * "Process Now" button so the web request returns immediately instead of
 * running the pipeline inline and hitting PHP-CGI's 30s max_execution_time.
 * The every-2s scheduler still calls RunPipeline::run() directly.
 */
class RunPipelineJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        RunPipeline::run();
    }
}
