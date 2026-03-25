<?php

namespace App\Actions\Pipeline;

class RunPipeline
{
    public static function run(): void
    {
        if (! app('mtgo')->pathsAreValid()) {
            return;
        }

        // Phase 0: Discover game logs
        DiscoverGameLogs::run();

        // Phase 1: Ingest main log
        app('mtgo')->ingestLogs();

        // Phase 2: Process matches
        $processedTokens = ProcessMatchEvents::run();
        ResolveActiveMatches::run($processedTokens);
    }
}
