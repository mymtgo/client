<?php

namespace App\Listeners\Pipeline;

use App\Actions\Matches\CreateOrUpdateGames;
use App\Enums\MatchState;
use App\Events\GameStateChanged;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class AdvanceMatchToInProgress
{
    public function handle(GameStateChanged $event): void
    {
        $logEvent = $event->logEvent;

        $match = MtgoMatch::findByEvent($logEvent);

        if (! $match || $match->state !== MatchState::Started) {
            return;
        }

        // Backfill mtgo_id if it was null (from CreateMatch)
        if (! $match->mtgo_id && $logEvent->match_id) {
            $match->update(['mtgo_id' => $logEvent->match_id]);
        }

        $gameStateEvents = LogEvent::where('match_id', $logEvent->match_id)
            ->where('event_type', 'game_state_update')
            ->get();

        if ($gameStateEvents->isEmpty()) {
            return;
        }

        // Create games from all available match events
        $allEvents = LogEvent::where('match_id', $logEvent->match_id)
            ->orderBy('timestamp')
            ->get();

        CreateOrUpdateGames::run($match, $allEvents);

        $match->update(['state' => MatchState::InProgress]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: Started → InProgress", [
            'game_state_events' => $gameStateEvents->count(),
        ]);
    }
}
