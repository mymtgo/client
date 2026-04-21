<?php

namespace App\Actions\Pipeline;

use App\Actions\Matches\LinkMatchToTournament;
use App\Actions\Tournaments\EnqueueTournamentObservations;
use App\Models\MtgoMatch;

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

        // Phase 3: Backfill tournament tokens on matches whose round_info
        // event arrived after the match itself was created.
        MtgoMatch::query()
            ->whereNull('tournament_token')
            ->whereNotNull('tournament_event_id')
            ->get()
            ->each(fn (MtgoMatch $match) => LinkMatchToTournament::run($match));

        // Phase 4: Enqueue tournament observations for shipping.
        // The sender job runs on its own schedule (see MtgoManager::schedule).
        EnqueueTournamentObservations::run();
    }
}
