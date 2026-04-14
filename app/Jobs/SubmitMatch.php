<?php

namespace App\Jobs;

use App\Actions\Matches\SubmitMatchToApi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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

    public function failed(?\Throwable $exception): void
    {
        Log::error('Match submission failed', [
            'match_id' => $this->matchId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
