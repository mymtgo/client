<?php

namespace App\Actions\Tournaments;

use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Support\Facades\Log;

class RefreshTournamentMetadata
{
    /**
     * Per-run cap on API lookups. Prevents a single backfill pass from
     * stalling the queue worker on large histories or flaky networks.
     */
    public const PER_RUN_CAP = 50;

    /**
     * Backfill tournaments from historical matches that already carry a
     * tournament_event_id but no local tournament_id link. Creates one
     * tournament row per mtgo_event_id using the API as the source of
     * truth — events the API does not know about are skipped (re-run later
     * to pick them up).
     *
     * Caller may pass a smaller cap to short-circuit boot-time runs.
     *
     * @return array{
     *   events_scanned: int,
     *   tournaments_created: int,
     *   matches_linked: int,
     *   events_skipped_api_miss: int,
     *   events_remaining: int,
     * }
     */
    public static function run(int $cap = self::PER_RUN_CAP): array
    {
        $eventIds = MtgoMatch::query()
            ->whereNotNull('tournament_event_id')
            ->whereNull('tournament_id')
            ->distinct()
            ->pluck('tournament_event_id');

        $totalEvents = $eventIds->count();
        $eventIds = $eventIds->take($cap);

        $created = 0;
        $linked = 0;
        $skipped = 0;

        foreach ($eventIds as $eventId) {
            $eventId = (int) $eventId;

            $tournament = Tournament::query()
                ->where('mtgo_event_id', $eventId)
                ->first();

            if (! $tournament) {
                $meta = FetchTournamentMetadata::run($eventId);

                if ($meta === null) {
                    $skipped++;

                    continue;
                }

                $token = MtgoMatch::query()
                    ->where('tournament_event_id', $eventId)
                    ->whereNotNull('tournament_token')
                    ->value('tournament_token');

                $tournament = Tournament::create([
                    'mtgo_event_id' => $eventId,
                    'token' => $token,
                    'name' => $meta['name'],
                    'format' => $meta['format'] ?? null,
                    'started_at' => $meta['started_at'] ?? now(),
                    'name_synthesized' => false,
                ]);
                $created++;
            }

            $linked += MtgoMatch::query()
                ->where('tournament_event_id', $eventId)
                ->whereNull('tournament_id')
                ->update(['tournament_id' => $tournament->id]);
        }

        $summary = [
            'events_scanned' => $eventIds->count(),
            'tournaments_created' => $created,
            'matches_linked' => $linked,
            'events_skipped_api_miss' => $skipped,
            'events_remaining' => max(0, $totalEvents - $eventIds->count()),
        ];

        Log::channel('pipeline')->info('RefreshTournamentMetadata: backfill pass complete', $summary);

        return $summary;
    }
}
