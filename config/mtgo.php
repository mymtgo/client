<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Stale match abandonment threshold
    |--------------------------------------------------------------------------
    |
    | An in_progress match that has received no new log activity for this many
    | minutes — and carries no match-end signal — is marked Abandoned by the
    | AbandonStaleMatches reaper. MTGO writes no clean close when the client is
    | killed mid-match (e.g. quitting during sideboarding), so without this the
    | match would stay in_progress forever.
    |
    */
    'match_abandon_after_minutes' => (int) env('MTGO_MATCH_ABANDON_AFTER_MINUTES', 60),
];
