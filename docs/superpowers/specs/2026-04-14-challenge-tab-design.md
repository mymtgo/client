# Challenge Tab Design

## Overview

Add a Challenge tab to the MTGO Deck Tracker that displays tournament/challenge data broadcast by MTGO to all connected clients. Covers both spectated challenges (passive broadcast) and participated challenges (user is playing), with participation support deferred to a later phase.

MTGO broadcasts rich tournament data via log files including full player rosters with names, per-round standings, eliminations, match pairings, and tournament metadata — even when the user is only spectating. This feature captures, stores, and displays that data.

## Scope

**In scope:**
- New database tables for challenges, standings, and timeline events
- Log event classification and processing pipeline for challenge data
- Challenges index page with filtering
- Challenge detail page with standings, timeline feed, and live polling
- Deck view challenges tab
- Seed pipeline from existing log file for development/testing

**Out of scope:**
- Participation pipeline (Joined state variants, match-tournament token linking)
- Deck view KPIs (best result, top 8/16 counts) — deferred until data exists
- Limited event schema (draft pools, picks) — separate future feature
- Deck integration on challenge detail page for participated challenges

## Data Sources

The primary data source is `EventSyncData_t` messages in the MTGO log file. These contain:

- Full player roster with `LoginID` and `PlayerName` mappings
- Tournament metadata: name, format, structure, round count, player limits
- Per-round match pairings with `ParentToken` linking to tournament
- Eliminated players list
- Prize structure and format description

Additional messages provide incremental updates:

- `Tournament State Changed` — lifecycle transitions
- `FlsTournamentRoundResultMessage` — per-round standings with rank, points, W-L records, tiebreakers
- `FlsTournamentPlayerIsEliminatedMessage` — elimination with reason
- `FlsTournamentEndRespMessage` — tournament completion

Format descriptions from MTGO use BBCode (e.g. `[b]Lorwyn Eclipsed[/b]`). These are stripped to plain text during processing before storage.

---

## Schema

### New Tables

#### `challenges`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | Auto-increment |
| token | string | MTGO tournament token |
| name | string, nullable | Event name, e.g. "Modern Challenge" |
| format | string, nullable | e.g. "Modern", "Legacy" |
| description | text, nullable | Format description (BBCode stripped) |
| tournament_structure | string, nullable | e.g. "Swiss" |
| state | string | `TournamentState` enum, default `AwaitingPlayers` |
| current_round | int, nullable | Updated as rounds progress |
| max_rounds | int, nullable | From sync data |
| player_count | int, default 0 | Current/final count |
| min_players | int, nullable | From sync data |
| max_players | int, nullable | From sync data |
| started_at | timestamp, nullable | When the challenge fired |
| ended_at | timestamp, nullable | When it completed |
| participated | bool, default false | Did the local user play in this? |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp, nullable | Soft deletes for cleanup |

**Indexes:**
- `token` — unique
- `[format, state]` — index page filtering
- `[participated, state]` — separating spectated from participated for cleanup queries

#### `challenge_standings`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | Auto-increment |
| challenge_id | bigint FK | References `challenges.id`, cascade delete |
| round | int | Which round this standing is from |
| login_id | int | MTGO numeric player ID |
| username | string, nullable | Populated from sync data, nullable defensively |
| rank | int | Position in standings |
| points | int | Match points |
| match_record | string | Per-round W-L records, e.g. "2-0, 2-1, 1-2" |
| opponent_match_win_pct | float, nullable | OMW% tiebreaker |
| game_win_pct | float, nullable | GW% tiebreaker |
| is_local | bool, default false | Is this the local user? |
| created_at | timestamp | |
| updated_at | timestamp | |

**Indexes:**
- `[challenge_id, round, login_id]` — unique composite (idempotent upserts)
- `[login_id, is_local]` — finding local user standings quickly

#### `challenge_timeline_events`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | Auto-increment |
| challenge_id | bigint FK | References `challenges.id`, cascade delete |
| round | int, nullable | Null for lifecycle events |
| event_type | string | `ChallengeTimelineEventType` enum |
| login_id | int, nullable | Player involved, if applicable |
| username | string, nullable | Resolved from sync data |
| payload | json, nullable | Extra data (e.g. elimination reason) |
| occurred_at | timestamp | When it happened |
| created_at | timestamp | |
| updated_at | timestamp | |

**Indexes:**
- `[challenge_id, round]` — feed queries
- `[challenge_id, event_type]` — filtering by event type

### Modifications to Existing Tables

#### `matches`

Add `challenge_id` as a nullable foreign key referencing `challenges.id`. Indexed.

#### `players`

Add `login_id` as a nullable indexed column (int) for MTGO numeric ID. Used for login ID to username resolution.

---

## Enums

### `TournamentState` (string-backed)

Agnostic enum shared across challenges and future limited events.

| Case | MTGO States Mapped |
|------|--------------------|
| `AwaitingPlayers` | `PremierNotJoinedAwaitingMinPlayersState`, `PremierNotJoinedAwaitingMaxPlayersState` |
| `Firing` | `PremierNotJoinedAwaitingStartState`, `TournamentNotJoinedFiredState` |
| `Drafting` | `TournamentNotJoinedDraftingState` |
| `DeckBuilding` | `TournamentNotJoinedDeckBuildingState` |
| `WaitingForFirstRound` | `TournamentNotJoinedWaitingForFirstRoundToStartState` |
| `RoundInProgress` | `TournamentNotJoinedRoundInProgressState` |
| `BetweenRounds` | `TournamentNotJoinedBetweenRoundsState` |
| `Completed` | `TournamentCompletedState` |

### `ChallengeTimelineEventType` (string-backed)

- `StateChanged` — challenge lifecycle transition
- `RoundResult` — standings posted for a round
- `PlayerEliminated` — player dropped or eliminated
- `MatchStateChanged` — individual match state change within tournament

### `EliminationReason` (string-backed)

- `MatchLoss`
- `Drop`

### `TournamentStructure` (string-backed)

- `Swiss`
- Additional values added as discovered from log data.

### `LogEventType` additions

Added to the existing enum:

- `CHALLENGE_SYNC` — `EventSyncData_t` messages (primary data source)
- `CHALLENGE_STATE_CHANGED` — tournament state transitions
- `CHALLENGE_ROUND_RESULT` — `FlsTournamentRoundResultMessage`
- `CHALLENGE_PLAYER_ELIMINATED` — `FlsTournamentPlayerIsEliminatedMessage`
- `CHALLENGE_ENDED` — `FlsTournamentEndRespMessage`
- `CHALLENGE_MATCH_STATE_CHANGED` — tournament match state transitions

---

## Event Pipeline

### Architecture: Split Pipeline

Two independent actions following the Single Responsibility Principle:

1. **`ProcessChallengeEvents`** — handles all challenge domain logic (lifecycle, standings, timeline)
2. **`LinkMatchToChallenge`** — bridges the challenge domain with the match pipeline (deferred to participation phase)

### `ProcessChallengeEvents` (`app/Actions/Challenges/`)

Runs during the ingestion cycle. Processes unprocessed challenge-related log events in order.

#### `CHALLENGE_SYNC` (EventSyncData_t)

The primary data source. A single sync message carries a full tournament snapshot.

Processing steps:
1. Extract tournament token from `EventToken`
2. Upsert challenge record: name (`Description`), format (`GameStructureCd` / `FormatDescription`), structure (`TournamentStructureCd`), max rounds (`NumberOfRounds`), min/max players, start/end dates
3. Strip BBCode tags from description fields (remove `[b]`, `[/b]`, `[i]`, `[/i]`, etc.)
4. Bulk upsert player `login_id` → `username` mappings into `players` table from the `Players` array
5. Process round/match data from `Rounds` array if present
6. Update eliminated players from `EliminatedPlayers` array

#### `CHALLENGE_STATE_CHANGED`

1. Map MTGO state string to `TournamentState` enum
2. Upsert challenge by token, update state
3. Set `started_at` on first transition out of `AwaitingPlayers`
4. Update `current_round` when transitioning to `RoundInProgress`
5. Set `ended_at` on transition to `Completed`
6. Create timeline event

#### `CHALLENGE_ROUND_RESULT`

1. Parse JSON standings data: login ID, rank, points, per-round W-L records, OMW%, GW%
2. Upsert standings rows using `[challenge_id, round, login_id]` unique constraint (idempotent)
3. Resolve usernames from `players.login_id` (already populated by sync)
4. Flag `is_local` by matching against the local user's login ID
5. Update `current_round` and `player_count` on challenge
6. Create timeline event

#### `CHALLENGE_PLAYER_ELIMINATED`

1. Create timeline event with elimination reason in payload (`MatchLoss` or `Drop`)
2. Resolve username from login ID

#### `CHALLENGE_ENDED`

1. Set challenge state to `Completed`, set `ended_at`
2. Create timeline event

#### `CHALLENGE_MATCH_STATE_CHANGED`

1. Create timeline event (feed-only data, no model updates)

### `LinkMatchToChallenge` (`app/Actions/Challenges/`)

Deferred to participation phase. When implemented:
- Triggered during match processing when a tournament `ParentToken` is detected
- Sets `challenge_id` on the match
- Marks the challenge as `participated = true`

### Login ID Resolution

Solved by `CHALLENGE_SYNC` — the `EventSyncData_t` message contains full `LoginID` → `PlayerName` mappings for all participants. These are bulk upserted into `players.login_id` on first sync. Standings can reference usernames immediately.

### `ClassifyLogEvent` Changes

New regex patterns added to detect:
- `EventSyncData_t` messages with tournament data → `CHALLENGE_SYNC`
- `Tournament State Changed for {token} from {state} to {state}` → `CHALLENGE_STATE_CHANGED`
- `FlsTournamentRoundResultMessage` → `CHALLENGE_ROUND_RESULT`
- `FlsTournamentPlayerIsEliminatedMessage` → `CHALLENGE_PLAYER_ELIMINATED`
- `FlsTournamentEndRespMessage` → `CHALLENGE_ENDED`
- `TournamentMatch` state changes → `CHALLENGE_MATCH_STATE_CHANGED`

Each pattern extracts the tournament token and relevant payload for downstream processing.

---

## Retention

**Participated challenges:** Kept forever.

**Spectated challenges:** Rolling time-based window. Challenges older than N days (configurable, default 30) are soft-deleted by a scheduled cleanup job. The job queries `challenges` where `participated = false` and `created_at < now() - N days`, then soft-deletes them. Cascade deletes handle standings and timeline events.

---

## UI

### Navigation

Add "Challenges" to `AppNav.vue` between Leagues and Opponents. Uses `ChallengesIndexController.url()` for href. Active state detected by URL prefix `/challenges`.

### Challenges Index Page (`/challenges`)

**Route:** `GET /challenges`
**Controller:** `App\Http\Controllers\Challenges\IndexController`
**Page:** `resources/js/pages/challenges/Index.vue`

**Filters:**
- Format dropdown — built from distinct formats in database
- State toggle — Active (default) / Completed / All
- Participated toggle — All (default) / Participated only

**Table:**

| Column | Description |
|--------|-------------|
| Name | Challenge name from sync data |
| Format | e.g. "Modern", "Legacy" |
| State | Colored badge from `TournamentState` |
| Round | "3/7" (current/max) |
| Players | "32/256" (current/max) |
| Started | Absolute + relative time |
| Your Rank | Rank from latest standings where `is_local = true`, dash if spectated |
| View | Link to challenge detail page |

**Sorting:** Active challenges first (by `started_at` desc), then completed (by `ended_at` desc). Paginated.

**Empty states:**
- No challenges: "No challenges detected yet. Challenges will appear here when MTGO broadcasts tournament data."
- No filter matches: "No challenges match your filters."

### Challenge Detail Page (`/challenges/{challenge}`)

**Route:** `GET /challenges/{challenge}`
**Controller:** `App\Http\Controllers\Challenges\ShowController`
**Page:** `resources/js/pages/challenges/Show.vue`

**Layout:** Three columns on desktop, stacking on smaller screens.

**Left column — Details:**
- Challenge name
- Format
- State badge
- Tournament structure (e.g. "Swiss")
- Round progress: "Round 3 of 7"
- Players: "32/256"
- Started at (timezone-aware, absolute + relative)
- Ended at (if completed)
- Your status: "Participating" / "Spectating"

**Middle column — Standings:**
- Columns: Rank, Player, Points, Record (per-round W-L), OMW%, GW%
- Local user row highlighted with blue background
- If user is outside visible range, pin their row at the bottom
- Eliminated players: greyed out text, strikethrough on name
- Sorted by rank

**Right column — Timeline Feed:**
- Grouped by round (newest round first)
- Each event: colored dot by type, description, timestamp
  - State changes: "Challenge moved to Round 3"
  - Eliminations: "PlayerName dropped" / "PlayerName eliminated (Match Loss)"
  - Round results: "Round 2 standings posted"
- Scrollable

**Live polling:** Inertia v2 polling at 30-second intervals while challenge state is not `Completed`. Stops on completion.

**Back navigation:** "Back to Challenges" link at top. When arriving from deck view (via `?from=deck&deck_id={id}`), also show "Back to Deck" link.

### Deck View Challenges Tab (`/decks/{deck:id}/challenges`)

**Route:** `GET /decks/{deck:id}/challenges`
**Controller:** `App\Http\Controllers\Decks\ChallengesController`
**Page:** `resources/js/pages/decks/Challenges.vue`

Follows existing deck tab pattern: uses `GetDeckViewSharedProps`, renders within `DeckViewLayout`.

**Sidebar:** Add "Challenges" nav item after Leagues in `DeckSidebar.vue`.

**Table:**

| Column | Description |
|--------|-------------|
| Name | Challenge name |
| Format | e.g. "Modern" |
| State | Badge — "In Progress" or "Completed" |
| Round | "3/7" or "7/7" |
| Your Rank | Final rank if completed, current if in progress |
| Date | `started_at` |
| View | Link to challenge detail page (with `?from=deck&deck_id={id}`) |

**Query:** Challenges that have at least one match with a `deck_version_id` belonging to this deck. No direct FK on challenges — follows the chain through matches.

**Note:** Until the participation pipeline is implemented (`LinkMatchToChallenge`), no matches will have `challenge_id` set, so this tab will be empty. The tab should still be built and visible — it will populate once participation support is added. For development, the seed command can optionally create synthetic match links for testing purposes.

---

## Development Seeding

Since there is no active MTGO log file on the development machine, use the existing log file at `storage/app/mtgo.log` to seed challenge data through the real pipeline.

Create a command (e.g. `php artisan challenges:seed-from-log`) that:
1. Reads `storage/app/mtgo.log`
2. Runs event classification via `ClassifyLogEvent` for challenge-related events
3. Processes classified events through `ProcessChallengeEvents`
4. Populates challenges, standings, timeline events, and player login ID mappings

This validates the full pipeline end-to-end and provides real data for UI development. The command is development-only and does not affect the production ingestion pipeline.

---

## Models

### `Challenge` (`app/Models/Challenge.php`)

- Soft deletes
- Casts: `state` → `TournamentState`, `participated` → `boolean`, `started_at` → `datetime`, `ended_at` → `datetime`
- Relationships:
  - `standings()` → HasMany ChallengeStanding
  - `timelineEvents()` → HasMany ChallengeTimelineEvent
  - `matches()` → HasMany MtgoMatch
- Scopes:
  - `scopeActive()` — state not Completed
  - `scopeCompleted()` — state is Completed
  - `scopeParticipated()` — participated = true
  - `scopeForFormat($format)` — filter by format

### `ChallengeStanding` (`app/Models/ChallengeStanding.php`)

- Casts: `is_local` → `boolean`, `opponent_match_win_pct` → `float`, `game_win_pct` → `float`
- Relationships:
  - `challenge()` → BelongsTo Challenge

### `ChallengeTimelineEvent` (`app/Models/ChallengeTimelineEvent.php`)

- Casts: `event_type` → `ChallengeTimelineEventType`, `payload` → `array`, `occurred_at` → `datetime`
- Relationships:
  - `challenge()` → BelongsTo Challenge

### `MtgoMatch` (modification)

- Add relationship: `challenge()` → BelongsTo Challenge

### `Player` (modification)

- Add `login_id` to fillable
