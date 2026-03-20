<?php

namespace App\Listeners\Pipeline;

use App\Actions\Matches\AssignLeague;
use App\Actions\Util\ExtractKeyValueBlock;
use App\Events\LeagueMatchStarted;
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

        $gameMeta = ExtractKeyValueBlock::run($logEvent->raw_text);
        $updates = [];

        // Backfill mtgo_id if not set (match was created from match_state_changed which lacks match_id)
        if (! $match->mtgo_id && $logEvent->match_id) {
            $updates['mtgo_id'] = $logEvent->match_id;
        }

        // Backfill format/match_type from the Receiver: key-value block
        // (match_state_changed events that trigger CreateMatch lack this metadata)
        if ($match->format === 'Unknown' || $match->match_type === 'Unknown') {
            if (! empty($gameMeta['PlayFormatCd']) && $match->format === 'Unknown') {
                $updates['format'] = $gameMeta['PlayFormatCd'];
            }

            if (! empty($gameMeta['GameStructureCd']) && $match->match_type === 'Unknown') {
                $updates['match_type'] = $gameMeta['GameStructureCd'];
            }
        }

        if (! empty($updates)) {
            $match->update($updates);
        }

        // Assign league if not yet assigned and metadata is available
        if (! $match->league_id && ! empty($gameMeta['PlayFormatCd'])) {
            AssignLeague::run($match, $gameMeta);
            $match->refresh();

            if ($match->league_id) {
                LeagueMatchStarted::dispatch();
            }
        }

        $logEvent->update(['processed_at' => now()]);
    }
}
