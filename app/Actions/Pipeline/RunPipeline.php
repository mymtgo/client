<?php

namespace App\Actions\Pipeline;

use App\Actions\Drafts\AbandonStaleDrafts;
use App\Actions\Drafts\AdoptCourselessDraftLeague;
use App\Actions\Drafts\LinkUnlinkedDrafts;
use App\Actions\Drafts\ProcessDraftEvents;
use App\Actions\Leagues\ProcessLeagueEvents;
use App\Actions\Matches\AbandonStaleMatches;
use App\Actions\Matches\LinkMatchToTournament;
use App\Actions\Matches\RelinkOrphanMatches;
use App\Actions\Overlay\SyncDraftNotesWindowVisibility;
use App\Actions\Sidecar\IngestSidecarEvents;
use App\Actions\Sidecar\ProjectUnprocessedSidecarEvents;
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

            // Phase 1.1: Sidecar event stream. No-op when the sidecar
            // directory does not exist (macOS, dev, disabled). Guarded on
            // its own: the sidecar is an external process writing files we
            // don't control (torn status.json, malformed lines), and it
            // must never abort log ingestion or projection for the tick.
            try {
                IngestSidecarEvents::run();
            } catch (\Throwable $e) {
                Log::channel('pipeline')->warning('Sidecar ingestion skipped this tick', [
                    'error' => $e->getMessage(),
                ]);
            }

            // Phase 1.5: Process league join/drop events. Runs before
            // ProcessMatchEvents so League rows (with event_id) exist before
            // AssignLeague needs to find them.
            ProcessLeagueEvents::run();

            // Phase 1.6: Project draft events (pod, picks, pool) so the draft
            // league exists with kind=draft before its first match arrives.
            // Looped so a catch-up replay of a whole draft (backlog larger
            // than one batch) fully drains before ProcessMatchEvents runs in
            // this same tick. Otherwise AssignLeague's pool-fit guard could
            // see a match projected against a still-partial pool.
            do {
                $draftEventsProcessed = ProcessDraftEvents::run();
            } while ($draftEventsProcessed >= ProcessDraftEvents::BATCH);

            // Phase 2: Process matches. Resolution now fires from inside
            // ProcessMatchEvents via ResolveMatchFromMetaMessages.
            ProcessMatchEvents::run();

            // Phase 2.4: Project sidecar events that no log activity will
            // bring back. ProcessMatchEvents only visits matches with
            // unprocessed log_events, so a game_ended or match_ended the
            // sidecar flushed after the log's last line for that match would
            // otherwise never reach games / matches. Guarded on its own for
            // the same reason phase 1.1 is: the sidecar is an external
            // process and must never abort a pipeline tick.
            try {
                ProjectUnprocessedSidecarEvents::run();
            } catch (\Throwable $e) {
                Log::channel('pipeline')->warning('Sidecar projection sweep skipped this tick', [
                    'error' => $e->getMessage(),
                ]);
            }

            // Phase 2.5: Abandon in_progress matches that will never resolve.
            // Runs after ProcessMatchEvents so any match still resolvable from
            // its events (e.g. orphaned end signals) is advanced first; only
            // genuinely dead matches (client killed mid-match, no close logged)
            // remain for the reaper.
            AbandonStaleMatches::run();

            // Phase 2.6: Drafts that went silent mid-pick, and drafts that
            // were first seen without a LeagueID but now have a played deck.
            AbandonStaleDrafts::run();
            LinkUnlinkedDrafts::run();

            // Phase 2.7: A draft whose lines arrived after its matches had
            // already minted a course-less draft league adopts that league
            // rather than leaving the run split in two. Needs the picks
            // projected, so it cannot happen inside ResolveDraftLeague.
            AdoptCourselessDraftLeague::run();

            // Phase 2.8: Reconcile the draft notes window against draft
            // state. One indexed query per tick; the Electron window API is
            // only touched when the desired state flips, so an idle machine
            // pays for the query alone. Opens within a tick of the first
            // PendingPick, closes on abandonment, and expires the 30 second
            // post-draft grace without a timer because this runs every tick.
            SyncDraftNotesWindowVisibility::run();

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
