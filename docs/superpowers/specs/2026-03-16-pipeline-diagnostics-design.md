# Pipeline Diagnostics — Design Spec

## Goal

Give end users visibility into what the match pipeline is doing so they can self-diagnose issues and report bugs with useful context. Two parts: structured logging throughout the pipeline, and a viewer in the debug area.

## Part 1: Pipeline Log Channel

### Channel Configuration

New `pipeline` channel in `config/logging.php`:
- Driver: `daily`
- Path: `storage/logs/pipeline.log` (Laravel adds date suffix automatically)
- Retention: 7 days
- `replace_placeholders: true` (matches existing channels)
- Format: `[2026-03-16 14:05:03] pipeline.INFO: Message {"key":"value"}`

### What Gets Logged

Decision + evidence at each branch point. Every log message should answer "what happened and why."

#### IngestLog
```
Ingesting /path/to/mtgo.log (cursor: 45032 → 52180, +7148 bytes)
Ingested 23 events (12 classified, 11 unclassified)
Log rotation detected — cursor reset to 0
```

#### Classification Summary (emitted from IngestLog after batch completes)
Summary per ingestion batch only (not per-event). Logged from `IngestLog::run()` after the batch loop, not from `ClassifyLogEvent` itself:
```
Classification summary: 5 match_state_changed, 8 game_state_update, 2 deck_used, 3 game_management_json
```

#### BuildMatches
```
Found 3 unprocessed match tokens
Match 2856871 (token dff6c5...): creating — username: anticloser
Match 2856871: AdvanceMatchState → in_progress
Match 2856849 (token 4237fc...): skipped — no username on events
```

#### AdvanceMatchState
```
Match 2856871: join event found in game_management_json events
Match 2856871: Started → InProgress (3 game_state_update events, 2 game_ids)
Match 2856871: gameMeta keys: [PlayFormatCd, GameStructureCd, League Token]
Match 2856871: assigned to league #4 (token: abc123, phantom: false)
Match 2856871: InProgress → Ended (end signal: TournamentMatchClosedState)
Match 2856871: Ended → Complete (2-1, game log parsed)
Match 2856849: Started → InProgress FAILED (0 game_state_update events for match_id 2856849)
```

#### ResolveStaleMatches
```
Evaluating 4 incomplete matches (latest start: 2026-03-15 20:15:00)
Match 2856849: stale (started 2026-03-15 19:45:00) — final advance → still started
Match 2856849: voided (phantom league, could not complete)
Match 2856882: skipped (is latest match)
```

#### CreateGames
```
Match 2856871: created game 98765 (index 0), game 98766 (index 1)
Match 2856871: game 98765 — 2 players found, deck linked
```

### Log Level Usage
- `info` — state transitions, match created, games created, normal outcomes
- `warning` — something unexpected but handled (no join event, no game log file, voided)
- `error` — exceptions, pipeline failures

## Part 2: Pipeline Log Viewer

### Route & Navigation
- Route: `GET /debug/pipeline-log`
- Added to `DebugNav.vue` as "Pipeline Log" (last tab, after Log Cursors)
- Protected by existing `debug` middleware

### Controller
- `app/Http/Controllers/Debug/PipelineLog/IndexController.php`
- Reads `storage/logs/pipeline-{date}.log`
- Reverses line order (newest entries first)
- Accepts query params: `date` (defaults to today), `filter` (text search, server-side `str_contains`)
- Returns lines as string array to Inertia
- If file doesn't exist for selected date, returns empty array

### Vue Page
- `resources/js/pages/debug/PipelineLog.vue`
- **Top bar**: date picker (defaults to today) + text filter input + refresh button
- **Content**: scrollable monospace text, newest at top
- **Log level colours**: subtle left border or text colour — info default, warning amber, error red
- **Empty state**: "No log entries for this date"
- No pagination — full day's file returned, capped at 5000 lines as a safety valve

## Part 3: Match Force Delete

### Location
Existing `debug/Matches` page.

### Behaviour
- "Force Delete" button appears only on already soft-deleted matches
- Controller must use `MtgoMatch::withTrashed()->findOrFail($id)` since this operates on soft-deleted records
- Delegates to `PurgeMatch` action (shared with Part 4) which handles the full cascade
- Entire operation wrapped in `DB::transaction()`

### Cascade (via PurgeMatch action)

Deletion must follow this order to respect FK constraints (no `cascadeOnDelete` on these FKs):

1. `match_archetypes` where `mtgo_match_id` = match id
2. `archetype_attempts` where `match_id` = match id
3. `game_timelines` where `game_id` in match's game ids
4. `game_player` where `game_id` in match's game ids
5. `games` where `match_id` = match id
6. The match record itself (`forceDelete()`)

Then reset `processed_at` to `null` on all `log_events` where:
- `match_id` = the match's `mtgo_id`, OR
- `match_token` = the match's `token`, OR
- `game_id` IN the match's games' `mtgo_id` values

Logs to pipeline channel: `"Match 2856849: force deleted — reset 47 log events for reingestion"`

### Route
- `DELETE /debug/matches/{match}/force` — new endpoint on the debug matches resource

## Part 4: Standalone Event Reset

### Location
Existing `debug/Matches` page (top section, above the matches table).

### Behaviour
- Small form: text input for match ID or match token, "Reset & Rebuild" button
- On submit, delegates to `PurgeMatch` action (same as Part 3):
  1. Finds the match by `mtgo_id` or `token` (using `withTrashed()`)
  2. Runs the full cascade deletion (see Part 3 cascade order)
  3. Sets `processed_at = null` on all matching `log_events`
  4. Logs: `"Manual reset: 47 events reset for match_id 2856849, match and games deleted"`
- If no match found for the input, still resets any matching log events (events may exist without a match record)
- Entire operation wrapped in `DB::transaction()`
- Success/error feedback via toast

### Route
- `POST /debug/matches/reset` — accepts `identifier` (match ID or token)

## Files Modified

### New files
- `app/Actions/Matches/PurgeMatch.php` — shared cascade deletion + event reset logic
- `app/Http/Controllers/Debug/PipelineLog/IndexController.php`
- `resources/js/pages/debug/PipelineLog.vue`
- `app/Http/Controllers/Debug/Matches/ForceDeleteController.php`
- `app/Http/Controllers/Debug/Matches/ResetController.php`

### Modified files
- `config/logging.php` — add pipeline channel
- `routes/web.php` — add new debug routes
- `resources/js/components/debug/DebugNav.vue` — add Pipeline Log tab
- `resources/js/pages/debug/Matches.vue` — add Force Delete button + Reset & Rebuild form
- `app/Actions/Logs/IngestLog.php` — add pipeline logging + classification summary
- `app/Actions/Matches/BuildMatches.php` — add/enhance pipeline logging
- `app/Actions/Matches/AdvanceMatchState.php` — add pipeline logging at each transition
- `app/Actions/Matches/ResolveStaleMatches.php` — add pipeline logging
- `app/Actions/Matches/CreateGames.php` — add/enhance pipeline logging
