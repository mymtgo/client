<?php

namespace App\Jobs;

use App\Actions\Matches\AdvanceMatchState;
use App\Facades\Mtgo;
use App\Models\LogEvent;
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
        $username = LogEvent::where('match_token', $this->matchToken)
            ->whereNotNull('username')
            ->value('username');

        if (! $username) {
            return;
        }

        Mtgo::setUsername($username);
        AdvanceMatchState::run($this->matchToken, $this->matchId);
    }
}
