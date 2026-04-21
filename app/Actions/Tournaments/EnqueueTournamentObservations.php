<?php

namespace App\Actions\Tournaments;

use App\Enums\LogEventType;
use App\Models\LogEvent;
use App\Models\TournamentObservationQueue;

class EnqueueTournamentObservations
{
    /**
     * Create queue rows for tournament log events that haven't been
     * enqueued yet. Safe to call repeatedly; unique FK on log_event_id
     * guarantees idempotency.
     */
    public static function run(int $limit = 500): int
    {
        $events = LogEvent::query()
            ->whereIn('event_type', LogEventType::tournamentValues())
            ->whereNotIn('id', fn ($q) => $q
                ->select('log_event_id')
                ->from('tournament_observation_queue')
            )
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $inserted = 0;
        foreach ($events as $event) {
            $payload = ExtractTournamentPayload::run($event);

            TournamentObservationQueue::query()->insertOrIgnore([
                'log_event_id' => $event->id,
                'tournament_token' => $event->tournament_token,
                'match_token' => $event->match_token,
                'event_type' => $event->event_type,
                'payload' => json_encode($payload),
                'client_observed_at' => $event->ingested_at,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $inserted++;
        }

        return $inserted;
    }
}
