# Pipeline Rearchitecture — Design Spec

**Status:** Complete
**Date:** 2026-03-24 / 2026-03-25
**Input doc:** `docs/mymtgo-log-parsing-rearchitecture.md`

---

## Current System (As-Is)

### Three loosely-coupled processes

**1. Ingest pipeline** (every 1s, `withoutOverlapping`)
- `MtgoManager::schedule()` runs `ingestLogs()` then `BuildMatches::run()`
- `IngestLog` reads main log from cursor, classifies events via `ClassifyLogEvent`, batch-inserts `LogEvent` rows
- `BuildMatches` finds unprocessed events grouped by match token, calls `AdvanceMatchState` for each
- `AdvanceMatchState` creates matches, advances through Started → InProgress → Ended using log events
- `ResolveStaleMatches` runs at the end to void/end stuck matches

**2. Resolve pipeline** (every 2s, `withoutOverlapping`)
- `PollGameLogs` discovers binary game log files via filesystem scan (`Finder`), creates `GameLog` records, parses them via `ParseGameLogBinary`
- `ResolveGameResults` uses parsed game log data to determine win/loss via `ExtractGameResults` and `DetermineMatchResult`, pushes matches to Complete
- `ResolvePendingResults` is a fallback that checks match history files for matches stuck in PendingResult

**3. Stale match cleanup**
- `ResolveStaleMatches` (inside BuildMatches) voids/ends matches that are older than the newest match

### Known problems

1. **Two independent schedules with implicit ordering** — ingest pipeline creates matches, resolve pipeline completes them, but they don't coordinate
2. **Filesystem scanning for game logs** — `PollGameLogs` uses `Finder` to discover files instead of constructing paths from known match tokens
3. **Game log discovery gap** — `PollGameLogs` dispatches as async job (`ShouldQueue`), adding timing uncertainty
4. **Cursor advances before downstream processing** — `IngestLog` commits cursor in its own transaction, but `BuildMatches` runs separately after
5. **Matches stuck as in_progress** — if the resolve pipeline misses its window or game log isn't discovered, match never completes
6. **Game results never picked up** — binary game log exists but `PollGameLogs` doesn't find it or parse it in time
7. **Band-aid classes** — `ResolveStaleMatches`, `ResolvePendingResults` exist to paper over pipeline timing bugs rather than fixing the root cause

---

## New Architecture

### Core Principle

Collapse everything into a **single scheduled command** (`mtgo:process-matches`) that owns the entire lifecycle from log reading through match completion. Linear, sequential, no async dispatch. If a match can't complete through the normal pipeline, it's a bug — the only deliberate fallback is the cursor-reset match history path, triggered by an explicit signal (new MTGO session).

### Command Structure

```
[Scheduler tick, every 2s] → mtgo:process-matches (withoutOverlapping)

    Phase 0 — Discover game logs:
        1. Scan MTGO data directory for new game log files
        2. Extract match token from filename, create GameLog records
        (Quick filesystem scan, keeps everything in one process)

    Phase 1 — Ingest (single transaction):
        1. Read main log from cursor
        2. Classify events
        3. Persist LogEvents
        4. Advance cursor
        (Note: IngestLog already wraps cursor + events in DB::transaction —
         this preserves existing behaviour)

    Phase 2 — Process matches (per-match transactions):
        For each match with unprocessed events:
            1. Resolve username from log events, verify tracked account
            2. Advance match state (create match, progress through states)
            3. Append new game_state_update events to game timelines
            4. Look up game log by match token (fallback: run discovery inline, log it)
            5. If game log exists → parse binary, sync game results
            6. If decisive result → determine outcome → Complete
            7. On completion → dispatch archetype detection job (background)
            8. Mark all LogEvents for this match token as processed (set processed_at)

        For each InProgress/Ended match NOT already processed above:
            1. Check game log for results (parsed fresh each tick)
            2. If decisive → Complete
```

### Username Resolution

The command must resolve the local player's username per match token before processing. The existing `BuildMatches` logic looks up the username from log events and checks if the account is tracked via `Mtgo::setUsername()`. This is preserved in Phase 2 step 1.

---

## State Machine

### States

```
Started → InProgress → Ended → Complete
              ↓                    ↑
              └────────────────────┘
         (game log decisive = skip Ended)
```

4 states, linear. No Voided, no PendingResult.

### Transitions

| Transition | Trigger |
|---|---|
| **Started → InProgress** | `game_state_update` events arrive. Games created, deck linked (`DetermineMatchDeck`), league assigned (`AssignLeague`). Emits `DeckLinkedToMatch`, `LeagueMatchStarted` events. |
| **InProgress → Ended** | Match-end signal in main log (state change contexts: `TournamentMatchClosedState`, `MatchCompletedState`, `MatchEndedState`, `MatchClosedState`, `JoinedCompletedState`) or concession detected. |
| **Ended → Complete** | Game log binary provides decisive per-game results. Outcome determined via `DetermineMatchResult`. |
| **InProgress → Complete** | Game log binary provides decisive result before main log emits end signal. Game log results are authoritative — they can drive completion from InProgress. |

### Removed States

- **PendingResult** — eliminated. No grace periods. Game log is checked every tick until it resolves, or cursor reset triggers match history fallback.
- **Voided** — eliminated. Matches cannot be void. Users can delete matches manually if needed.

---

## Transaction Boundaries

### Phase 1: Ingest

Single transaction wrapping the entire ingest step:
- Read main log from cursor position
- Classify all new events via `ClassifyLogEvent`
- Batch-insert `LogEvent` rows
- Advance cursor

If anything fails, the transaction rolls back and the cursor stays put. Next tick retries from the same position. Classification is idempotent. Note: `IngestLog` already wraps this in `DB::transaction()` — this is preserving existing behaviour, not a new design.

### Phase 2: Process Matches

Each match gets its own transaction. If match A throws, match B still processes. A broken match does not block the pipeline.

Failed matches are logged and retried on subsequent ticks (events remain unprocessed). After 5 failed attempts, the match is flagged with `failed_at` timestamp and the pipeline skips it on future ticks.

---

## Game Log Integration

### Lookup strategy

Game logs are associated with matches via the `match_token` column on the `game_logs` table. Phase 0 of the command (`DiscoverGameLogs` — extracted from the existing `PollGameLogs`) pre-populates this table each tick by scanning the MTGO data directory for `*Match_GameLog*` files and extracting the token from the filename (`Match_GameLog_{token}.dat`). This runs inside the same command, not on a separate schedule.

### Pipeline behaviour

- Game log binary is checked **every tick** for any match in InProgress or Ended state
- **Primary lookup**: query `GameLog` by match token
- **Fallback**: if no `GameLog` record exists, run the discovery function inline to find it. Log when this happens — it should be rare and indicates the background routine missed it
- Parsed fresh every tick — no mtime caching, no cursors on game logs
- If game log still not found, skip — next tick picks it up (natural retry)
- Game log results are **authoritative**: if they show a decisive result, match goes to Complete regardless of whether it's InProgress or Ended
- Per-game results synced to `Game.won` field

### GameLog model

The `GameLog` model is **kept** but simplified. It stores the file path and match token for lookup. The `decoded_entries` and `byte_offset` columns are no longer used for incremental parsing (we parse fresh each tick), but `decoded_entries` may still be useful for caching the parsed result within a tick to avoid re-parsing if multiple consumers need it. Implementation should determine whether to keep or drop these columns.

---

## Timeline Population

- Game timelines are built from main log `game_state_update` events
- Appended **every tick** as new events arrive — not gated to a specific state transition
- Powers the real-time overlay (shows cards as they're played)
- Game log binary is used for results only, not timeline data

---

## Deck Linking & League Assignment

- Happens at **Started → InProgress** transition
- `DetermineMatchDeck` — links deck version to match (separate action class)
- `AssignLeague` — assigns match to league (separate action class)
- Both emit events: `DeckLinkedToMatch`, `LeagueMatchStarted`
- Each action is independently testable with single responsibility
- Note: `AdvanceMatchState` currently calls `SyncDecks::dispatchSync()` as a fallback when no deck is found. This is a synchronous filesystem scan + XML parse that could be slow. The new pipeline preserves this for now but it should be monitored — if it blocks the tick noticeably, it can be moved to a pre-pipeline step or background job in a future iteration.

---

## Archetype Detection

- Dispatched as a **background job** when a match transitions to Complete
- Dispatched from the pipeline inline
- Uses an API call that can fail/timeout — must not block the pipeline
- If it fails, the match is still Complete — archetype is enrichment, not critical
- Can be retried independently

---

## Match Completion Side Effects

When a match transitions to Complete, the following must happen. The existing `MtgoMatchObserver::updated()` currently handles these — they are preserved:

1. **Archetype detection** — dispatched as background job (see above)
2. **Match submission** — submits match stats to API
3. **Card game stats** — computes per-card win rate statistics
4. **App notification** — notifies the user of match result
5. **League completion check** — checks if the league run is complete

The pipeline dispatches the archetype detection job directly. The remaining side effects (submission, card stats, notification, league check) continue to fire via the existing model observer. The observer is not removed or gutted — it handles enrichment concerns that are not the pipeline's responsibility.

---

## LogEvent Processed Marking

### Problem

In the current system, `AdvanceMatchState` marks events as processed before the transaction commits, and only marks events it directly queried. Events can slip through — particularly state change events and events for matches that stay in the same state across ticks. This leads to the same match token appearing in the "unprocessed events" query every tick, causing continuous reprocessing and slowdown.

### New behaviour

**All LogEvents for a match token are marked `processed_at` at the end of each per-match transaction in Phase 2** (step 8 in the command structure). This happens regardless of what state the match reached — whether it advanced, stayed the same, or even if processing only checked the game log. Once a tick has processed a match's events, those events are done.

This is critical for pipeline performance: without it, the "matches with unprocessed events" query grows unboundedly as events accumulate for InProgress/Ended matches that haven't completed yet.

### Edge case: failed transactions

If a per-match transaction rolls back (exception), the `processed_at` update also rolls back. This is correct — the events remain unprocessed and will be retried next tick. The `attempts` counter (tracked outside the transaction) increments to enforce the 5-attempt limit.

---

## Error Handling

### Per-match failure isolation

Each match processes in its own transaction. A failure in one match does not affect others. Failed matches are retried on subsequent ticks.

### Retry semantics

- Each match tracks an `attempts` counter (integer, default 0)
- **Only incremented on exceptions** — not on "game log not found" or "no decisive result yet". Those are normal states, not failures.
- After **5 failed attempts** (actual exceptions), the match is flagged with a `failed_at` timestamp
- Pipeline skips matches where `failed_at` is set
- Recovery: null out `failed_at` and reset `attempts` to re-enter the match into the pipeline (manual action, could be a diagnostics page button)

### Phase 1 failures

If ingest fails, the cursor doesn't advance. Next tick retries from the same position. No data loss.

### Corrupt game logs

Logged as an error, counted as a failed attempt for that match. If the binary is genuinely unreadable after 5 attempts, match gets `failed_at`.

### Philosophy

The pipeline does not silently recover from failures. There are no timed reconciliation sweeps or self-healing classes. If a match can't complete through normal processing, the `failed_at` flag and error logs make it visible for investigation. The **only** deliberate fallback is the cursor-reset match history path — triggered by an explicit signal (new MTGO session), not a periodic sweep.

---

## Cursor Reset — Match History Fallback

### Trigger

`LogCursor` model observer detects a cursor reset (log file shrank or rotated = new MTGO session started). Detection logic: on the `saving` or `updated` event, check `$cursor->getOriginal('byte_offset') > $cursor->byte_offset` — i.e., the offset went backwards. A normal cursor advance (offset increases) does not trigger the fallback.

### Behaviour

When cursor resets:
1. Find all incomplete matches (any state except Complete): Started, InProgress, or Ended
2. Check match history file for each
3. **Started matches with no games**: match history may have a result — if so, set outcome and transition to Complete. If not in match history, these are likely noise (match joined but never played) and are left as-is for the user to delete if desired.
4. **InProgress/Ended matches**: match history provides a W-L record (e.g. "2-1") — this is the definitive last resort
5. For existing `Game` records where `won` is null: backfill `won` based on the W-L (best guess, assigned in game order by `started_at`)
6. Do **not** create missing Game records — we won't have deck data, players, or timeline for fabricated games
7. Set outcome on the match from the W-L directly
8. Transition to Complete

### Not a reconciliation sweep

This is triggered by a specific, meaningful signal (cursor reset = new MTGO session), not a timed sweep looking for "stale" data. It handles the legitimate case where MTGO crashed or the user force-quit before game logs were written.

---

## Schema Changes

### New columns on `matches` table

| Column | Type | Purpose |
|---|---|---|
| `failed_at` | nullable timestamp | Set after 5 failed processing attempts. Pipeline skips these. |
| `attempts` | integer, default 0 | Tracks failed processing attempts (exceptions only). |

### Removed states from `MatchState` enum

- `PendingResult` — removed
- `Voided` — removed

### Migration for existing data

- Matches in `PendingResult` → update to `Ended`
- Matches in `Voided` → delete

### Ripple effects of removed states

The following files reference `Voided` or `PendingResult` and must be updated:
- `app/Enums/MatchState.php` — remove cases
- `app/Models/MtgoMatch.php` — `scopeIncomplete()` references Voided
- `app/Actions/Matches/AdvanceMatchState.php` — transitions to removed states
- `app/Http/Controllers/` — any controllers referencing these states (DeleteController, ResetController, OverlayController)
- `app/Jobs/PruneProcessedLogEvents.php` — references Voided
- All tests referencing these states

---

## Migration Path

**Big-bang replacement.** No feature flags, no parallel running.

### Delete

- `app/Actions/Matches/ResolveStaleMatches.php`
- `app/Actions/Matches/ResolvePendingResults.php`
- `app/Actions/Matches/ResolveGameResults.php`
- `app/Jobs/PollGameLogs.php` (discovery logic extracted to reusable action first)

### Create

- `app/Console/Commands/ProcessMatches.php` — the single unified command
- `app/Actions/Matches/DiscoverGameLogs.php` — extracted from PollGameLogs, reusable for inline fallback
- `LogCursor` model observer — triggers match history fallback on cursor reset

### Modify

- `app/Enums/MatchState.php` — remove `PendingResult`, `Voided`
- `app/Managers/MtgoManager.php` — replace both pipelines with single `mtgo:process-matches` command
- `app/Actions/Matches/AdvanceMatchState.php` — remove transitions to removed states
- `app/Models/MtgoMatch.php` — add `failed_at`, `attempts` to casts; update scopes
- All files referencing removed states (see ripple effects above)

---

## Testing Strategy

1. **Unit tests per action** — each action class tested independently with fixture data (existing pattern)
2. **Integration test for full command** — fixture log file + fixture binary game log → run command → assert matches progress Start → Complete end-to-end
3. **Cursor reset test** — simulate cursor reset, verify all incomplete matches (Started/InProgress/Ended) get resolved via match history fixture
4. **Failure handling test** — feed a match that throws, verify 5 retries then `failed_at` set. Verify "game log not found" does NOT increment attempts.
5. **Idempotency tests** — run the command twice with same input, assert same result
6. **Game log authority test** — verify game log can drive InProgress → Complete (skipping Ended)
7. **Timeline append test** — verify new game_state_update events append to timeline each tick
8. **Game log discovery fallback test** — verify inline discovery runs and logs when GameLog record is missing
9. **Username resolution test** — verify pipeline correctly identifies and filters by tracked accounts

---

## Files Kept (Reused in New Pipeline)

| File | Role |
|---|---|
| `app/Actions/Logs/IngestLog.php` | Main log reading, cursor management |
| `app/Actions/Logs/ClassifyLogEvent.php` | Event classification |
| `app/Actions/Matches/BuildMatches.php` | Find unprocessed events, dispatch to AdvanceMatchState |
| `app/Actions/Matches/AdvanceMatchState.php` | Match state machine (modified) |
| `app/Actions/Matches/CreateOrUpdateGames.php` | Game record creation |
| `app/Actions/Matches/ParseGameLogBinary.php` | Binary .dat parser |
| `app/Actions/Matches/ExtractGameResults.php` | Per-game result extraction |
| `app/Actions/Matches/DetermineMatchResult.php` | Win/loss determination |
| `app/Actions/Matches/DetermineMatchDeck.php` | Deck linking |
| `app/Actions/Matches/AssignLeague.php` | League assignment |
| `app/Models/GameLog.php` | Game log lookup by match token (simplified usage) |
| `app/Observers/MtgoMatchObserver.php` | Completion side effects (submission, card stats, notification, league check) |
