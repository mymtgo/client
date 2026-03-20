<?php

namespace App\Listeners\Pipeline;

use App\Events\MatchMetadataReceived;
use App\Models\MtgoMatch;

class StoreMatchMetadata
{
    public function handle(MatchMetadataReceived $event): void
    {
        $logEvent = $event->logEvent;

        if (! $logEvent->match_token || ! $logEvent->match_id) {
            return;
        }

        $match = MtgoMatch::findByEvent($logEvent);

        if (! $match) {
            return;
        }

        // Backfill mtgo_id if not set (match was created from match_state_changed which lacks match_id)
        if (! $match->mtgo_id && $logEvent->match_id) {
            $match->update(['mtgo_id' => $logEvent->match_id]);
        }

        $logEvent->update(['processed_at' => now()]);
    }
}
