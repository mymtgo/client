# Tournament Observations — Client → Cloud Contract

> Sibling to [`spec.md`](./spec.md) (the `{match}.json` contract). Where that contract carries **one user's match**, this one carries **shared tournament state** (standings, pairings, rounds, bracket) that no single match file can hold. The **client agent** produces these; the **cloud worker** folds them into the global `tournaments` / `tournament_standings` / `tournament_events` tables (see `../cloud-pipeline/spec.md`).
>
> **This is a port, not a new design.** The 0.x client already emits these exact payloads to `POST /api/tournament-observations`. v1 keeps the payloads **byte-identical** and changes only the transport auth (device-key → Passport bearer) and the server-side storage engine (MySQL → Postgres). If you are the client agent: **keep sending what you already send**; only the auth header changes.

## Why this exists (read first)

The cloud is the system of record and builds everything from files the client pushes. A per-match `{match}.json` only ever contains **the local player's** match plus a thin tournament reference (`mtgo_event_id`, `round`, `name`). Full tournament data — every player's standing, every pairing, ranks, tiebreakers, elimination, final results — lives **outside any single match** and is only visible by observing MTGO's tournament state directly. So the client scrapes that state and pushes it as **observations**. Reprocessing the stored observations re-derives the tournament tables (the same re-derivation guarantee match files have).

## Transport

```
POST /api/tournament-observations
Authorization: Bearer <passport access token>     # v1: Passport auth:api (was X-Device-Id + X-Api-Key in 0.x)
Content-Type: application/json
Content-Encoding: gzip                              # optional; server auto-decompresses
```

- **Body = a JSON array** of observation objects (NOT wrapped in an envelope object).
- **Batch:** min 1, max **200** observations per request.
- **Response:** `204 No Content` on accept. The endpoint is a dumb sink — it validates auth + shape only, never tournament semantics, and always acks accepted batches.
- **gzip** the body for large sync/round payloads; set `Content-Encoding: gzip`.

## Observation object

Each element of the array:

```jsonc
{
  "tournament_token": "<uuid|null>",   // MTGO EventToken. Nullable, but ≥1 of token/match_token REQUIRED.
  "match_token":      "<uuid|null>",   // per-match token; used to link round-level events to matches.
  "event_type":       "tournament_sync",   // one of the 7 enum values below
  "payload":          { /* MTGO-shaped, per event_type — see below */ },
  "client_observed_at": "<iso8601>"    // when the client saw this state; drives ordering + timestamps
}
```

- **`content_hash` is NOT sent by the client.** The server computes it as `sha1(event_type + '|' + canonicalJson(payload))` (keys sorted deep, arrays in order, `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`) and dedupes on it. **Consequence: resend freely.** Identical observations are idempotently ignored, so the client should re-push on reconnect / app-foreground (at-least-once delivery; the server makes it exactly-once).
- **Validation rules** (server rejects the batch on failure):
  - `event_type` ∈ the 7 values; `payload` required array/object; `client_observed_at` required date.
  - at least one of `tournament_token` / `match_token` present per observation.
- **`payload` is stored verbatim as `jsonb`.** Keep MTGO's original field names and casing (PascalCase) exactly as below — the server reads those keys.

## Event types & payloads

Payloads use MTGO's native field names. Send them raw as scraped.

### 1. `tournament_sync` — tournament identity + roster
Emit once on join / first observation of the event. Establishes the tournament and its player list.
```jsonc
{
  "EventID": 12840477,                    // MTGO numeric event id
  "EventToken": "4b92a89a-...-35bff1357ec9", // == tournament_token
  "ParentToken": "parent-uuid",           // parent event (brackets/rounds); null if none
  "Description": "Modern Challenge 32",    // human name
  "GameStructureCd": "CMODERN",           // format code (CPAUPER, CMODERN, CSTD; D*/S6* = draft/sealed)
  "PlayerEventState": 12,                  // MTGO state enum (int)
  "StartDate": "2026-04-21T15:47:00+00:00",
  "EndDate":   "2026-04-22T15:48:25+01:00",
  "Players": [
    { "LoginID": 100, "PlayerName": "Alice", "AvatarID": 1 },
    { "LoginID": 200, "PlayerName": "Bob",   "AvatarID": 2 }
  ],
  "TournamentSyncData": { "NumberOfRounds": 3 }
}
```

### 2. `tournament_round_info` — pairings for a round
Emit when a round's pairings are posted. One observation per round; `Matches[]` is every pairing you can see.
```jsonc
{
  "Number": 2,                             // round number
  "Matches": [
    {
      "EventID": 111,                      // MTGO match id
      "EventToken": "match-1",             // per-match token (also set as the observation's match_token)
      "StartDate": "2026-04-21T15:47:00+00:00",
      "EndDate": null,
      "Players": [                         // 1 player ⇒ bye
        { "LoginID": 100, "PlayerName": "Alice" },
        { "LoginID": 200, "PlayerName": "Bob" }
      ]
    }
  ],
  "ByeList": []                            // may be present; currently informational
}
```

### 3. `tournament_round_result` — standings + results after a round
Emit when round results post. Carries ranks, tiebreakers, and per-opponent game counts (used to backfill match scores).
```jsonc
{
  "Round": 1,
  "Token": "<tournament_token>",
  "Results": [
    {
      "LoginID": 100,
      "Rank": 1,
      "Points": 3,
      "GameWinPercentage": "0.6667",
      "OpponentGameWinPercentage": "0.3333",
      "OpponentMatchWinPercentage": "0.0000",
      "OpponentResults": [
        { "LoginID": 200, "Round": 1, "Win": 2, "Loss": 1, "Draw": 0, "Bye": 0 }
      ]
    }
  ]
}
```
- `Win`/`Loss`/`Draw` are **game** counts vs that opponent; the server derives match W/L/D from them and backfills the pairing's game scores.

### 4. `tournament_player_eliminated`
```jsonc
{ "Token": "<tournament_token>", "LoginID": 100, "EliminationReason": 1 }
```
- Server timestamps elimination from `client_observed_at`.

### 5. `tournament_state_changed`
```jsonc
{ "from": "TournamentNotJoinedBetweenRoundsState", "to": "TournamentCompletedState" }
```
- Only `to === "TournamentCompletedState"` is actioned (marks the tournament finalized); other transitions are stored but inert. Safe to send all transitions.

### 6. `tournament_ended`
```jsonc
{ "Token": "<tournament_token>", "EndDate": "2026-04-21T15:43:31+00:00", "ID": 12840474 }
```
- Finalizes + sets end time (falls back to `client_observed_at` if `EndDate` missing).

### 7. `tournament_match_state_changed`
- Accepted by the enum, currently **no server handler** (stored, marked processed, inert). Reserved. Send if cheap; not required.

## Emission guidance (cadence)

| When | Emit |
|---|---|
| First time you see the event (join) | `tournament_sync` |
| A round's pairings appear | `tournament_round_info` (that round) |
| A round's results post | `tournament_round_result` (that round) |
| A player is dropped/eliminated | `tournament_player_eliminated` |
| Event completes | `tournament_state_changed` (`to: TournamentCompletedState`) and/or `tournament_ended` |
| On reconnect / app-foreground | **re-push recent observations** — dedup makes this free and closes gaps from missed pushes |

- Batch opportunistically (≤200). Ordering across batches doesn't matter — the server processes per-tournament and folds idempotently — but **do include `client_observed_at`** accurately; it drives timestamps and last-observed ordering.
- Once a tournament is finalized server-side, late observations for it are discarded. Push terminal events promptly.

## Notes for the client agent

- **Nothing about the payloads changed from 0.x.** If your current build already posts to `/api/tournament-observations`, the only required change is the `Authorization: Bearer <passport token>` header (drop `X-Device-Id`/`X-Api-Key`).
- Tournament data is **global** on the cloud side (a Challenge is one shared event for everyone) — your observations enrich a shared record, deduped across all submitting clients. Don't assume your push is the only source.
- These observations are **separate from `{match}.json`**. A match you played in a tournament still produces its own match file; the observation stream is what supplies the standings/bracket around it. The two are linked server-side via `match_token` (`tournament_round_info.Matches[].EventToken` == the match's `match_key`).
