<?php

namespace App\Observers;

use App\Actions\Leagues\CompleteLeague;
use App\Actions\Matches\PurgeMatchDerivedData;
use App\Enums\LeagueState;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Events\AppNotification;
use App\Facades\AppSettings;
use App\Jobs\ComputeCardGameStats;
use App\Jobs\DetermineMatchArchetypesJob;
use App\Jobs\RunSyncJob;
use App\Jobs\SubmitMatch;
use App\Models\MtgoMatch;
use App\Services\Sync\SyncTokens;
use Illuminate\Support\Facades\Log;

class MtgoMatchObserver
{
    /**
     * Trigger enrichment when a match transitions to Complete.
     */
    public function updated(MtgoMatch $match): void
    {
        if ($match->isDirty('state') && $match->state === MatchState::Complete) {
            // Each enrichment is independent — failure in one doesn't block others
            try {
                DetermineMatchArchetypesJob::dispatch($match->id)->onQueue('match_archetypes');
            } catch (\Throwable $e) {
                Log::warning("Enrichment failed: archetypes for match {$match->id}: {$e->getMessage()}");
            }

            try {
                SubmitMatch::dispatch($match->id);
            } catch (\Throwable $e) {
                Log::warning("Enrichment failed: submit for match {$match->id}: {$e->getMessage()}");
            }

            try {
                ComputeCardGameStats::dispatch($match->id);
            } catch (\Throwable $e) {
                Log::warning("Enrichment failed: card stats for match {$match->id}: {$e->getMessage()}");
            }

            $won = $match->outcome === MatchOutcome::Win;

            AppNotification::dispatch(
                type: $won ? 'match_win' : 'match_loss',
                title: 'Match recorded '.$match->gameRecord(),
                message: $won ? 'Win' : 'Loss',
                route: '/matches/'.$match->id,
            );

            // League completion check
            if (($league = $match->league) && $league->state === LeagueState::Active) {
                CompleteLeague::runIfFinished($league);
            }

            // Cross-device sync should feel automatic: a completed match
            // schedules a run 30 seconds out. Short, so closing the app
            // soon after a match rarely leaves it stranded on this device;
            // any enrichment landing later marks the match dirty again and
            // rides the next run. RunSyncJob is unique while queued, so
            // completions close together fold into one run.
            try {
                if (! AppSettings::isOffline() && app(SyncTokens::class)->linked()) {
                    RunSyncJob::dispatch()->delay(now()->addSeconds(30));
                }
            } catch (\Throwable $e) {
                Log::warning("Enrichment failed: sync trigger for match {$match->id}: {$e->getMessage()}");
            }

            return;
        }

        // Deck linked (or swapped) on an already-complete match. Recompute card stats.
        if (
            $match->isDirty('deck_version_id')
            && $match->deck_version_id !== null
            && $match->state === MatchState::Complete
        ) {
            ComputeCardGameStats::dispatch($match->id);
        }
    }

    /**
     * Clean up all related records when a match is permanently deleted.
     */
    public function deleting(MtgoMatch $match): void
    {
        PurgeMatchDerivedData::run($match, includeLogEvents: true);
    }
}
