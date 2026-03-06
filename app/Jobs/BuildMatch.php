<?php

namespace App\Jobs;

use App\Actions\Matches\AdvanceMatchState;
use App\Facades\Mtgo;
use App\Models\LogCursor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildMatch implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $matchToken,
        protected int|string $matchId,
    ) {}

    public function handle(): void
    {
        Mtgo::setUsername(LogCursor::first()->local_username);
        AdvanceMatchState::run($this->matchToken, $this->matchId);
    }
}
