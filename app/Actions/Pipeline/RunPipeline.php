<?php

namespace App\Actions\Pipeline;

use App\Actions\Leagues\ProcessLeagueEvents;
use App\Actions\Matches\AbandonStaleMatches;
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

            // Phase 2.5: Abandon in_progress matches that will never resolve.
            // Runs after ProcessMatchEvents so any match still resolvable from
            // its events (e.g. orphaned end signals) is advanced first; only
            // genuinely dead matches (client killed mid-match, no close logged)
            // remain for the reaper.
            AbandonStaleMatches::run();

            // Phase 3: Backfill tournament tokens on matches whose round_info
            // event arrived after the match itself was created.
            //
            // Bounded deliberately. Each candidate costs a `raw_text LIKE`
            // scan over log_events, and a match whose round_info event has
            // since been pruned can never resolve — unbounded, that dead set
            // only grows and every tick pays for all of it.
            MtgoMatch::query()
                ->whereNull('tournament_token')
                ->whereNotNull('tournament_event_id')
                ->where('started_at', '>', now()->subDays(7))
                ->orderByDesc('started_at')
                ->limit(20)
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
