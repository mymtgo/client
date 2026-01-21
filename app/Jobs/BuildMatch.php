<?php

namespace App\Jobs;

use App\Facades\Mtgo;
use App\Models\LogCursor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildMatch implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(protected string $matchToken, protected string $matchId)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Mtgo::setUsername(LogCursor::first()->local_username);
        \App\Actions\Matches\BuildMatch::run($this->matchToken, $this->matchId);
    }
}
