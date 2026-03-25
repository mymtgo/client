# Pipeline Redesign: Independent Subsystems

**Date:** 2026-03-23
**Branch:** `next` (from `main`)
**Status:** Draft

## Problem

The event-driven pipeline attempted on `0.9.0` and `pipeline-simplification` branches tried to unify all match processing into a single event dispatch + listener cascade. This created:

- Hidden listener ordering dependencies (11 auto-discovered listeners with implicit sequencing)
- Silent failures (exceptions logged but not propagated, no retry)
- Timing races (metadata arrives in different events than match creation)
- NativePHP IPC never worked (real-time file watcher silently fails)
- Complexity shifted from "advance state" to "coordinate listeners"

The original monolithic `AdvanceMatchState` had explicit sequential control flow but was hard to extend.

## Design Principle

**Different concerns have different timing and reliability requirements. Treat them as independent subsystems, not phases in one pipeline.**

- `log_events` for text log stream data, `game_logs.decoded_entries` for game log snapshots
- Independent polling loops per subsystem
- Each subsystem owns its own error handling and cadence
- No events for critical-path work; observer for non-critical enrichment

## Architecture Overview

```
┌──────────────────────────────────────────────────────────────┐
│                       INGEST LAYER                            │
│                                                               │
│  mtgo.log poll (2s)              Match_GameLog_*.dat poll (2s)│
│       ↓                                    ↓                  │
│  ClassifyLogEvent                 ParseGameLogBinary          │
│       ↓                                    ↓                  │
│  log_events table              game_logs.decoded_entries      │
│  (append-only stream)          (full JSON snapshot per match) │
│                                                               │
│  Cursor: LogCursor             Cursor: GameLog.byte_offset   │
└──────────────────────────────────────────────────────────────┘
                          ↓
┌──────────────────────────────────────────────────────────────┐
│                     CONSUMER LAYER                            │
│                                                               │
│  ┌──────────────────┐  ┌──────────────────────────────────┐  │
│  │ Match             │  │ Game Result Resolution (2s)      │  │
│  │ Construction (2s) │  │                                  │  │
│  │                   │  │ Reads game_logs.decoded_entries   │  │
│  │ Reads log_events  │  │ → ExtractGameResults (as-is)     │  │
│  │ → Create match    │  │ → Update Game.won progressively  │  │
│  │ → Link deck       │  │ → DetermineMatchResult           │  │
│  │ → Assign league   │  │ → Cross-check match score        │  │
│  │ → State machine   │  │ → Set outcome when decided       │  │
│  │ (→ Started        │  │ → Mark pending_result if         │  │
│  │  → InProgress     │  │   incomplete                     │  │
│  │  → Ended)         │  │                                  │  │
│  └──────────────────┘  └──────────────────────────────────┘  │
│                                                               │
│  ┌──────────────────────┐  ┌──────────────────────────────┐  │
│  │ Pending Result        │  │ Enrichment                   │  │
│  │ Fallback (30s)        │  │ (MtgoMatchObserver)          │  │
│  │                       │  │                              │  │
│  │ Cross-check           │  │ Archetype detection          │  │
│  │ match_history file    │  │ UI notifications             │  │
│  │ for unresolved        │  │ Match submission             │  │
│  │ matches               │  │ Card stats                   │  │
│  │                       │  │                              │  │
│  │                       │  │ Failure-tolerant             │  │
│  └──────────────────────┘  └──────────────────────────────┘  │
└──────────────────────────────────────────────────────────────┘
```

## Subsystem Details

### 1. Text Log Ingestion (exists, proven)

**What:** Polls `mtgo.log`, classifies lines, writes to `log_events`.
**Cadence:** Every 2 seconds.
**Cursor:** `LogCursor` (byte offset per file).
**Changes needed:** Remove the `LogEventsIngested` event dispatch from `IngestLog.php` (the event and its listener are being removed).

**Key files:**
- `app/Actions/Logs/IngestLog.php`
- `app/Actions/Logs/ClassifyLogEvent.php`
- `app/Models/LogCursor.php`

### 2. Game Log Polling (new — full JSON snapshot)

**What:** Polls `Match_GameLog_*.dat` binary files for active matches, re-parses the full file, updates `GameLog.decoded_entries` with the complete JSON snapshot.
**Cadence:** Every 2 seconds.
**Data store:** `game_logs.decoded_entries` (JSON column on existing `GameLog` model).

Game log .dat files are small and finite (one per match). Rather than incrementally appending individual entries to `log_events`, we re-parse the entire file on each poll cycle. This ensures we always have the full picture — including context like "Match tied 1-1" that only makes sense when reading the complete log.

**Flow:**

```
For each match in [InProgress, Ended] with a GameLog record:
  1. Read the full .dat file via ParseGameLogBinary (proven, keep as-is)
  2. Replace GameLog.decoded_entries with the full parsed JSON
  3. Update GameLog.byte_offset to track file size (for change detection)
```

**GameLog record lifecycle:**
- `PollGameLogs` discovers `.dat` files and creates `GameLog` records (see "GameLog record creation" below)
- The .dat file may not exist when a match first starts — the poller handles this gracefully (no record created until file appears)

**Why re-parse the full file instead of incremental:**
- Game logs are small (a few KB, never megabytes) — re-parsing is cheap
- Avoids context loss — messages like "Match tied 1-1" need surrounding context to interpret
- Avoids the complexity of classifying individual messages into typed event rows
- `ExtractGameResults` already works perfectly with the full decoded entries array
- No schema changes needed to `log_events` — game log data stays in its own table
- No sentinel values, no dedup constraints, no column mapping gymnastics

**Change detection:** `byte_offset` stores the file size after each parse. On the next poll, compare current file size to `byte_offset` — if unchanged, skip. If the file has grown (or appeared), do a full re-parse and replace `decoded_entries` entirely. The `byte_offset` is NOT an incremental cursor here — it's purely a change-detection flag.

**GameLog record creation:** `PollGameLogs` owns the creation of `GameLog` records. It discovers `.dat` files for matches in `[Started, InProgress, Ended]` that don't yet have a `GameLog` record. File discovery uses the match token to find `Match_GameLog_{token}.dat` across candidate directories (per `docs/system.md`). This keeps filesystem concerns out of Match Construction.

**`GameLog.decoded_entries` is now dual-purpose:** working data during match progression AND display data after completion. No separate snapshot step needed.

### 3. Match Construction (refactor of AdvanceMatchState)

**What:** Reads text-log `log_events` for match lifecycle signals. Creates matches, links decks, assigns leagues, manages state transitions up to `Ended`.
**Cadence:** Every 2 seconds.
**Reads:** `log_events` where `event_type` in match lifecycle types and `processed_at IS NULL`.

This is a refactor of the existing `BuildMatches` → `AdvanceMatchState` chain. The logic stays sequential and deterministic, but with clearer boundaries:

**State transitions owned by this subsystem:**
- `→ Started` — join event detected, match created
- `Started → InProgress` — game_state_update events exist, games created, deck linked, league assigned
- `InProgress → Ended` — match end signal detected (TournamentMatchClosedState, MatchCompletedState, concede, etc.)

**NOT owned by this subsystem:**
- `Ended → Complete` — owned by Game Result Resolution
- `Ended → PendingResult` — owned by Game Result Resolution
- `PendingResult → Complete` — owned by Pending Result Fallback

**Refactoring approach:**
- Keep `AdvanceMatchState` but strip out `tryAdvanceToComplete` (move to Game Result Resolution)
- Keep `BuildMatches` as the scheduler entry point
- Keep `CreateOrUpdateGames`, `DetermineMatchDeck`, `AssignLeague` as-is
- Keep `DeckLinkedToMatch` and `LeagueMatchStarted` events — these are non-critical side effects that drive UI features (deck popout window, league notifications)

**Stale match handling:** `ResolveStaleMatches` (called from `BuildMatches::run()`) stays as-is. It voids casual matches and ends incomplete league matches when a newer match exists. This is distinct from `PendingResult` — stale matches have no activity at all, while `PendingResult` matches ended normally but lack result data.

### 4. Game Result Resolution (new subsystem)

**What:** Reads `GameLog.decoded_entries` for matches in `InProgress` or `Ended` state. Progressively resolves game results and match outcomes.
**Cadence:** Every 2 seconds.
**Reads:** `game_logs.decoded_entries` for active matches.

**New action: `ResolveGameResults`**

```
For each match in [InProgress, Ended] with a GameLog that has decoded_entries:

  1. Run ExtractGameResults(gameLog.decoded_entries, localPlayer)
     → Returns per-game results, match_score, on_play, starting_hands
     → Already handles game boundary detection, win/concede/disconnect
     → Already cross-checks counted results against MTGO's "leads the match X-Y"

  2. Update Game.won for each game based on extracted results

  3. If match state is Ended:
     → Run DetermineMatchResult with extracted results + state change log_events
     → A result is "decided" if ANY of:
       (a) Win threshold reached (2 wins or 2 losses for BO3)
       (b) Concession detected in state change log_events
       (c) Disconnect detected in game log
       (d) MTGO's match score line exists (authoritative)
     → If decided:
       → Set games_won, games_lost, outcome, AND state = Complete atomically
     → If NOT decided after 2 minutes past match.ended_at:
       → Advance to PendingResult
```

**Progressive resolution during InProgress:**
- Each poll cycle re-parses the .dat file (Subsystem 2) and re-runs `ExtractGameResults`
- As games complete, `Game.won` is updated on the next cycle
- This gives near-real-time game results (within 2s of the .dat file being written)
- Match outcome is only determined after the match reaches `Ended`
- Matches with no `GameLog` or empty `decoded_entries` are skipped (no-op)

**Key advantage:** `ExtractGameResults` already exists, is proven, and handles all the edge cases — game boundary detection, "Match tied 1-1", concessions, disconnects, match score cross-checks. We reuse it directly with no changes.

**Reworked `DetermineMatchResult`:**
- Currently takes `array $logResults` and `Collection $stateChanges` as arguments
- Rework to accept a match, read results from `ExtractGameResults` output, and query state change `log_events` itself
- **Critical change:** Stop inflating results to the win threshold. The current code fills wins/losses to 2 (or 3) on concession/disconnect — e.g., a 1-0 concede becomes 2-0. This is wrong. If MTGO reports 1-0, store 1-0. The `MatchOutcome` enum (Win/Loss) captures the meaning; the raw game counts should reflect what actually happened.
- **Completeness is separate from threshold:** A match result is "decided" when any of: (a) win threshold reached, (b) concession detected, (c) disconnect detected, (d) MTGO match score line present. The threshold is ONE way to determine completeness, not the only way.
- `games_won` and `games_lost` columns on matches are kept and populated with the real (non-inflated) counts
- Returns `array{wins: int, losses: int, decided: bool}` — adds a `decided` flag so the caller knows whether to advance to Complete or wait

**Atomic completion:** When transitioning to `Complete`, `games_won`, `games_lost`, `outcome`, and `state` MUST be set in a single `$match->update([...])` call. This ensures the `MtgoMatchObserver` sees all values when it fires.

### 5. Pending Result Fallback (new subsystem)

**What:** Cross-checks `match_history` file for matches stuck in `PendingResult`.
**Cadence:** Every 30 seconds.
**Reads:** `match_history` file (MTGO's own summary file).

**New actions: `ResolvePendingResults` + `ParseMatchHistory`**

The `match_history` file format is not yet documented and no parser exists in the codebase. `ParseMatchHistory` is new work — it needs to discover the file, parse its format, and extract match results by token/ID. If the file format turns out to be undocumented or unreliable, this subsystem degrades gracefully (matches stay in `PendingResult` for manual resolution).

```
For each match in PendingResult:
  1. ParseMatchHistory: find and parse the match_history file
  2. If match found: extract result, set games_won, games_lost, outcome, state = Complete atomically
  3. If not found or file missing: leave as PendingResult (user can manually resolve)
```

**New match state: `PendingResult`**
- Added to `MatchState` enum between `Ended` and `Complete`
- Means: "match ended but game results are incomplete from the game log"
- UI can display these with a "result pending" indicator
- Grace period: 2 minutes after `match.ended_at` before transitioning from `Ended`

### 6. Enrichment (model observer, non-critical)

**What:** Archetype detection, match submission, card stats, UI notifications.
**Trigger:** `MtgoMatchObserver` watches for state changing to `Complete` on save.
**Failure:** Each action wrapped in try/catch — logged, does not affect match state.

**New: `MtgoMatchObserver`**

```php
// In the `updated` method:
if ($match->isDirty('state') && $match->state === MatchState::Complete) {
    // Each is independent; failure in one doesn't block others
    try { DetermineMatchArchetypes::run($match); } catch (\Throwable $e) { Log::warning(...); }
    try { SubmitMatch::dispatch($match->id); } catch (\Throwable $e) { Log::warning(...); }
    try { ComputeCardGameStats::dispatch($match->id); } catch (\Throwable $e) { Log::warning(...); }

    // Notification
    $won = $match->outcome === MatchOutcome::Win;
    $opponent = $match->opponentArchetypes()->with('archetype')->first()?->archetype?->name ?? 'Unknown';
    AppNotification::dispatch(...);

    // League completion check
    if (($league = $match->league) && $league->state === LeagueState::Active
        && $league->matches()->where('state', MatchState::Complete)->count() >= 5) {
        $league->update(['state' => LeagueState::Complete]);
    }
}
```

**No pipeline events in the system.** The observer is the only hook for post-completion work. `DeckLinkedToMatch` and `LeagueMatchStarted` events survive in Match Construction as they drive UI features (deck popout, league notifications), not pipeline logic.

## New MatchState Enum

```php
enum MatchState: string
{
    case Started = 'started';
    case InProgress = 'in_progress';
    case Ended = 'ended';
    case PendingResult = 'pending_result';  // NEW
    case Complete = 'complete';
    case Voided = 'voided';
}
```

## New MatchOutcome Enum (from 0.9.0)

```php
enum MatchOutcome: string
{
    case Win = 'win';
    case Loss = 'loss';
    case Draw = 'draw';
    case Unknown = 'unknown';
}
```

Stored in `matches.outcome` column (new migration needed).

## Scheduler Configuration

```php
// routes/console.php
Schedule::call(fn () => IngestLogs::dispatch())->everyTwoSeconds();
Schedule::call(fn () => PollGameLogs::dispatch())->everyTwoSeconds();
Schedule::call(fn () => BuildMatches::run())->everyTwoSeconds();
Schedule::call(fn () => ResolveGameResults::run())->everyTwoSeconds();
Schedule::call(fn () => ResolvePendingResults::run())->everyThirtySeconds();
```

Each is independent. If one fails, the others still run. Note: `BuildMatches` and `ResolveGameResults` both write to matches/games tables. Since this is SQLite (single-writer), they serialize on the write lock. In practice this is fine given small data volumes — if contention becomes an issue, stagger cadences.

## No Schema Changes to log_events

Game log data lives entirely in `game_logs.decoded_entries`. The `log_events` table is unchanged — it continues to store only text log data. No `source` column, no sentinel values, no column mapping needed.

## Backward Compatibility

Existing matches that completed under the old system already have `GameLog.decoded_entries` populated. These are unaffected. The only difference is that `decoded_entries` is now updated progressively during a match (not just at completion), but for already-Complete matches this code path is never entered.

## What Gets Cherry-Picked from 0.9.0

1. **Dashboard command center** — Index.vue rewrite, 8 dashboard actions, 6 partial components, controller updates
2. **MatchOutcome enum** — `app/Enums/MatchOutcome.php`
3. **Outcome migration** — `add_outcome_to_matches_table`
4. **Any improved ParseGameLogBinary** — if v2 exists on 0.9.0 with improvements

## What Gets Built New

1. **`PollGameLogs` job** — polls .dat files for active matches, re-parses full file, updates `decoded_entries`
2. **`ResolveGameResults` action** — progressive game result resolution using `ExtractGameResults`
3. **`ResolvePendingResults` + `ParseMatchHistory` actions** — match_history fallback (parser is new, file format needs investigation)
4. **`PendingResult` match state** — enum addition
5. **Reworked `DetermineMatchResult`** — accepts match, queries own data
6. **`MtgoMatchObserver`** — triggers enrichment when state reaches Complete
7. **Refactored `AdvanceMatchState`** — stripped of completion logic
8. **Scheduler rewiring** — independent loops in routes/console.php

## What Gets Removed

- `ProcessLogEvents` job (replaced by independent subsystems)
- `LogEventsIngested` event + `DispatchProcessLogEvents` listener (no longer needed)
- `GetGameLog` action (replaced by direct reads from `GameLog.decoded_entries`)
- `GetGameLogEntries` action (if it exists — same reason as `GetGameLog`)
- `SyncGameResults` action (game results synced progressively by `ResolveGameResults`)
- `tryAdvanceToComplete` from `AdvanceMatchState` (moved to `ResolveGameResults`)

## Migration Path

Since we're on a clean `next` branch from `main`:

1. Add migration for `outcome` column on matches table
2. Add `PendingResult` to MatchState enum (no migration needed — string-backed enum)
3. Implement `PollGameLogs` (re-parse .dat → decoded_entries)
4. Implement `ResolveGameResults` (progressive resolution using ExtractGameResults)
5. Refactor `AdvanceMatchState` (remove completion logic, add GameLog creation)
6. Implement `MtgoMatchObserver`
7. Implement `ResolvePendingResults` (match_history fallback)
8. Wire up scheduler
9. Cherry-pick dashboard from 0.9.0
10. Clean up dead code (removed actions/events/listeners)
11. Tests for each subsystem independently

## Testing Strategy

Each subsystem is independently testable:

- **PollGameLogs**: given a .dat file fixture, assert `decoded_entries` populated correctly; assert skips when file unchanged; assert handles missing files; assert creates GameLog record for new .dat files
- **Match Construction**: given log_events fixtures, assert match state transitions (Started → InProgress → Ended only)
- **ResolveGameResults**: given decoded_entries JSON, assert Game.won updates progressively; assert match score cross-check; assert atomic outcome + state + games_won/lost write; assert PendingResult after 2-min grace period
- **DetermineMatchResult**: assert no result inflation (1-0 concede stays 1-0); assert `decided=true` for concession, disconnect, match score line, and threshold; assert `decided=false` when no signal exists
- **ResolvePendingResults**: given a match_history fixture, assert pending matches get resolved
- **Enrichment**: assert MtgoMatchObserver fires enrichment actions when state changes to Complete

Idempotency tests: run each subsystem twice with same input, assert no duplicates or state corruption.
