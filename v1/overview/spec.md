# Overview — v1 Cloud-of-Record

> Extracted from the v1 architecture brainstorm (2026-06-30). The map for all sections — see [`../README.md`](../README.md).

## 1. Problem

Users want their MTGO data across multiple devices — desktop + laptop (both Windows, both ingesting), and eventually a phone for viewing stats. Today all data is local SQLite, derived by projecting MTGO log files. Local-first does not span devices: each machine holds a partial, non-authoritative DB, and reconciling them is a two-database merge nightmare.

**Hard constraints that shape every option:**

- **Ingestion is forced-local.** No MTGO API exists. Only a process on the gaming machine can read the logs + XML. The local client can never go away.
- **MTGO is Windows-desktop only.** No device can *play* except a Windows machine. Every non-gaming device is a pure viewer.
- **Multiple machines, but never simultaneously.** A user games on desktop *or* laptop at a given moment — never the same match on both. Across time both produce data into one account.
- **MTGO rotates logs daily.** On-disk re-scan only reaches back as far as MTGO's retention window.
- **Raw logs must never leave the device.** They contain opponent handles, chat, and third-party data — uploading them is a privacy breach.

## 2. Decision

**The cloud is the system of record. The local app becomes a thin ingest agent.** Every device — desktop, web, future mobile — is a client of one API. Views are API queries, so they are built once and work everywhere.

This is the opposite of "local is source of truth, cloud is a replica." Multi-machine is what makes it correct: with two partial local DBs, neither is authoritative and you must merge them. With cloud-as-record, both machines just push to one account; because they produce *different* matches, it is a **union, not a conflict**.

The "rethink the API / start again" fear is overblown: the API is Laravel/PHP and the projection engine is Laravel/PHP, so relocating logic is *moving code*, not rewriting it. The match-building logic moves server-side as a background worker.

## 3. Core principles

- **One writer per match.** A match happens on one machine in one sitting → no write-conflict, ever. Idempotency handles the only overlap (a re-pushed match).
- **The file is the record; the DB is a derivation.** Clients upload a per-match JSON file. The queryable match/games/stats tables are *built from* those files by a worker and can be rebuilt at any time.
- **Idempotent upsert, keyed by match identity.** No push is ever final. Late events → recompile → re-push → last-write-wins. "Done" is a *when-to-bother-pushing* (perf) decision, not a correctness gate.
- **The client write-path cannot fail.** The upload endpoint is a dumb authenticated sink: accept the file, store it, ack. No schema validation at the door.
- **Errors move, they don't vanish.** Validation/build failures become *async, server-side, non-blocking, retryable* — they can't block ingestion or lose data. This requires a worker error path + dead-letter state + observability, not an assumption of zero errors.
- **Capture is the floor.** The uploaded file lands safely before any processing; bad processing never loses the user's data.
- **Privacy boundary:** raw, verbatim, chat-and-opponent-laden logs → **local only, never leave the device**. Structured, minimal, projected match data → **cloud**.

## 4. Architecture

```
LOCAL (per gaming machine)                       [client-agent, client-auth, client-ui]
  tail logs ─┬─► live overlay  (in-memory off log events; instant; works offline)
             └─► compile {match-key}.json ─► outbox ─► push
                 (private local raw archive — keep-forever, only to fix compilation bugs)
                              │ authenticated upload (Bearer token, PKCE-issued)
                              ▼
CLOUD  (../api, DigitalOcean droplet)             [cloud-pipeline, cloud-api, cloud-auth, catalog, ops]
  dumb sink: accept file, ack "thanks", enqueue            ← cannot fail validation
       │
       ▼
  worker: {match}.json ─► match + games + archetype + stats   ← reprocessable, no client/raw needed
       │ on DB commit
       ▼
  emit "match.logged" (Reverb, per-user channel) ──────────► all devices refetch
       ▲
  read API (JSON): matches / decks / stats / cards / archetypes / shareable pages
       │
  desktop · web · future mobile   (pure read clients + catch-up fetch on reconnect)

  auth: Discord OAuth + email/password; MTGO username/player-id read from logs (non-editable)
```

Three tiers of durability:

1. **Local raw archive** (private floor, keep-forever) — fixes log→json *compilation* bugs.
2. **Cloud `{match}.json` files** (system of record) — server reprocesses these to fix *build-layer* bugs (archetype, stats, schema) with no client involvement.
3. **In-memory overlay** (live) — decoupled from everything below.

## 5. Phasing

**Build order — base first, UI follows (form follows function).** Get the plumbing correct end-to-end before investing in design; the v1 UI may initially just render raw JSON from the API.

- **v1 core:** thin local agent (compile + outbox + push), raw archive (keep-forever), dumb sink, build worker, read API, auth (Discord OAuth + email/password, strict 1:1 binding, custom-protocol redirect), 0.x import, functional web + desktop read views (design can lag). Realtime via Reverb for fresh views.
- **Likely later:** restyle-to-0.x pass + incremental UI polish (redesign dead — decision 2026-07-09, see [`../client-ui/spec.md`](../client-ui/spec.md)); live second-screen of an *active* game (realtime game-state relay, not just record notifications); mobile app; public shareable pages polish.

## 6. Codebase strategy

**Don't gut the 0.x codebase — port the ingestion core, delete the display layer.**

- **Crown jewels — port verbatim (never rewrite):** log tailing, `LogCursor`/`LogInstance` (rotation/shrink handling), event classification, the `MetaMessage` binary decoder, match/game projection, archetype detection, deck XML parsing / signature. Years of absorbed MTGO lies and traps (`known_mtgo_lies_and_traps.md`) live here; a from-scratch rewrite would reintroduce every edge case. In v1 this logic becomes the **client compiler** (events → `{match}.json`); the **server worker** (in `../api`) reuses the same logic to build from files.
- **Delete hard (display / local-DB layer):** the local queryable schema (matches / games / stats / decks tables + migrations) and every controller/page that renders from the local DB → these become API-fed.
- **Cut mechanic** — decided by how coupled ingestion is to display: cleanly separated → delete-in-place on the `1.x` branch; tangled → fresh NativePHP skeleton + port the ingestion classes over. (Assess before committing.) Either way the log-parsing logic moves **verbatim**.

## 7. Non-goals

- Playing or ingesting MTGO anywhere but a Windows machine.
- **Multiplayer / >2-player formats.** v1 supports **2-player (1v1) formats only** — schema, `{match}.json`, and stats are all shaped to a single opponent.
- The cloud re-running log *parsing* (only match *building* from already-compiled files).
- Bidirectional sync / conflict resolution (the one-writer-per-match invariant removes the need).
- **Rewriting the ingestion core.** The log→match logic is ported verbatim from 0.x.

## 8. Cloud deployment & stack (v1)

Clarifies the earlier "greenfield vs `../api`" ambiguity that surfaced during planning:

- **v1 cloud runs on a NEW database.** 0.x installs keep working untouched by **freezing the 0.x API on its own subdomain + its existing DB**; v1 takes a new host. No forced server-side data migration, no dual-model tables. (The client 0.x→v1 import reads the *local* `nativephp.sqlite` and pushes to the new v1 API — see [`../migration/spec.md`](../migration/spec.md).)
- **Stack: Laravel 13 / PHP 8.3 / PostgreSQL**, DigitalOcean Spaces (`s3` disk) + Horizon (Redis). This is the real `../api` deployment stack — **not** the earlier draft assumption of L12 / PHP 8.4 / MySQL. v1 switches the DB to **Postgres** (free to do — new DB, nothing to migrate).
- **Reuse code, not the DB.** `../api` already contains proven, reusable logic — Scryfall/Goatbots catalog ingestion, archetype classification — which is **ported into v1** (same crown-jewels move as the client core). What's built fresh: the cloud-of-record schema (new Postgres DB) and the auth model (**Passport OAuth2 + PKCE**, replacing the old Sanctum device-key model, which lives on with 0.x).
- **Postgres schema choices** (exploit, don't just swap the driver): `match_key` as native `uuid`; JSON payloads (`game_decks.deck_json`, card data, timeline context) as `jsonb`; the nullable-`user_id` archetype global/owned rule and other conditional uniqueness as **partial unique indexes**; enums as string + `casts()` (portable); idempotent upserts via `INSERT ... ON CONFLICT`.

## 9. Multi-source (Arena) readiness

v1 ships MTGO only, but is built so a future **MTG Arena** source is a union, not a rewrite. Cloud-of-record makes the sink/worker/schema/API source-agnostic; only the ingest agent is platform-specific.

- **Baked now (cheap seam):** a `source` (`mtgo`|`arena`) discriminator in the `{match}.json` contract + `matches`/`match_files`, with identity scoped `UNIQUE(user_id, source, match_key)` and the sink path `{user_id}/{source}/{match_key}.json`. Card stats stay keyed on `oracle_id` (the cross-client lingua franca; Scryfall carries `arena_id` too). See [`../contract/spec.md`](../contract/spec.md).
- **Deferred to v2 (non-goal now):** the Arena ingest agent (different log format; Arena is cross-platform — Mac/mobile — breaking MTGO's Windows-only assumption) and any `mtgo_*` → generic rename. Build these against real Arena logs, not guessed abstractions.

## Cross-references

- Contracts: [`../contract/spec.md`](../contract/spec.md) (`{match}.json`) + [`../contract/tournament-observations.md`](../contract/tournament-observations.md) (tournament observation broadcast)
- Tournaments: [`../tournaments/spec.md`](../tournaments/spec.md) (client broadcast + cloud projection; **global** tournaments)
- Client: [`../client-agent/spec.md`](../client-agent/spec.md), [`../client-auth/spec.md`](../client-auth/spec.md), [`../client-ui/spec.md`](../client-ui/spec.md)
- Cloud: [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md), [`../cloud-api/spec.md`](../cloud-api/spec.md), [`../cloud-auth/spec.md`](../cloud-auth/spec.md)
- Reference data & policy: [`../catalog/spec.md`](../catalog/spec.md), [`../migration/spec.md`](../migration/spec.md), [`../ops/spec.md`](../ops/spec.md)
