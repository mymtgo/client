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

    /*
    |--------------------------------------------------------------------------
    | Stuck-cursor force-seal threshold
    |--------------------------------------------------------------------------
    |
    | When the active log file keeps growing but the ingestion cursor has not
    | advanced for this many seconds, the log instance is force-sealed and
    | recreated. Wall-clock based so the watcher's tick cadence cannot change
    | the effective timeout.
    |
    */
    'cursor_stuck_after_seconds' => (int) env('MTGO_CURSOR_STUCK_AFTER_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Watcher tick interval
    |--------------------------------------------------------------------------
    |
    | How often (in milliseconds) the mtgo:watch daemon stat-polls the MTGO
    | log files. Detection latency averages half this value.
    |
    */
    'watch_interval_ms' => (int) env('MTGO_WATCH_INTERVAL_MS', 150),
];
