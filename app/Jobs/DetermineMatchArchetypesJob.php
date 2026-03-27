<?php

namespace App\Jobs;

use App\Actions\DetermineMatchArchetypes;
use App\Models\MtgoMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DetermineMatchArchetypesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public int $matchId,
    ) {}

    public function handle(): void
    {
        $match = MtgoMatch::with('games.players')->find($this->matchId);

        if (! $match) {
            return;
        }

        DetermineMatchArchetypes::run($match);
    }
}
