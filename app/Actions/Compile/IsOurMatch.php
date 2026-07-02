<?php

namespace App\Actions\Compile;

use App\Models\LogEvent;

/**
 * Match-detection gate: a token is OUR match only when it carries real
 * GsMessage game traffic (a game_management_json event with a GameID).
 * State-change lines alone appear for observed/adjacent matches too, so
 * they never qualify — see docs/v1/client-agent/spec.md.
 */
final class IsOurMatch
{
    public function run(string $matchKey): bool
    {
        return LogEvent::query()
            ->where('match_token', $matchKey)
            ->where('event_type', 'game_management_json')
            ->whereNotNull('game_id')
            ->exists();
    }
}
