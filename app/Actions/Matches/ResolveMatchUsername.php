<?php

namespace App\Actions\Matches;

use App\Facades\Mtgo;
use App\Models\LogEvent;
use Illuminate\Support\Collection;

/**
 * Resolve the local username for a match.
 *
 * Preference order:
 * 1. A sibling LogEvent on the same match that already carries a username
 *    (set by the ingestion layer when it sees a Login line).
 * 2. The Mtgo facade's `resolveUsername()` fallback, which combines the
 *    in-memory cache, the active Account, and a candidate-name match
 *    against any known Account. Used when no Login line exists in the
 *    current log file (e.g. day rotation).
 */
class ResolveMatchUsername
{
    /**
     * Resolve a username from an in-memory collection of LogEvents plus
     * an optional list of candidate player names (for the Mtgo facade
     * fallback when the events don't carry one).
     *
     * @param  Collection<int, LogEvent>  $events
     * @param  array<int, string>  $candidates
     */
    public static function fromEvents(Collection $events, array $candidates = []): ?string
    {
        $eventUsername = $events->first(fn (LogEvent $event) => $event->username !== null)?->username;

        if ($eventUsername !== null) {
            return $eventUsername;
        }

        return Mtgo::resolveUsername($candidates);
    }

    /**
     * Resolve a username by querying LogEvents for a match token, falling
     * back to the Mtgo facade. Used when callers don't already have the
     * events loaded.
     */
    public static function run(string $matchToken): ?string
    {
        $eventUsername = LogEvent::where('match_token', $matchToken)
            ->whereNotNull('username')
            ->value('username');

        if ($eventUsername !== null) {
            return $eventUsername;
        }

        return Mtgo::resolveUsername();
    }
}
