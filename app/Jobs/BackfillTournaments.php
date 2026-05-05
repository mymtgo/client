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
        RefreshTournamentMetadata::run();
    }
}
