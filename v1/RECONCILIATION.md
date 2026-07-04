# v1 Reconciliation Decisions

Cross-cutting decisions resolved after the section plans were drafted. Where a plan's inline text lags one of these, **this file is authoritative** — align to it during implementation.

## Client database — this repo IS v1 (single connection) — SUPERSEDES the dual-DB plan text

- **This repository becomes v1 in place.** 0.x is preserved by branching a `0.x` branch off `main` (done when needed) — **not** by a second on-disk database. The client is free to be fully destructive to get v1 into shape.
- **v1 uses the app's single default connection** (the existing NativePHP `nativephp` / `sqlite` connection). There is **no** separate `mymtgo.sqlite` file, **no** `mymtgo` connection, and **no** `DB_MYMTGO_DATABASE`. This **corrects** client-agent Task 1's "new DB file `mymtgo.sqlite` / 0.x left untouched for rollback" and every doc that says the v1 app runs on a separate `mymtgo` connection.
- **The gut was already executed** (2026-07-01, commits on the v1 branch): all 0.x display migrations were deleted and the ingest schema (`log_instances`/`log_cursors`/`log_events`) consolidated into a single migration on the default connection. Kept alongside: `cache`, `jobs`, `app_updates`, `tournament_observation_queue`.
- **What survives in the v1 tree:** the log **tail + classify** core (`Actions/Logs/*` — `IngestLogInstance`, `ClassifyLogEvent`, `DetectLogRotation`, `SealLogInstance`, `FindMtgoLogPath`, `GetLogFilePaths`, `RotationResult`), the tournament-observation broadcast, and the NativePHP shell/infra.
- **The frontend was deleted wholesale** (2026-07-02): the v1 UI is a from-scratch rebuild, so all of `resources/js`/`resources/css` went, replaced by a stub (`app.ts` + `Blank` home + `Error` page). The **overlay-window subsystem went with it** — data controllers, `ComputeDrawOdds`, `FetchOpponentLeagueArchetype`, window open/close actions, overlay settings/background endpoints. This **supersedes client-ui's "KEEP overlays — re-source + restyle"**: overlays are now *rebuilt* with the new UI, porting logic from the `0.x` branch. Every surviving PHP class autoloads clean; `npm run build` is green.
- **What was ALSO removed (not just the display layer):** the match-building / projection parser island — `ExtractMetaMessageEntries`, `DecodeMetaMessageText`, `ExtractGameResults`, `DetermineMatchResult`, `Parse{Game,Match}History`, `ParseGameLogBinary`, `GenerateDeckSignature`, `ParseDekFile`, `IsTransientWriteError`, `ConvertMtgoTimestamp`, `ExtractKeyValueBlock`, `Winrate`. They were orphaned once the pipeline that drove them was deleted. **Re-port these verbatim from the `0.x` branch / git history** when building client-agent Tasks 3–9 (compile → project). "Port verbatim, never rewrite" still holds — the source is now the `0.x` branch, not the in-tree file.
- **TODO — migration section needs a revisit.** [`migration/spec.md`](./migration/spec.md) + [`migration/plan.md`](./migration/plan.md) assume a read-only `legacy` connection to a separate `nativephp.sqlite` distinct from the v1 app DB. Post-pivot the upgraded install's 0.x display tables live in the *same* default DB the v1 app runs on, so the `legacy`-connection framing is stale. Rework the 0.x-import approach before building that section.

## Cloud deployment & stack
- **v1 cloud = a NEW PostgreSQL database on a new host.** 0.x is frozen on its own subdomain + existing DB; no server-side data migration, no tables added alongside `reported_matches`. (See `overview/spec.md` §8.)
- **Stack: Laravel 13 / PHP 8.3 / PostgreSQL**, DigitalOcean Spaces (`s3` disk) + Horizon (Redis). Corrects earlier "greenfield L12 / PHP 8.4 / MySQL" drafts.
- **Reuse code, not the DB.** Port `../api`'s Scryfall/Goatbots + archetype logic; build the cloud-of-record schema + Passport/PKCE auth fresh. The old Sanctum device-key model stays with 0.x.
- **Postgres schema choices:** `match_key`/`league_token` as native `uuid`; JSON payloads (`game_decks.deck_json`, card raw data, `game_timeline` context) as `jsonb`; conditional uniqueness as **partial unique indexes** (`... WHERE ... IS NOT NULL`); enums as string + `casts()` (not native PG enums); idempotent upserts via `INSERT ... ON CONFLICT`; backups via `pg_dump`.

## Schema ownership & identity
- **`decks` + `deck_versions` are owned by `cloud-pipeline`** (authoritative migrations). `catalog` **references** them, never re-creates them.
- **`opponents.mtgo_player_id` is nullable** with a partial unique index (`UNIQUE(mtgo_player_id) WHERE mtgo_player_id IS NOT NULL`) + username fallback — 0.x imports carry no player id. Same for the envelope `mtgo_player_id` on imports.
- **`mtgo_accounts`** uses partial unique indexes on nullable `user_id` / `mtgo_player_id` so a deactivation-released binding doesn't collide.

## Auth
- **Passport (`auth:api`) across the board** for the desktop/token side. No `auth:sanctum` interim, no 0.x device-key middleware in v1 — including the **archetype-catalog endpoint** (`catalog` Task 10), which is `auth:api`, tested with `Passport::actingAs()`.
- **Fortify** stays for the web session side (the website). Discord via Socialite (secret server-side only).

## HTTP semantics
- **Cross-user resource access → `404`** (no existence leak), implemented via **user-scoped route-model binding** (the row simply isn't found for a non-owner). This is the canonical rule; where a plan says `403` for cross-user ownership, read it as `404`.
- **Plan gate (free hitting paid) → `402`** (distinct from 404 so the client can surface an upsell).
- **Sink** validates auth + ownership only, always acks `200` on accepted content; ownership is enforced by the `{user_id}/{match_key}.json` namespace.

## Tournaments — global, + second contract/sink

- **Tournaments are GLOBAL** (pooled + deduped across all users; a Challenge is one shared event) — `tournaments`/`tournament_standings`/`tournament_events` are **not** `user_id`-scoped. This **corrects** any plan text scoping `tournaments` by user. Per-user participation links via `matches.tournament_id`. `leagues` remain per-user.
- **Second client→cloud contract:** [`contract/tournament-observations.md`](./contract/tournament-observations.md). A **second sink** `POST /api/tournament-observations` exists alongside the `{match}.json` sink, with a **different idempotency model** — server-computed `content_hash` dedupe (append-only, resend-free) vs the match sink's `file_version` last-write-wins.
- **Client is a pure broadcast lift from 0.x** — classify 7 tournament event types → queue → gzip-batch → POST. No client-side match/tournament correlation (all upstream). Only change vs 0.x: `Authorization: Bearer` (Passport) instead of device-key. See [`tournaments/spec.md`](./tournaments/spec.md).
- Server stamps `source` on `tournament_observations` (Arena additive later).

## Multi-source (Arena) seam — DECIDED: bake the cheap seam in v1

v1 ships MTGO only, but is built Arena-ready. Implementers apply these deltas everywhere the plans still show MTGO-only keying:
- **`source` column** (`mtgo`|`arena`; v1 value = `mtgo`) on **`matches`** and **`match_files`**.
- **Identity is source-scoped:** `UNIQUE(user_id, source, match_key)` on both tables (replaces the plans' `UNIQUE(user_id, match_key)`). Test factories/creates must pass `source => 'mtgo'`.
- **Sink object path includes source:** `{user_id}/{source}/{match_key}.json` (plans showing `{user_id}/{match_key}` or `matches/{user_id}/{match_key}/...` gain the `source` segment).
- **`{match}.json` envelope carries `source`** (client compiler stamps `mtgo`); `ProjectedMatchData` gains a `source` field.
- **Card stats stay keyed on `oracle_id`** — unchanged; the cross-client lingua franca (Scryfall carries `arena_id` for a future Arena catalog mapping).
- **Deferred to v2 (non-goal now):** the Arena ingest agent (different log format; cross-platform) and any `mtgo_*` → generic rename — build against real Arena logs, not guessed. See `overview/spec.md` §9, `contract/spec.md` "Arena readiness".
