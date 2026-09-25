<?php

namespace App\Actions\Matches;

use App\Actions\Util\ExtractKeyValueBlock;
use App\Enums\LogEventType;
use App\Models\LogEvent;
use App\Models\MtgoMatch;

class ReadJoinedGameMeta
{
    /**
     * Re-extract gameMeta from the most informative joined-state log event
     * for the match. Prefers the game_management_json variant (carries
     * Receiver: + key=value block); falls back to the match_state_changed
     * header only if no JSON variant exists. Null when the match has no
     * joined-state event (never logged, or pruned).
     *
     * @return array<string, mixed>|null
     */
    public static function run(MtgoMatch $match): ?array
    {
        $joinedState = LogEvent::where('match_token', $match->token)
            ->where('event_type', 'game_management_json')
            ->where('context', 'like', '%MatchJoinedEventUnderwayState%')
            ->orderByDesc('id')
            ->first()
            ?? LogEvent::where('match_token', $match->token)
                ->where('event_type', LogEventType::MATCH_STATE_CHANGED->value)
                ->where('context', 'like', '%MatchJoinedEventUnderwayState%')
                ->orderByDesc('id')
                ->first();

        return $joinedState ? ExtractKeyValueBlock::run($joinedState->raw_text) : null;
    }
}
