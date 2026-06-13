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
            static::hotPath();
            static::maintenance();
        } catch (\Throwable $e) {
            Log::channel('pipeline')->error('RunPipeline crashed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            throw $e;
        }
    }

    /**
     * Latency-critical phases — run on every watcher tick that sees log growth.
     */
    public static function hotPath(): void
    {
        // Phase 1: Ingest main log
        app('mtgo')->ingestLogs();

        // Phase 1.5: Process league join/drop events. Runs before
        // ProcessMatchEvents so League rows (with event_id) exist before
        // AssignLeague needs to find them.
        ProcessLeagueEvents::run();

        // Phase 2: Process matches. Resolution fires from inside
        // ProcessMatchEvents via ResolveMatchFromMetaMessages.
        ProcessMatchEvents::run();
    }

    /**
     * Slow phases — run every ~30 s (watcher internal timer or backstop job).
     * Safe to call independently of hotPath().
     */
    public static function maintenance(): void
    {
        // Abandon in_progress matches that will never resolve. Runs after the
        // hot path so any match still resolvable from its events (e.g. orphaned
        // end signals) is advanced first; only genuinely dead matches (client
        // killed mid-match, no close logged) remain for the reaper.
        AbandonStaleMatches::run();

        // Backfill tournament tokens on matches whose round_info event
        // arrived after the match itself was created.
        MtgoMatch::query()
            ->whereNull('tournament_token')
            ->whereNotNull('tournament_event_id')
            ->get()
            ->each(fn (MtgoMatch $match) => LinkMatchToTournament::run($match));

        // Enqueue tournament observations for shipping.
        EnqueueTournamentObservations::run();

        // Relink complete matches whose deck XML arrived after the
        // Started → InProgress boundary (otherwise they stay invisible in the
        // deck-scoped match views).
        RelinkOrphanMatches::run();
    }
}
