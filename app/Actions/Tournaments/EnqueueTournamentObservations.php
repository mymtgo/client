<?php

namespace App\Actions\Tournaments;

use App\Enums\LogEventType;
use App\Facades\AppSettings;
use App\Models\LogEvent;
use App\Models\TournamentObservationQueue;
use Illuminate\Support\Facades\Log;

class EnqueueTournamentObservations
{
    /**
     * Create queue rows for tournament log events that haven't been
     * enqueued yet. Safe to call repeatedly; unique FK on log_event_id
     * guarantees idempotency.
     */
    public static function run(int $limit = 500): int
    {
        if (AppSettings::isOffline()) {
            return 0;
        }

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

            if (empty($payload)) {
                // Record a terminal 'skipped' row so this un-shippable event is
                // not re-selected (and re-logged) on every pipeline run. The
                // unique FK on log_event_id keeps this idempotent, so the
                // warning fires at most once per event rather than forever.
                Log::warning('EnqueueTournamentObservations: skipping event with empty payload', [
                    'log_event_id' => $event->id,
                    'event_type' => $event->event_type,
                    'raw_text_preview' => mb_substr($event->raw_text, 0, 200),
                ]);

                TournamentObservationQueue::query()->insertOrIgnore([
                    'log_event_id' => $event->id,
                    'tournament_token' => $event->tournament_token,
                    'match_token' => $event->match_token,
                    'event_type' => $event->event_type,
                    'payload' => json_encode([]),
                    'client_observed_at' => $event->ingested_at,
                    'status' => 'skipped',
                    'attempts' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

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
