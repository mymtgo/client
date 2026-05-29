<?php

namespace App\Actions\Pipeline;

use App\Actions\Leagues\ProcessLeagueEvents;
use App\Actions\Matches\LinkMatchToTournament;
use App\Actions\Matches\RelinkOrphanMatches;
use App\Actions\Tournaments\EnqueueTournamentObservations;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class RunPipeline
{
    public static function run(): void
    {
        if (! app('mtgo')->pathsAreValid()) {
            return;
        }

        try {
            // Phase 1: Ingest main log
            app('mtgo')->ingestLogs();

            // Phase 1.5: Process league join/drop events. Runs before
            // ProcessMatchEvents so League rows (with event_id) exist before
            // AssignLeague needs to find them.
            ProcessLeagueEvents::run();

            // Phase 2: Process matches. Resolution now fires from inside
            // ProcessMatchEvents via ResolveMatchFromMetaMessages.
            ProcessMatchEvents::run();

            // Phase 3: Backfill tournament tokens on matches whose round_info
            // event arrived after the match itself was created.
            MtgoMatch::query()
                ->whereNull('tournament_token')
                ->whereNotNull('tournament_event_id')
                ->get()
                ->each(fn (MtgoMatch $match) => LinkMatchToTournament::run($match));

            // Phase 4: Enqueue tournament observations for shipping.
            EnqueueTournamentObservations::run();

            // Phase 5: Relink complete matches whose deck XML arrived after the
            // Started → InProgress boundary (otherwise they stay invisible in the
            // deck-scoped match views).
            RelinkOrphanMatches::run();
        } catch (\Throwable $e) {
            Log::channel('pipeline')->error('RunPipeline crashed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            throw $e;
        }
    }
}
