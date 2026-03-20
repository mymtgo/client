<?php

namespace App\Listeners\Pipeline;

use App\Actions\Matches\AssignLeague;
use App\Actions\Util\ExtractKeyValueBlock;
use App\Events\LeagueMatchStarted;
use App\Events\MatchJoined;
use App\Models\MtgoMatch;

class AssignMatchLeague
{
    public function handle(MatchJoined $event): void
    {
        $match = MtgoMatch::findByEvent($event->logEvent);

        if (! $match || $match->league_id) {
            return;
        }

        $gameMeta = ExtractKeyValueBlock::run($event->logEvent->raw_text);

        AssignLeague::run($match, $gameMeta);
        $match->refresh();

        if ($match->league_id) {
            LeagueMatchStarted::dispatch();
        }
    }
}
