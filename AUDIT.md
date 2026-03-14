# Codebase Audit — 2026-03-14

## Critical: Null Safety Gaps

### CreateGames.php:28
`$gameStateEvents->first()->raw_text` with no null check. If game state events are empty, fatal error. Same issue at lines 35-36 accessing `->logged_at` and `->timestamp`.

### DetermineMatchDeck.php:25
`$firstGameDeck->raw_text` with no null check. If no `deck_used` event exists for the first game, crash.

### DetermineMatchArchetypes.php:15
`$match->games->first()->localPlayers->first()` chains without null guards. If match has no games, fatal.

---

## High: Matches Stuck in Started State

`AdvanceMatchState` only prevents regression for Complete/Voided. If `game_state_update` events are missing or failed classification, Started -> InProgress silently returns false. No timeout or retry — match stays in Started until `ResolveStaleMatches` runs, which only triggers when a newer match exists.

---

## High: Silent Classification Failures

`ClassifyLogEvent.php:39-50` — if JSON extraction fails on a `game_management_json` event, the event returns with `event_type = null`. Silently dropped from all downstream queries. No logging, no way to detect data loss.

---

## High: Concede Detection is Fragile

`AdvanceMatchState.php:196-217` — uses substring matching and regex on state transition strings. Mixes `$events` and `$stateChanges` collections. Boolean logic with `||` overriding `&&` is error-prone.

---

## High: ResolveStaleMatches Can Mark Real Matches as Incomplete

If a user plays two matches in quick succession, the first may get marked stale prematurely. Also has N+1 query pattern and a dead variable (`$latestMatchStart` fetched but never used).

---

## Medium: processed_at Never Rolled Back

`AdvanceMatchState.php:286-291` marks all match events as `processed_at = now()` during Complete transition. If subsequent `SubmitMatch` job fails, events hidden from reprocessing forever.

---

## Medium: Game Log Files May Disappear

`GetGameLog.php:18-31` reads game log files from disk with `@file_get_contents`. If MTGO deletes the file between match completion and result parsing, returns null silently.

---

## Simplification & Refactoring Opportunities

| What | Where | Why |
|------|-------|-----|
| Duplicate login detection block | `IngestLog.php:100-107` + `138-145` | Identical 7-line block appears twice |
| Duplicate game grouping logic | `AdvanceMatchState.php:131-155` + `310-334` | Verbatim copy |
| Dead method `extractUsername()` | `IngestLog.php:180-187` | Never called |
| `IngestLog::run()` is 155 lines | `IngestLog.php:18-173` | Mixes file I/O, cursor mgmt, parsing, account registration, DB transaction |
| `AdvanceMatchState::run()` is 412 lines | Full file | Five state transitions, game creation, league assignment, concede detection |
| Repeated account lookup pattern | `BuildMatches`, `BuildMatch`, `MtgoManager` | Two queries for same thing |
| `ValidatePath::forLogs()` / `forData()` | `ValidatePath.php` | Nearly identical methods |

---

## Test Coverage Gaps

No unit tests for: `ClassifyLogEvent`, `GetGameLog` parsing, `DetermineMatchArchetypes`, `DetermineMatchDeck`, or pure extraction functions. Tests are almost entirely feature-level.

---

## What's Fine

- Jobs are thin wrappers delegating to Actions
- MtgoManager is proper orchestration
- Cursor management in IngestLog is solid for common cases
- Event listener auto-discovery works via Laravel conventions
- `active` vs `current` on Account is intentional
