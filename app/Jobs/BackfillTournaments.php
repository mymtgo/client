<?php

namespace App\Jobs;

use App\Actions\Tournaments\RefreshTournamentMetadata;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class BackfillTournaments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('backfill-tournaments'))->dontRelease()];
    }

    public function handle(): void
    {
        $summary = RefreshTournamentMetadata::run();

        // Re-dispatch only when this pass made progress AND work remains.
        // Stopping on a zero-progress pass prevents an infinite re-dispatch
        // loop when every remaining event id is permanently unknown to the
        // API (404). Those events get retried on the next app-update trigger.
        $progressed = $summary['tournaments_created'] > 0 || $summary['matches_linked'] > 0;

        if ($progressed && $summary['events_remaining'] > 0) {
            self::dispatch()->delay(now()->addSeconds(30));
        }
    }
}
