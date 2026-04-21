<?php

namespace App\Actions\Pipeline;

use App\Actions\Tournaments\EnqueueTournamentObservations;

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

        // Phase 3: Enqueue tournament observations for shipping.
        // The sender job runs on its own schedule (see MtgoManager::schedule).
        EnqueueTournamentObservations::run();
    }
}
