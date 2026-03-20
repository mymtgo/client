<?php

namespace App\Listeners\Pipeline;

use App\Actions\Matches\CreateOrUpdateGames;
use App\Enums\MatchState;
use App\Events\GameStateChanged;
use App\Models\LogEvent;
use App\Models\MtgoMatch;

class UpdateGameState
{
    public function handle(GameStateChanged $event): void
    {
        $logEvent = $event->logEvent;

        $match = MtgoMatch::where('mtgo_id', $logEvent->match_id)->first()
            ?? MtgoMatch::where('token', $logEvent->match_token)->first();

        if (! $match) {
            return;
        }

        // Only update game state for active matches
        if (! in_array($match->state, [MatchState::InProgress, MatchState::Ended])) {
            return;
        }

        $allEvents = LogEvent::where('match_id', $logEvent->match_id)
            ->orderBy('timestamp')
            ->get();

        CreateOrUpdateGames::run($match, $allEvents);
    }
}
