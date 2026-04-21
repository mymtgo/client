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
  "content_hash": "sha256-hex-digest",
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
| `content_hash` | string (64 hex chars) | yes | sha256 of the canonical-JSON string `{event_type}{tournament_token or match_token}{canonical(payload)}`. Computed client-side, re-verified server-side. |
| `client_observed_at` | ISO-8601 UTC | yes | When the client ingested the log line (not MTGO's own timestamp — MTGO timestamps are in the payload if present). Useful for support/debugging only; not part of dedupe. |

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

### Content hash

**Canonicalisation:** `json_encode($payload, JSON_SORT_KEYS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`. PHP equivalent: sort keys recursively, then `json_encode` with the listed flags. Node/server should use the same canonical form.

**Hash input:** `{$event_type}|{$tournament_token ?? $match_token}|{$canonical_payload_json}`

**Hash output:** lowercase hex sha256.

**Dedupe semantics:** API rejects (silently accepts) any observation whose `content_hash` already exists. Unique constraint on the column. No special behaviour for same-content different-user: first one wins.

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
| `tournament_event_id` | `unsignedInteger`, nullable, indexed | Numeric MTGO event ID parsed from `Description=Tournament:{N}` on `TournamentMatchJoined*` events. The ID a participated match is most reliably keyed by. |
| `tournament_round` | `unsignedSmallInteger`, nullable | Round number parsed from `Description=Round:{M}`. |
| `tournament_match_token` | `string`, nullable, indexed | The match_token from the join event's `gameMeta`. Secondary correlation key. |

No `tournament_id` FK yet — that lands in sub-project 3 (hydration) where local tournament rows start existing.

### Stamping the match on join

The existing match pipeline already receives the join event's `gameMeta`. Add a new hook in `App\Actions\Matches\AdvanceMatchState` (or wherever the participated-join transition lands on `main` — verify at implementation time, since the `challenge_tab` version of this file doesn't exist here):

```php
if (preg_match('/Tournament:(\d+)\s+Round:(\d+)/', $gameMeta['Description'] ?? '', $m)) {
    $match->update([
        'tournament_event_id' => (int) $m[1],
        'tournament_round' => (int) $m[2],
        'tournament_match_token' => $gameMeta['MatchToken'] ?? $match->token,
    ]);
}
```

The third column (`tournament_match_token`) may already equal `matches.token` for participated matches — **VERIFY**: check a real participated-match log to confirm the `gameMeta.MatchToken` equals the local `matches.token`. If so, we only need the first two columns and can drop the third.

### Phantom-draft exclusion

Wherever the client categorises matches into phantom leagues / phantom drafts (current codebase on `main` — **locate at implementation time**), short-circuit with:

> If `matches.tournament_event_id` is not null, the match is a tournament match. Skip any phantom routing.

This prevents the categoriser from dropping tournament matches into practice buckets before hydration has a chance to link them up.

### Queue table: `tournament_observation_queue`

New table, client-local.

```
id                  big int, PK
tournament_token    string, nullable, indexed
match_token         string, nullable, indexed
event_type          string, indexed
payload             json
content_hash        string (64), unique
client_observed_at  datetime
status              enum('pending','sending','sent','failed'), default 'pending', indexed
attempts            unsigned small int, default 0
next_attempt_at     datetime, nullable
last_error          text, nullable
created_at/updated_at
```

- Unique content_hash prevents the client from queueing its own duplicate.
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

- Compute `content_hash` client-side using the canonical-JSON form described above
- `status = 'pending'`, `attempts = 0`
- Duplicate content_hash ⇒ silently swallow (client already sent it)

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

Server-side storage for the raw observation log.

```
id                  big int, PK
content_hash        string (64), unique         ← idempotency key
tournament_token    string, nullable, indexed
match_token         string, nullable, indexed
event_type          string, indexed
payload             json
submitted_by_device string (device ID from header)
client_observed_at  datetime
created_at          datetime
```

No soft-deletes, no updates — this is an append-only log.

### `POST /api/tournament-observations`

Request:
- Headers: `X-Device-Id`, `X-Api-Key`, `Content-Encoding: gzip`, `Content-Type: application/json`
- Body: gzip-compressed JSON array of observation objects

Behaviour:
1. Authenticate device via existing middleware.
2. Decompress, parse array. Reject if array is empty or >200 items (413).
3. For each observation:
   - Re-compute `content_hash` server-side. If it doesn't match the submitted value, drop that single observation and log a warning (do not fail the whole batch).
   - Validate shape (required fields, event_type enum, at least one token present). Invalid observations are dropped and logged; the batch still succeeds.
4. Insert the surviving observations in one statement using `ON CONFLICT DO NOTHING` on the `content_hash` unique index, so duplicates are absorbed at the database layer without per-row try/catch.
5. Return `204 No Content` on success (nothing useful to send back).

The server does **not** derive or update any tournament/standings/timeline state in this sub-project. That's sub-project 2.

### Event ID ↔ tournament token mapping

Not built in this sub-project, but worth calling out so sub-project 2's design can hit the ground running.

The findings note `FlsTournamentRoundInfoMessage` payloads include `Token` (tournament UUID) alongside per-match entries containing `EventToken` (match UUID) and `EventID` (a numeric ID).

**VERIFY at implementation time (API side):** confirm that the per-match `EventID` field in RoundInfo is the same numeric ID as the participated-match `Description=Tournament:{N}` descriptor. If yes, RoundInfo becomes the universal bridge: one observation lets the server map every participated match to its tournament_token. If no, we need a different bridge (another event type carries both IDs, or we accept weaker linking).

Even though we're not building the mapping now, we should verify the premise early — before sub-project 3 depends on it.

## Testing Considerations

**Client:**

- Classifier unit tests for each of the two new event types, using real log fragments as fixtures.
- Unit test for the content-hash canonicaliser: identical payloads (different key ordering, different whitespace) produce identical hashes.
- Feature test: a classified tournament event lands in `tournament_observation_queue` with the expected fields.
- Feature test: duplicate observation (same content_hash) is dropped silently at enqueue time.
- Feature test: sender job claims pending rows, on 200 flips them to `sent`, on 500 flips them back with incremented attempts.
- Feature test: match stamping — a `TournamentMatchJoinedEventUnderwayState` log with `Description=Tournament:12345 Round:3` sets the three new columns on the match row.
- Feature test: phantom-draft categoriser skips a match whose `tournament_event_id` is set.

**API:** (lives with sub-project's API plan)

- POST with gzipped body → rows inserted.
- POST with tampered hash on one observation → that observation dropped, rest succeed, warning logged.
- POST with duplicate hash → 204, no new row.
- POST with empty/oversize array → 413.
- POST without auth headers → 401.

## Open Questions (resolve at implementation time)

1. Does `gameMeta.MatchToken` equal `matches.token` for participated matches? If yes, drop `tournament_match_token` column.
2. Does `RoundInfo.Matches[].EventID` match the participated `Description=Tournament:{N}` numeric ID? Confirm before sub-project 3.
3. On `main`, what's the current shape of classifier code? The `challenge_tab` layout (`App\Actions\Logs\ClassifyLogEvent`) may or may not exist — the plan should locate and either extend or create.
4. Where does phantom-league routing live on `main`? Plan should find the exact call site before writing the exclusion patch.
