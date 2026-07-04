# Tournaments — Observation Broadcast + Cloud Projection

> Second client→cloud contract, sibling to the `{match}.json` contract. Wire shape is authoritative in [`../contract/tournament-observations.md`](../contract/tournament-observations.md). Client side is a **lift from 0.x**; cloud side does all correlation.

## Shape of the feature

A per-match `{match}.json` only holds **the local player's** match plus a thin tournament reference (`mtgo_event_id`, `round`, `name`). Full tournament data — every player's standing, pairings, ranks, tiebreakers, elimination, final results — lives **outside any single match** and is only visible by observing MTGO's tournament state in the logs. So the client **broadcasts** those observations; the cloud aggregates them into a **global** tournament record.

## Client side — pure broadcast (lift from 0.x)

**The client does no matching or correlation.** It classifies tournament log events and pings them up. That's it. All linking (observation ↔ match, standings, bracket) happens **upstream** in the cloud.

- **Lift from 0.x verbatim:** the classifier branches for the 7 tournament event types, the `tournament_observation_queue` table, and the `ShipTournamentObservations` batch/gzip sender job. See 0.x `docs/superpowers/specs/2026-04-21-tournament-observations-design.md` + `app/Jobs/ShipTournamentObservations.php`, `app/Actions/Tournaments/EnqueueTournamentObservations.php`, `app/Models/TournamentObservationQueue.php`.
- **Only change vs 0.x: auth.** `POST /api/tournament-observations` now carries `Authorization: Bearer <passport token>` (drop `X-Device-Id`/`X-Api-Key`). Everything else — payload shapes, the 7 event types, batching (≤200), 30s/50-row flush, gzip, backoff — is unchanged.
- **Dropped from the 0.x design:** the client no longer stamps `tournament_event_id`/`tournament_round` on matches or tries to exclude tournament matches from phantom leagues *for correlation purposes*. The `{match}.json` still carries its thin `tournament` block (plain field extraction from the join `Description=Tournament:{N} Round:{M}`), but the client does **not** attempt to build tournament state or link standings — the cloud does that from the observation stream + match files.
- Runs off the same log tail as the compiler (the observation queue is fed by the same classified `LogEvent`s). Resend-free: the server dedupes, so re-push on reconnect/foreground is safe.

## Cloud side — sink + projection + read

- **Dumb sink:** `POST /api/tournament-observations` (Passport `auth:api`, gzip auto-decompress). Validates auth + shape only (never tournament semantics), stores each observation **append-only** as `jsonb`, computes `content_hash = sha1(event_type + '|' + canonicalJson(payload))` and **dedupes on it** (resend-free), always acks `204`. This is a *second* sink alongside the `{match}.json` sink, with a **different idempotency model** (content_hash dedupe vs the match sink's `file_version` last-write-wins).
- **Projection worker:** folds the observation log into the **global** tournament tables — `tournaments`, `tournament_standings`, `tournament_events`/timeline, and pairings. Re-running over stored observations re-derives them (same guarantee match files have). This is where all correlation happens: observation ↔ match via `match_token` (`tournament_round_info.Matches[].EventToken` == a match's `match_key`); pooled + deduped across every submitting client.
- **Read endpoints:** serve tournament standings / pairings / bracket via the read API.
- **Port the 0.x API side** (`../api` `docs/superpowers/plans/2026-04-21-tournament-observations-api.md` + design), swapping device-key auth → Passport and MySQL → Postgres (`jsonb`).

## Scope — tournaments are GLOBAL, not user-scoped

A Challenge is **one shared event for everyone**; observations from all users enrich one record, deduped. So `tournaments` / `tournament_standings` / `tournament_events` are **global** (no `user_id` scope) — this **corrects** the earlier `cloud-pipeline` note that had `tournaments` scoped by `user_id`. Per-user linkage is via `matches.tournament_id` (a user's participation), not by scoping the tournament itself. (`leagues` stay per-user — a league run is personal.)

## Source seam

Observation payloads are unchanged (0.x lift) and carry no `source`. The server stamps `source` (`mtgo`) on the `tournament_observations` table + derived tournament rows so a future Arena source is additive (Arena events are far off — v2).

## Cross-references

- Wire contract (authoritative): [`../contract/tournament-observations.md`](../contract/tournament-observations.md)
- Match contract: [`../contract/spec.md`](../contract/spec.md)
- Cloud schema/worker: [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md) · Read API: [`../cloud-api/spec.md`](../cloud-api/spec.md) · Auth: [`../cloud-auth/spec.md`](../cloud-auth/spec.md)
- Client agent (shares the log tail): [`../client-agent/spec.md`](../client-agent/spec.md)
