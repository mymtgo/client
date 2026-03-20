<?php

namespace App\Listeners\Pipeline;

use App\Enums\MatchState;
use App\Events\MatchEnded;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class EndMatch
{
    public function handle(MatchEnded $event): void
    {
        $logEvent = $event->logEvent;
        $match = MtgoMatch::where('token', $logEvent->match_token)->first();

        if (! $match || $match->state !== MatchState::InProgress) {
            return;
        }

        // Use the last event for the match to determine end time
        $lastEvent = LogEvent::where('match_token', $logEvent->match_token)
            ->orderBy('id', 'desc')
            ->first();

        $ended = Carbon::parse($lastEvent->logged_at ?? now())
            ->setTimeFromTimeString($lastEvent->timestamp ?? now()->toTimeString());

        $match->update([
            'ended_at' => $ended,
            'state' => MatchState::Ended,
        ]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: InProgress → Ended", [
            'signal' => $logEvent->context,
        ]);
    }
}
