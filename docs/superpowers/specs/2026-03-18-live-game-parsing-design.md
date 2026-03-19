# Live Game Result Parsing — Design Spec

## Problem

Game results (`Game.won`) are only populated at match completion (`tryAdvanceToComplete` → `SyncGameResults`). The overlay already reads `Game.won` for the current match's games, but sees `null` until the entire match is over. In a 3-game match, the user can't see game 1's result while game 2 is being played.

## Goal

Update `Game.won` as each game completes during a live match, so the overlay shows results in real-time without waiting for match completion.

## Design

### Hook Point

In `AdvanceMatchState::run()` at line 116-118, after `createOrUpdateGames` runs for `InProgress`/`Ended` matches, add a call to sync live game results:

```php
if ($match->state === MatchState::InProgress || $match->state === MatchState::Ended) {
    self::createOrUpdateGames($match, $events);
    self::syncLiveGameResults($match);  // NEW
}
```

This runs on every `AdvanceMatchState` cycle while the match is active — each time new log events arrive, we check if any games have completed.

### New Method: `syncLiveGameResults`

```
syncLiveGameResults(MtgoMatch $match)
  1. Call GetGameLog::run($match->token)
     → incrementally parses .dat file, stores new entries
     → returns results array (per-game booleans)
  2. Get match's Game records ordered by started_at
  3. For each game where won IS NULL:
     → check if results[$gameIndex] exists
     → if yes, update Game.won
```

### What Changes

| Component | Change |
|-----------|--------|
| `AdvanceMatchState::run()` | Add `syncLiveGameResults` call after `createOrUpdateGames` |
| `AdvanceMatchState` | Add private `syncLiveGameResults` method |

### What Stays the Same

- `tryAdvanceToComplete` → `SyncGameResults` still runs at match completion as final reconciliation
- `GetGameLog::run()` unchanged — already supports incremental parsing
- `OverlayController` unchanged — already reads `Game.won`
- `ExtractGameResults` unchanged
- `DetermineMatchResult` unchanged
- All existing return formats preserved

### Edge Cases

- **No game log file yet:** `GetGameLog::run()` returns null — `syncLiveGameResults` exits early, no harm done
- **Game created but no result yet:** `results[$gameIndex]` doesn't exist — game stays with `won = null`, overlay shows it as in-progress
- **Multiple cycles with same result:** `Game.won` is already set — skip update (idempotent)
- **Game log has fewer entries than games:** Normal during live play — only games with results get updated

### Testing

- Test that `syncLiveGameResults` updates `Game.won` when game log has results
- Test that games without results in the log are left as `won = null`
- Test idempotency — calling twice with same data doesn't cause issues
