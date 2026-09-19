<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\League;

class LeagueClientId
{
    /**
     * leagues.token is a log session token reused across runs, not a league
     * identifier; (token, started_at) is unique. Basic ISO-8601 UTC keeps
     * the id path-safe: no colons, no dots.
     */
    public static function for(League $league): string
    {
        return $league->token.'_'.$league->started_at->clone()->utc()->format('Ymd\THis\Z');
    }
}
