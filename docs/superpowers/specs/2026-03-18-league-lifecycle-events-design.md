# League Lifecycle Events — Design Spec

## Problem

Leagues are currently created reactively when the first match arrives, using composite key guessing (`token + format + deck_version_id`) to determine run boundaries. This is fragile — League Tokens are per-league-season (shared by all players), not per-run. When a user drops from a league at <5 matches and re-enters with the same deck, there's no signal to distinguish the new run from the old one.

## Goal

Detect league join events from the MTGO log file and create leagues proactively. By the time a match arrives, the league row already exists. When a new join is detected for the same league token and an active run exists, mark the old run as Partial and create a new one. Keep reactive creation as a fallback for cases where the join event was missed (app start mid-league, log rotation).

## Discovered Log Signals

From raw MTGO log analysis:

| Signal | Log Pattern | Data Available |
|--------|-------------|----------------|
| **Join** | `Send Class: FlsLeagueUserJoinReqMessage` followed by `(UI\|Creating GameDetailsView) League` with `EventToken=`, `EventId=`, `PlayFormatCd=` | EventToken (League Token), EventId (league season ID), format |
| **Match start** | `(Leagues\|HandleFlsLeagueMatchStarted) LeagueId={id}, MatchId={id}, MatchToken="{token}"` | Direct league-to-match link |

**Note:** `FlsLeagueUserDropReqMessage` exists but is intentionally not used — the "Req" suffix means it's a request, and we can't be 100% certain the user followed through. Instead, we detect the start of a new run (join event) and infer the old run ended.

**Note:** The deck list is only available at join time for the first entry (`Twitch Info|Deck Used to Join Event ID`), not for re-entries. `deck_version_id` is filled later when the first match links a deck via `DetermineMatchDeck`.

## Architecture

### New Log Event Type

`ClassifyLogEvent` detects one new event type:

**`league_joined`** — When `FlsLeagueUserJoinReqMessage` is followed by `(UI|Creating GameDetailsView) League` containing:
- `EventToken` (the League Token)
- `EventId` (MTGO league season ID, e.g. 10397)
- `PlayFormatCd` (format)

### New Event & Listener

**`LeagueJoined` event** → `CreateLeagueFromJoinEvent` listener:
1. Check if an active league exists for this `event_token` → if yes, mark it `Partial` (user dropped/completed and re-entered)
2. Create new league row: `event_id`, `token`, `format`, `joined_at`, state `Active`
3. `deck_version_id` stays null until first match links a deck

### Migration — New Columns on `leagues`

- `event_id` (integer, nullable) — MTGO league season ID (e.g. 10397). Same across all players and re-entries in a league season.
- `joined_at` (datetime, nullable) — When the user joined this specific run.

### AssignLeague Simplification

For real leagues (has League Token), the lookup order becomes:

1. **Best path:** Find active league by `event_id` (from `HandleFlsLeagueMatchStarted` which provides `LeagueId`)
2. **Fallback:** Find active league by `token + deck_version_id` (existing composite key logic)
3. **Safety net:** Create reactively (current behavior, for cases where join event was missed — e.g. app started mid-league, log rotation)

### Redundancy Model

| Scenario | How it's handled |
|----------|-----------------|
| Normal flow (join event seen) | League created proactively, match links to it |
| App started mid-league (join event missed) | `AssignLeague` creates league reactively at first match |
| User drops and re-enters | New join event finds active league → marks old as Partial, creates new |
| User completes 5 matches and re-enters | League already marked Complete, new join creates fresh row |
| 5th match completes | League marked Complete (existing logic) |

## What Changes

| Component | Change |
|-----------|--------|
| `ClassifyLogEvent` | Detect `league_joined` event type |
| `LeagueJoined` event | **New** — dispatched when join log event processed |
| `CreateLeagueFromJoinEvent` listener | **New** — creates league row proactively, marks old run as Partial |
| `leagues` migration | **New** — add `event_id`, `joined_at` |
| `League` model | Add casts for new columns |
| `AssignLeague` | Simplify: prefer `event_id` lookup, keep fallback logic |

## What Stays the Same

- `AdvanceMatchState` orchestration unchanged
- `SyncLiveGameResults` unchanged
- `CreateOrUpdateGames` unchanged
- Overlay controller unchanged
- Phantom league logic unchanged (no join/drop events for casual play)
- All existing return formats preserved

## Testing

- Test `ClassifyLogEvent` detects `league_joined` from raw log lines
- Test `CreateLeagueFromJoinEvent` creates league with correct `event_id`, `token`, `format`, `joined_at`
- Test `CreateLeagueFromJoinEvent` marks existing active league as Partial on re-join
- Test `CreateLeagueFromJoinEvent` is idempotent (rapid duplicate joins don't create duplicates)
- Test `AssignLeague` finds league by `event_id` when available
- Test `AssignLeague` falls back to composite key when `event_id` not available
- Test `AssignLeague` creates reactively when no league exists (safety net)
- Test full flow: join → match → re-join → match (integration test with raw log fixture)

## Edge Cases

- **App starts mid-league**: No join event in current log. First match triggers reactive creation in `AssignLeague`. Normal operation.
- **Log rotation loses join event**: Same as above — fallback handles it.
- **Multiple rapid joins (UI glitch)**: Idempotent — second join for same token finds the league just created (no matches yet), reuses it.
- **Join event but user never plays a match**: League row exists with state Active, no matches. Gets marked Partial when next join arrives, or stays as an empty Active league (harmless).
- **Join for already-Complete league**: No conflict — Complete league is not Active, so a new row is created cleanly.
