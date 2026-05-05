<?php

namespace App\Actions\Tournaments;

use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Support\Facades\Log;

class RefreshTournamentMetadata
{
    /**
     * Backfill tournaments from historical matches that already carry a
     * tournament_event_id but no local tournament_id link. Creates a tournament
     * row per (mtgo_event_id, deck_version_id) pair using the API as the
     * source of truth — pairs whose event id is unknown to the API are
     * skipped (re-run later to pick them up).
     *
     * @return array{
     *   pairs_scanned: int,
     *   tournaments_created: int,
     *   matches_linked: int,
     *   pairs_skipped_api_miss: int,
     * }
     */
    public static function run(): array
    {
        $pairs = MtgoMatch::query()
            ->whereNotNull('tournament_event_id')
            ->whereNull('tournament_id')
            ->select('tournament_event_id', 'deck_version_id')
            ->distinct()
            ->get();

        $created = 0;
        $linked = 0;
        $skipped = 0;

        foreach ($pairs as $pair) {
            $eventId = (int) $pair->tournament_event_id;
            $deckVersionId = $pair->deck_version_id;

            $tournament = Tournament::query()
                ->where('mtgo_event_id', $eventId)
                ->where(function ($q) use ($deckVersionId) {
                    $q->whereNull('deck_version_id')
                        ->orWhere('deck_version_id', $deckVersionId);
                })
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
                    'deck_version_id' => $deckVersionId,
                    'name_synthesized' => false,
                ]);
                $created++;
            }

            $linkedCount = MtgoMatch::query()
                ->where('tournament_event_id', $eventId)
                ->when(
                    $deckVersionId === null,
                    fn ($q) => $q->whereNull('deck_version_id'),
                    fn ($q) => $q->where('deck_version_id', $deckVersionId),
                )
                ->whereNull('tournament_id')
                ->update(['tournament_id' => $tournament->id]);

            $linked += $linkedCount;
        }

        $summary = [
            'pairs_scanned' => $pairs->count(),
            'tournaments_created' => $created,
            'matches_linked' => $linked,
            'pairs_skipped_api_miss' => $skipped,
        ];

        Log::channel('pipeline')->info('RefreshTournamentMetadata: backfill pass complete', $summary);

        return $summary;
    }
}
