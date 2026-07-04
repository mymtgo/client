# Cloud Pipeline — Sink, Worker, Match Schema, Re-derivation

> Extracted from the v1 architecture brainstorm (2026-06-30). Lives in the `../api` project (DigitalOcean droplet). Consumes [`../contract/spec.md`](../contract/spec.md); served by [`../cloud-api/spec.md`](../cloud-api/spec.md).

The cloud is the **system of record**: clients upload per-match JSON files; a worker builds the queryable tables from them. Re-running the worker over stored files rebuilds everything.

## 1. Dumb sink

Authenticated upload endpoint. Validates **auth + ownership only** (file namespaced per user + source + match-key: `{user_id}/{source}/{match_key}.json` — see [`../ops/spec.md`](../ops/spec.md)), never content/schema. Stores the file (object storage — DigitalOcean Spaces) and enqueues a build job. **Always acks.** This is what decouples client versions from server schema: an old client keeps dumping its file shape and the server builder evolves underneath. The write-path cannot fail on content.

## 2. Build worker

Consumes `{match}.json` → builds/updates match + games + timeline + card stats + archetype linkage (**idempotent upsert on `match_key`**). Must handle partial/sparse/malformed files gracefully (e.g. 0.x imports with no per-game data — see [`../migration/spec.md`](../migration/spec.md)). On unrecoverable failure → **dead-letter / needs-attention state, not data loss**. On successful commit → emit the `match.logged` notification (see [`../cloud-api/spec.md`](../cloud-api/spec.md)). Re-running the worker over all stored files is the **re-derivation** mechanism.

The match-building logic (event→match projection) is the **same code as the client compiler**, reused server-side. See [`../overview/spec.md`](../overview/spec.md) §6.

## 3. Cloud schema — match record (this section's tables)

Clean v1 migrations on a **NEW Postgres database** (Laravel 13 / PHP 8.3 / PostgreSQL, DigitalOcean Spaces `s3` disk + Horizon/Redis — see [`../overview/spec.md`](../overview/spec.md) §8), **born in the 1v1 target shape**. This is *not* additive to the live 0.x DB; 0.x is frozen on its own subdomain + existing DB. Code is reused (the event→match projection is ported), the schema is built fresh. All gameplay tables scoped by `user_id`. (Identity tables in [`../cloud-auth/spec.md`](../cloud-auth/spec.md); cards/archetypes in [`../catalog/spec.md`](../catalog/spec.md).)

**Postgres schema choices:** `match_key` / `league_token` as native `uuid`; JSON columns (`game_decks.deck_json`, `game_timeline` context) as `jsonb`; conditional uniqueness as **partial unique indexes** (`... WHERE ... IS NOT NULL`); enums as string + `casts()` (not native PG enums); idempotent upserts via `INSERT ... ON CONFLICT`.

**File inbox (work queue)**
- `match_files` — id, `user_id`, `source` (`mtgo`|`arena`; v1 = `mtgo`), `match_key`, `object_path` (blob in Spaces, path includes source — see sink), `file_version`, `status` (received / processing / built / dead-letter), `last_processed_version`, `error`, `received_at`, `processed_at`. **`UNIQUE(user_id, source, match_key)`**. **This is the source of record; everything below is derived.**

**Match record (derived by the worker)**
- `matches` — id, `user_id`, `mtgo_account_id`, `source` (`mtgo`|`arena`; v1 = `mtgo`), `match_key` (= **MatchToken uuid**) **`UNIQUE(user_id, source, match_key)`** (the constraint the current 0.x schema lacks; source-scoped for the Arena seam), `mtgo_id` (MatchID int, attribute), `league_token`, format, match_type, outcome (enum), `outcome_source` (resolved|manual|unknown), state, started_at, ended_at, `deck_version_id`, `league_id`, `tournament_id`, `opponent_id`, notes, imported, `source_file_version`. **Dropped vs 0.x:** `result`, `games_won`, `games_lost` (derived from games).
- `opponents` — id, **`mtgo_player_id` (stable MTGO numeric id — the real key; **nullable** with a **partial unique index** `UNIQUE(mtgo_player_id) WHERE mtgo_player_id IS NOT NULL`)**, username (display attribute; may change over time — also the **fallback identity** when the player id is absent), `matches.opponent_id` FK. `mtgo_player_id` is nullable because 0.x imports carry no player id; those rows key on `username`. Verified: `PlayerIds` gives stable numeric ids for both players, identical + same order on both sides → key on the id, not the rename-prone handle. Global scope; handles are public knowledge (see [`../ops/spec.md`](../ops/spec.md)).
- `games` — id, match_id, mtgo_id, won (nullable), started_at, ended_at, turn_count, local_on_play, local_mulligans, opp_mulligans, local_dice, opp_dice, local_instance, opp_instance. **Dropped:** `game_player`/`players` pivots, `starting_hand_size`.
- `game_decks` — id, game_id, `is_opponent` bool, `deck_json` (`jsonb`). `UNIQUE(game_id, is_opponent)`.
- `card_game_stats` — as current 0.x (oracle_id, quantity, kept, seen, played, won, is_postboard, sided_out, opponent, pregame_*, kicked/flashback/madness/evoked/activated), `UNIQUE(game_id, oracle_id, opponent)`. **Dropped:** `cast` (dup of played).
- `game_timeline` — id, game_id, action, timestamp, player, context.

**League / tournament**
- `leagues` — per-user (a league run is personal), scoped by `user_id`.
- `tournaments` (+ `tournament_standings`, `tournament_events`/pairings) — **GLOBAL, not user-scoped** (a Challenge is one shared event; pooled + deduped across all users). Built by the **tournament-observation projection**, not from `{match}.json`. Per-user participation links via `matches.tournament_id`. See [`../tournaments/spec.md`](../tournaments/spec.md) + [`../contract/tournament-observations.md`](../contract/tournament-observations.md). (The tournament-observation **sink** — `POST /api/tournament-observations`, content_hash dedupe, jsonb — is a second sink alongside the `{match}.json` sink.)

**Decks** (shared with catalog — **cloud-pipeline OWNS these migrations**; [`../catalog/spec.md`](../catalog/spec.md) / catalog plan REFERENCES them, never re-creates them)
- `decks` — id, `user_id`, mtgo_id (NetDeckId), name, format, color_identity, original_name, cover_id, archetype_id.
- `deck_versions` — id, deck_id, signature, modified_at. **Cloud owns version dedup / never-regress** (was local in 0.x): keyed on the MTGO XML `modified_at` (deterministic across machines, not a local clock); same signature → reuse, newer `modified_at` → new version, older → ignore.

## 4. Re-derivation strategy

- **Build-layer bugs** (archetype, stats, aggregation, schema): re-churn the stored `{match}.json` files server-side. No client involvement, no raw logs.
- **Compilation-layer bugs** (log → json): require the client's keep-forever **local raw archive** (see [`../client-agent/spec.md`](../client-agent/spec.md) §3) — the client recompiles and re-pushes fresh files.
- The projected DB is always safe in the cloud regardless; what the raw archive buys is *bug-fix reach on old data*, not the data itself.
- **Manual outcomes are preserved** across re-derivation because `outcome_source: "manual"` is baked into the file, not stored only in the DB.
