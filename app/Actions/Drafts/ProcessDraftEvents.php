<?php

namespace App\Actions\Drafts;

use App\Enums\LogEventType;
use App\Models\LogEvent;
use Illuminate\Support\Facades\Log;

class ProcessDraftEvents
{
    /**
     * Chunk size for a single query in run(), not a per-tick delay budget.
     * A whole-draft backlog still fully drains within one RunPipeline tick:
     * RunPipeline loops run() until a call processes fewer than BATCH
     * events, so a run of picks never crosses into the same tick as
     * ProcessMatchEvents partway through. That loop is deliberate, not a
     * side effect: a slow tick is cheaper than AssignLeague's re-entry
     * guard comparing a registered deck against a still-partial pool.
     */
    public const BATCH = 200;

    /**
     * Replay one chunk (up to $limit) of unprocessed draft events in log
     * order. Callers that need the whole backlog drained, such as
     * RunPipeline, loop this until it returns fewer than $limit.
     *
     * @return int Number of events processed by this call.
     */
    public static function run(int $limit = self::BATCH): int
    {
        $events = LogEvent::query()
            ->whereIn('event_type', LogEventType::draftValues())
            ->whereNull('processed_at')
            ->orderBy('logged_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            return 0;
        }

        foreach ($events as $event) {
            try {
                ApplyDraftEvent::run($event);
            } catch (\Throwable $e) {
                Log::channel('pipeline')->error('ProcessDraftEvents: failed to apply event', [
                    'log_event_id' => $event->id,
                    'event_type' => $event->event_type,
                    'message' => $e->getMessage(),
                ]);
            }

            $event->update(['processed_at' => now()]);
        }

        return $events->count();
    }
}
