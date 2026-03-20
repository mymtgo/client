<?php

namespace App\Listeners\Pipeline;

use App\Actions\Util\ExtractKeyValueBlock;
use App\Enums\MatchState;
use App\Events\MatchJoined;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CreateMatch
{
    public function handle(MatchJoined $event): void
    {
        $logEvent = $event->logEvent;
        $matchToken = $logEvent->match_token;

        // Look up by token — match_state_changed events don't carry match_id
        $existing = MtgoMatch::where('token', $matchToken)->first();

        if ($existing) {
            return;
        }

        $gameMeta = ExtractKeyValueBlock::run($logEvent->raw_text);

        $started = Carbon::parse($logEvent->logged_at)
            ->setTimeFromTimeString($logEvent->timestamp);

        MtgoMatch::create([
            'token' => $matchToken,
            'format' => $gameMeta['PlayFormatCd'] ?? 'Unknown',
            'match_type' => $gameMeta['GameStructureCd'] ?? 'Unknown',
            'started_at' => $started,
            'ended_at' => $started,
            'state' => MatchState::Started,
            // mtgo_id will be backfilled by StoreMatchMetadata when
            // game_management_json event arrives (which carries MatchID)
        ]);

        Log::channel('pipeline')->info('Match created in Started state', [
            'token' => $matchToken,
        ]);
    }
}
