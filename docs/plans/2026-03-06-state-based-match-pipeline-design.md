# State-Based Match Pipeline

Version: 0.2.0
Status: **Approved design**

## Overview

Refactor the match pipeline from a one-shot "build when complete" model to incremental state advancement. Matches progress through states as log events arrive, enabling near-realtime awareness of in-progress matches.

This is a pipeline-only change. No UI changes — existing pages continue to show only complete matches.

## Match States

```
[gate] → started → in_progress → ended → complete
```

| State | Meaning |
|-------|---------|
| `started` | Join event confirmed (it's our match), match record created |
| `in_progress` | Games being played, deck linked |
| `ended` | Match end signals detected, result not yet fully determined |
| `complete` | Result determined, archetypes resolved, ready for reporting |

States can be skipped. If all events are available on first processing (e.g. user was AFK), a match can go `started → complete` in one pass.

## Gate: Match Creation

MTGO logs contain match tokens for matches the local player is not part of (spectated matches, other players' events). These must not create match records.

- A match record is only created when `MatchJoinedEventUnderwayState` is found for the token
- No join event → skip the token, try again next cycle (the join event may arrive in a later ingestion)
- Events for tokens that never produce a join event are eventually cleaned up

## State Advancement Logic

### started → in_progress

**Trigger:** `game_state_update` events found for this match

**Actions:**
- Create Game records from game state events
- Link deck via `DetermineMatchDeck` (deck_used event available from game 1)
- Assign league (real or phantom)

Deck linking happens here (not at completion) so downstream features like the league overlay can show the deck name immediately.

### in_progress → ended

**Trigger:** Match end signal detected in state change events:
- `TournamentMatchClosedState`
- `MatchCompletedState`
- `MatchEndedState`
- `MatchClosedState`
- Concede sequence: `MatchConcedeReqState` → `MatchNotJoinedEventUnderwayState` + `MatchCompleted`

**Actions:**
- Mark match as ended (no game mutations yet — just state tracking)

### ended → complete

**Trigger:** All data available to determine result

**Actions:**
- Parse game results from game log (GetGameLog)
- Determine win/loss counts with concede/disconnect fallback
- Resolve archetypes via DetermineMatchArchetypes
- Send desktop notification
- Dispatch SubmitMatch job
- Mark related LogEvents as processed

## Pipeline Architecture

### Event-Driven Processing

Current: `ProcessLogEvents` runs on a 30-second polling schedule.

New: Event-driven with a fallback.

```
IngestLog (every 1s)
  → inserts new LogEvent rows
  → fires LogEventsIngested event (only when rows were inserted)
        ↓
LogEventsIngested listener
  → dispatches ProcessLogEvents job (ShouldBeUnique — deduplicated on queue)
        ↓
ProcessLogEvents job
  1. Find unprocessed match tokens with join events → create matches (started)
  2. Find all incomplete matches (started / in_progress / ended)
  3. For each, evaluate available events and advance state

Fallback: scheduled ProcessLogEvents every 60s (safety net)
```

This reduces match detection latency from ~30s to ~1-2s.

### ProcessLogEvents Job

The job handles both concerns in one pass:
1. **New match detection** — query unprocessed events with match tokens, check for join events, create match records
2. **State advancement** — query matches not in `complete` state, run AdvanceMatchState on each

The job implements `ShouldBeUnique` so rapid event dispatches are deduplicated.

## Key Refactors

| Current | New |
|---------|-----|
| `BuildMatch` action (one-shot) | `AdvanceMatchState` action (idempotent, incremental) |
| `BuildMatches` action (find unprocessed only) | Updated to also find incomplete matches |
| `ProcessLogEvents` (30s schedule) | Event-driven (unique job) + 60s fallback |
| No state column | `state` enum column on matches table |
| Deck linked at completion | Deck linked at `in_progress` |

### AdvanceMatchState

Replaces `BuildMatch`. Must be:
- **Idempotent** — same input twice produces same result, no duplicate records
- **Incremental** — evaluates current state, advances if conditions met
- **Safe for existing records** — handles matches that already have games/deck without duplicating

## Database Changes

### New migration: add state to matches

```sql
ALTER TABLE matches ADD COLUMN state VARCHAR DEFAULT 'complete';
```

Default is `complete` so all existing matches are treated as complete (backward compatible).

### New enum: MatchState

```php
enum MatchState: string
{
    case Started = 'started';
    case InProgress = 'in_progress';
    case Ended = 'ended';
    case Complete = 'complete';
}
```

## Consumer Protection

All existing queries that read match data must filter to `complete` only.

### New scope on MtgoMatch

```php
public function scopeComplete($query)
{
    return $query->where('state', MatchState::Complete);
}
```

### Queries to update

| File | What to add |
|------|-------------|
| `IndexController` (dashboard) | `->complete()` on all match queries |
| `Decks/IndexController` | Filter via relationship (Deck::wonMatches, lostMatches, matches) |
| `Decks/ShowController` | `->complete()` on match queries + version stats |
| `Leagues/IndexController` | `WHERE state = 'complete'` on raw DB queries |
| `Opponents/IndexController` | `WHERE state = 'complete'` on raw DB queries |
| `Matches/ShowController` | Allow viewing any state (detail page) |
| `GetArchetypeMatchupSpread` | `WHERE state = 'complete'` on raw DB query |
| `SyncMatchArchetypes` | `->complete()` |
| `HandleInertiaRequests` | `submittable()` scope already sufficient |
| `Deck` model relationships | Add `->where('state', 'complete')` to wonMatches/lostMatches |
| `MtgoMatch::scopeSubmittable()` | Add `->where('state', 'complete')` |

## Schedule Changes

```php
// Remove:
$schedule->call(fn () => $this->processLogEvents())
    ->everyThirtySeconds()
    ->name('process_log_events')
    ->withoutOverlapping(30);

// Add (fallback only):
$schedule->call(fn () => $this->processLogEvents())
    ->everyMinute()
    ->name('process_log_events_fallback')
    ->withoutOverlapping(60);
```

## What Doesn't Change

- Log ingestion (IngestLog, ClassifyLogEvent) — only change is firing event after insert
- Game log parsing (GetGameLog)
- DetermineMatchDeck, DetermineMatchArchetypes — still called, just at different state transitions
- Frontend pages — same data, filtered to complete
- SubmitMatch — still dispatched on completion
- Notification — still sent on completion

## Testing Strategy

- Test each state transition in isolation
- Test idempotency — advancing a match already in the target state is a no-op
- Test skip-ahead — all events available at once goes straight to complete
- Test partial data — match with join event but no end signal stays in_progress
- Test foreign match tokens — no join event = no match record created
- Test backward compatibility — existing matches with state=complete behave identically

## Dependencies

None. This is a standalone pipeline refactor.

## Enables (future work)

- [League overlay window](2026-03-06-league-overlay-design.md) — knows match is in progress + deck name before completion
- Current match page — display live match data
- Live deck tracking — cards drawn, library contents, draw percentages
