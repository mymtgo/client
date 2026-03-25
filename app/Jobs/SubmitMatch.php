<?php

namespace App\Jobs;

use App\Actions\Matches\SubmitMatchToApi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SubmitMatch implements ShouldQueue
{
    use Queueable;

    /** Retry up to 3 times with exponential backoff before moving to failed_jobs. */
    public int $tries = 3;

    /** @var int[] Seconds between retries: 10s, 60s, 5min */
    public array $backoff = [10, 60, 300];

    public function __construct(protected int $matchId) {}

    public function handle(): void
    {
        SubmitMatchToApi::run($this->matchId);
    }
}
