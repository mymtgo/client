# Tournament Observations — Design (Sub-project 1 of 4)

**Date:** 2026-04-21
**Status:** Design approved, pending implementation plans
**Scope:** Backend plumbing only. No UI changes. No hydration. No server-side projections.

## Background

Tournament data in the MTGO client today is fragmentary: a single user's log only captures the events they witnessed, so if they log in mid-round or skip a round they get gaps. In a prior branch (`challenge_tab`) the client tried to project tournament state locally from those partial observations, which produced unreliable data (bad `current_round` counters, missing standings, unclassified pairings). The project's live `challenge_tab` investigation (2026-04-20) confirmed the client-only approach cannot reach "good" data.

The fix: make tournaments a **pooled resource**. Every client forwards its tournament observations to the external mymtgo API. The API aggregates across the userbase so one active user's observations fill gaps for everyone else. The API then becomes the source of truth for tournament standings, timelines, pairings, and round progression.

This is a multi-project feature spanning four sub-projects:

1. **Tournament observations (this doc).** Client forwards classified tournament log events to API; API accepts and stores them. No projections, no reads, no UI. Purpose: start accumulating raw data so that later sub-projects have something to work with.
2. **API projections.** Server-side logic that turns the observation log into `tournaments`, `tournament_standings`, `tournament_timeline_events`, `tournament_matches` (pairings). Read endpoints.
3. **Client hydration.** On-demand sync on the tournament page. Local `tournaments`-family tables become a write-through cache of API data. "Syncing…" / "Sync failed" UI with manual retry.
4. **Client tournament match display.** Surface per-round pairings and per-match detail in the client UI.

Everything beyond sub-project 1 is **explicitly out of scope for this spec.**

## Goal

Ship a producer/consumer pair — client producer, API consumer — that:

- Captures every tournament-relevant log event the client sees
- Ships each observation exactly once to the API (idempotent at the network layer)
- Persists a minimum of local state on the client to identify participated tournament matches (so a future hydration step can marry them up to API-aggregated tournaments)
- Does **nothing else** — no display, no derived tournament state, no hydration

## Out of Scope

- Any UI showing tournament data to users
- Server-side projections (observation log → tournaments/standings/timeline/matches)
- Client → API fetching of tournament data
- `share_stats` setting gate (deferred to sub-project 3 when the UI is involved)
- Retroactive backfill of historical matches (see `project_challenge_backfill.md` — not viable)
- Fixing the `current_round` projection bug from the `challenge_tab` branch — that code doesn't exist on this branch, and the new architecture removes the need for it

## Wire Contract

### Observation shape

```json
{
  "tournament_token": "4b92a89a-a319-4725-aa5a-35bff1357ec9",
  "match_token": null,
  "event_type": "tournament_round_result",
  "payload": { "... raw JSON from log line ..." },
  "client_observed_at": "2026-04-21T09:12:33Z"
}
```

Field rules:

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `tournament_token` | string (UUID) | At least one of this or `match_token` | The MTGO tournament UUID. Always set for sync/state/result/info/eliminated/ended. Null for `tournament_match_state_changed` (server correlates later). |
| `match_token` | string (UUID) | At least one of this or `tournament_token` | The MTGO match UUID. Set for `tournament_match_state_changed` and any event that carries a match-scope identifier. |
| `event_type` | string | yes | One of the seven types listed below. |
| `payload` | object | yes | The raw JSON object extracted from the log line, untouched apart from standard JSON parsing. |
| `client_observed_at` | ISO-8601 UTC | yes | When the client ingested the log line (not MTGO's own timestamp — MTGO timestamps are in the payload if present). Useful for support/debugging only. |

**No server-side dedupe at write time.** The observations table is append-only; the same event observed by N clients will produce N rows. The projection job (sub-project 2) is responsible for dedup at projection time — it already needs idempotent per-tournament rebuild logic, so this keeps the write path dumb. Tournament events are small and bounded, so the storage cost is acceptable.

### Event types

The client classifies and forwards exactly these seven types. Anything else is ignored.

| `event_type` | Log-line signature | Token carrier |
| --- | --- | --- |
| `tournament_sync` | `EventSyncData_t` block containing a tournament token | `Token` in payload |
| `tournament_state_changed` | `Tournament State Changed from X to Y` with tournament token context | tournament token parsed from surrounding context |
| `tournament_round_result` | `FlsTournamentRoundResultMessage` | `Token` in payload |
| `tournament_round_info` | `FlsTournamentRoundInfoMessage` **(new classification)** | `Token` in payload |
| `tournament_player_eliminated` | `FlsTournamentPlayerIsEliminatedMessage` | `Token` in payload |
| `tournament_ended` | tournament-end signature | `Token` in payload |
| `tournament_match_state_changed` | `TournamentMatch State Changed` lines **(new classification)** | `match_token` only; server correlates to tournament via RoundInfo cross-reference |

### Auth

Reuse the existing device-key pattern used by `App\Actions\Matches\SubmitMatchToApi`:

- Header `X-Device-Id`: `Settings::get('device_id')`
- Header `X-Api-Key`: `RegisterDevice::retrieveKey()`
- On 401: call `RegisterDevice::run()` and retry once

No new auth primitives.

### Transport

- **Batch size:** up to 200 observations per request body.
- **Flush trigger:** every 30 seconds, or when 50 queued observations accumulate, whichever first. A final flush runs on graceful shutdown. (Values are the starting point; wire them into config so we can tune without a deploy.)
- **Compression:** `Content-Encoding: gzip` on every batch. The body is a gzip-compressed JSON array of observation objects.
- **Endpoint:** `POST /api/tournament-observations`.
- **Success:** 2xx ⇒ all observations in the batch are considered delivered and marked sent in the client queue. The server accepts dupes silently, so we don't need to track per-observation success/failure within a batch.
- **Failure:** non-2xx (including 401 retries exhausted) ⇒ all observations in the batch remain in `pending` state and are re-attempted on the next flush tick, with exponential backoff capped at 5 minutes. No dead-letter handling in this sub-project — volume is low enough that stuck observations are acceptable and can be inspected manually if they pile up.

## Client-side Design

### Columns added to `matches`

Nullable, on the existing `matches` table (do NOT port the `tournaments` FK from `challenge_tab`):

| Column | Type | Purpose |
| --- | --- | --- |
| `tournament_event_id` | `unsignedInteger`, nullable, indexed | Numeric MTGO tournament event ID parsed from `Description=Tournament:{N}` on `TournamentMatchJoined*` events. Distinct from `matches.mtgo_id` (which is the **match's** numeric ID). |
| `tournament_round` | `unsignedSmallInteger`, nullable | Round number parsed from `Description=Round:{M}`. |

Already present and reused as correlation keys:

- `matches.token` — equals `gameMeta.MatchToken` (UUID, e.g. `2212f089-c748-42c8-ab3f-61c463a4278f`).
- `matches.mtgo_id` — equals `gameMeta.MatchID` (numeric, e.g. `286451927`). This is likely the value that appears in `RoundInfo.Matches[].EventID` server-side — see "Event ID ↔ tournament token mapping" below.

No `tournament_id` FK yet — that lands in sub-project 3 (hydration) where local tournament rows start existing.

### Stamping the match on join

`App\Actions\Matches\AdvanceMatchState` already handles the join event and writes `matches.token` and `matches.mtgo_id`. Extend the creation path (around line 99–107) to also stamp tournament fields when the Description matches the tournament pattern:

```php
$tournamentEventId = null;
$tournamentRound = null;
if (preg_match('/Tournament:(\d+)\s+Round:(\d+)/', $gameMeta['Description'] ?? '', $m)) {
    $tournamentEventId = (int) $m[1];
    $tournamentRound = (int) $m[2];
}

$match = MtgoMatch::create([
    'mtgo_id' => $matchId,
    'token' => $matchToken,
    // …existing fields…
    'tournament_event_id' => $tournamentEventId,
    'tournament_round' => $tournamentRound,
]);
```

Real join events from MTGO (provided 2026-04-21) confirm the Description format, e.g. `Description=Tournament:12839688 Round:3`.

### Phantom-league exclusion

`App\Actions\Matches\AssignLeague` currently routes matches with no `gameMeta['League Token']` into `findOrCreatePhantomLeague` (line 73+). Tournament matches carry a Description but no League Token, so today they would be bucketed into phantom leagues — wrong.

Fix: in `AssignLeague::run`, branch off before the phantom fallback:

```php
if (preg_match('/Tournament:\d+\s+Round:\d+/', $gameMeta['Description'] ?? '')) {
    // Tournament match — deliberately unassigned until hydration lands (sub-project 3).
    return;
}
```

This leaves `matches.league_id = NULL` for tournament matches. When hydration is built, tournament matches get linked via `matches.tournament_id` instead.

### Queue table: `tournament_observation_queue`

New table, client-local.

```
id                  big int, PK
log_event_id        big int, FK to log_events(id), UNIQUE
tournament_token    string, nullable, indexed
match_token         string, nullable, indexed
event_type          string, indexed
payload             json
client_observed_at  datetime
status              enum('pending','sending','sent','failed'), default 'pending', indexed
attempts            unsigned small int, default 0
next_attempt_at     datetime, nullable
last_error          text, nullable
created_at/updated_at
```

- Unique `log_event_id` ensures one classified LogEvent produces at most one queue row. If classification re-runs (e.g., dev rebuild), we don't re-enqueue. No cryptographic hashing needed — the LogEvent FK is the natural idempotency key.
- `status='sending'` is a transient state while a batch is in flight; reverted to `pending` on failure or `sent` on success.
- `sent` rows are kept for 7 days for support/debug visibility, then pruned by a scheduled job.

### Classifier additions

The existing classifier (`App\Actions\Logs\ClassifyLogEvent` — **verify name on `main`**, may not exist yet in the shape we expect) needs branches for:

- `FlsTournamentRoundInfoMessage` → `tournament_round_info`, extract `Token` as tournament_token
- `TournamentMatch State Changed` → `tournament_match_state_changed`, extract the match_token embedded in the line, leave tournament_token null

Existing types (`sync`, `state_changed`, `round_result`, `player_eliminated`, `ended`) may or may not exist on `main` — if not, they come in alongside the new ones.

Use the `challenge_tab` branch as reference for regex patterns and `ExtractJson` helpers, but **re-implement fresh** rather than cherry-picking — the rename history (challenge → tournament) makes cherry-picks messy, and we want to avoid dragging in projection code we no longer need.

### Enqueueing

After classification, if `event_type` is one of the seven tournament types, write a row into `tournament_observation_queue`:

- `log_event_id` = the classified LogEvent's ID
- `status = 'pending'`, `attempts = 0`
- Duplicate `log_event_id` ⇒ silently swallow (this LogEvent was already enqueued)

### Sender job

`App\Jobs\ShipTournamentObservations`, queued, runs every 30s via scheduler. Also fires ad-hoc when 50+ pending rows exist.

Responsibilities:

1. Claim up to 200 rows where `status='pending' AND (next_attempt_at IS NULL OR next_attempt_at <= now())`. Flip to `status='sending'`.
2. Build the batch JSON array, gzip, POST with the auth headers.
3. 2xx ⇒ flip claimed rows to `sent`, set `attempts = attempts + 1` (for observability).
4. Non-2xx or exception ⇒ flip claimed rows back to `pending`, increment `attempts`, compute `next_attempt_at` using exponential backoff (`min(300, 5 * 2^attempts)` seconds), record `last_error`.
5. After 20 failed attempts on the same row, flip to `status='failed'` and leave it for inspection. No automatic recovery — alerts/logs only.

## API-side Design

Lives in the companion project at `/Volumes/Dev/mymtgo/api` (Windows path: `E:\mymtgo\api`). Implementation details for that repo belong in a separate plan; this doc just defines the contract.

### `tournament_observations` table

Server-side storage for the raw observation log. Append-only — no dedup at write time, no updates, no soft-deletes.

```
id                  big int, PK
tournament_token    string, nullable, indexed
match_token         string, nullable, indexed
event_type          string, indexed
payload             json
submitted_by_device string (device ID from header)
processed_at        datetime, nullable, indexed     ← used by sub-project 2's projection job
client_observed_at  datetime
created_at          datetime
```

The `processed_at` column is included up front so sub-project 2's projection job can mark observations as processed without another migration.

### `POST /api/tournament-observations`

Request:
- Headers: `X-Device-Id`, `X-Api-Key`, `Content-Encoding: gzip`, `Content-Type: application/json`
- Body: gzip-compressed JSON array of observation objects

Behaviour:
1. Authenticate device via existing middleware.
2. Decompress, parse array. Reject if array is empty or >200 items (413).
3. For each observation, validate shape (required fields, event_type enum, at least one token present). Invalid observations are dropped and logged; the batch still succeeds.
4. Insert the surviving observations in one bulk insert. No dedupe — every observation gets a row. Projection-time dedup is the later sub-project's problem.
5. Return `204 No Content` on success (nothing useful to send back).

The server does **not** derive or update any tournament/standings/timeline state in this sub-project. That's sub-project 2.

### Event ID ↔ tournament token mapping

Not built in this sub-project, but worth mapping out so sub-project 2's design can hit the ground running.

Participated matches carry a **tournament numeric ID** (`Description=Tournament:12839688`) and a **match numeric ID** (`MatchID=286415435`). Spectator-side events carry the **tournament UUID token** (`4b92a89a-…`). We need a way to connect these identifier families.

The findings note `FlsTournamentRoundInfoMessage` payloads include the tournament `Token` alongside per-match entries with `EventToken` and `EventID`. Given the findings described `EventID` as per-match detail, the most likely bridge is:

- Client-side join event → stamps `matches.mtgo_id` (= MatchID) and `matches.tournament_event_id` (= tournament numeric ID). Both are included in the observation payload when the join is forwarded.
- Server-side RoundInfo observation → carries tournament `Token` (UUID) and per-match `EventID` values.
- **Assumed bridge:** `RoundInfo.Matches[].EventID` == the match's `MatchID` (same numeric ID we've stored locally as `matches.mtgo_id`).
- Server projection (sub-project 2) cross-references: observed join has `MatchID=X` linked to `tournament_event_id=Y`; RoundInfo has `Token=Z` containing `EventID=X` → therefore `tournament_event_id Y ↔ tournament_token Z`.

**VERIFY at the start of sub-project 2 (API side):** capture a real `FlsTournamentRoundInfoMessage` payload and confirm (a) per-match `EventID` equals the same numeric ID we see as `MatchID` in the join event, and (b) there is no field that carries the tournament numeric ID directly. If (a) is false or (b) is surprisingly true, the bridge changes.

For this sub-project, no server-side logic acts on this mapping yet. We just ensure the client ships enough in the payload — specifically, the raw log JSON (which includes `MatchID`/`MatchToken`) — so the projection job has everything it needs.

## Testing Considerations

**Client:**

- Classifier unit tests for each of the seven event types, using the real 2026-04-21 log fragments as fixtures for the join events (tournament `12839688`, rounds 1–4 and tournament `12839714` round 1).
- Feature test: a classified tournament event lands in `tournament_observation_queue` with the expected fields.
- Feature test: classifying the same LogEvent twice enqueues only one observation (unique `log_event_id` FK).
- Feature test: sender job claims pending rows, on 200 flips them to `sent`, on 500 flips them back with incremented `attempts` and a backoff-based `next_attempt_at`.
- Feature test: match stamping — a `TournamentMatchJoinedEventUnderwayState` log with `Description=Tournament:12839688 Round:3` sets `tournament_event_id=12839688` and `tournament_round=3` on the new match row, while `matches.token` and `matches.mtgo_id` are populated as before.
- Feature test: `AssignLeague` returns without assigning a league for a match whose `gameMeta.Description` matches the tournament pattern (no phantom league created, `matches.league_id` remains null).

**API:** (lives with sub-project's API plan, but noted here for contract visibility)

- POST with gzipped body → all valid rows inserted.
- POST with an invalid observation in the array (missing token, unknown event_type) → that observation dropped, rest succeed, warning logged.
- POST with empty/oversize array → 413.
- POST without auth headers → 401.
- POST from a device observing the same event twice in different batches → two rows (no write-time dedupe).

## Open Questions (resolve at implementation time)

1. **Classifier shape on `main`.** The `challenge_tab` layout (`App\Actions\Logs\ClassifyLogEvent`) may or may not exist here — the plan should locate the existing classifier and either extend or add tournament branches. Use `challenge_tab` as reference, not a cherry-pick source.
2. **RoundInfo `EventID` field semantics.** Assumption: per-match `EventID` in `FlsTournamentRoundInfoMessage` equals the match's `MatchID` (i.e., `matches.mtgo_id`). Confirm at the start of sub-project 2 with a captured RoundInfo payload.

## Resolved assumptions (confirmed 2026-04-21)

- `gameMeta.MatchToken` equals `matches.token` and `gameMeta.MatchID` equals `matches.mtgo_id` (confirmed against real join events). No `tournament_match_token` column needed — `matches.token` is already the match UUID.
- `Description=Tournament:{N}` carries the tournament's numeric event ID (distinct from the match's numeric MatchID).
- Tournament matches currently fall into phantom-league routing at `AssignLeague.php:73` because they have no `League Token`. Exclusion branches off at the top of `AssignLeague::run`.
