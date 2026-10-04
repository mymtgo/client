<?php

namespace App\Actions\Leagues;

use App\Actions\Overlay\DetectSideboarding;
use App\Models\MtgoMatch;

class DecideMatchPhase
{
    /**
     * Whether the local player is sideboarding or playing in an active match,
     * read from the log via the same DetectSideboarding the game overlay uses.
     *
     * @return 'sideboarding'|'in_game'
     */
    public static function run(MtgoMatch $match): string
    {
        return DetectSideboarding::run($match) ? 'sideboarding' : 'in_game';
    }
}
