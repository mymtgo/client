<?php

namespace App\Actions\Matches;

use App\Facades\Mtgo;
use App\Models\LogCursor;
use App\Models\LogEvent;

class BuildMatches
{
    public static function run()
    {
        $matchTokens = LogEvent::whereNotNull('match_id')
            ->whereNotNull('match_token')
            ->whereNull('processed_at')
            ->distinct()
            ->pluck('match_token');

        $matchIds = LogEvent::whereIn('match_token', $matchTokens)->whereNotNull('match_id')->distinct()->pluck('match_id', 'match_token');

        Mtgo::setUsername(LogCursor::first()->local_username);

        foreach ($matchIds as $matchToken => $matchId) {
            \App\Jobs\BuildMatch::dispatch($matchToken, $matchId);
        }
    }
}
