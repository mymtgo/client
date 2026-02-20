<?php

namespace App\Jobs;

use App\Actions\Matches\SubmitMatchToApi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SubmitMatch implements ShouldQueue
{
    use Queueable;

    public function __construct(protected int $matchId) {}

    public function handle(): void
    {
        SubmitMatchToApi::run($this->matchId);
    }
}
