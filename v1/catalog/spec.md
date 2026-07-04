# Catalog — Cards, Prices, Archetypes

> Extracted from the v1 architecture brainstorm (2026-06-30). Lives in the `../api` project. Reference data served via [`../cloud-api/spec.md`](../cloud-api/spec.md).

> **Stack (v1):** Laravel 13 / PHP 8.3 / PostgreSQL, DigitalOcean Spaces (`s3` disk) + Horizon (Redis) — see [`../overview/spec.md`](../overview/spec.md) §8. v1 cloud = a NEW Postgres database on a new host; 0.x is frozen on its own subdomain + existing DB (no server-side migration). The Scryfall/Goatbots ingestion + archetype logic is **ported** from `../api`; the catalog schema is built fresh. Postgres notes: raw card payloads as `jsonb`; the `archetypes` nullable `user_id` (global vs owned) uses a **partial unique index** where per-owner uniqueness is needed; enums as string + `casts()`, not native PG enums; idempotent upserts via `INSERT ... ON CONFLICT`.

## Cards

- Card data served **via the API**. (This reverses the earlier "client downloads the full catalog + prices itself" lean — fine under cloud-of-record.)
- **Catalog sources (server-side ingestion):** card data (oracle id, names, types, images) from **Scryfall**; prices **and** the MTGO CatalogID↔card mapping from **Goatbots**. A scheduled server-side job pulls both into `cards` + prices. The MTGO-id mapping matters because gameplay events reference MTGO CatalogIDs, not oracle ids (cf. the warp-printing-divergence trap where casts logged under a different printing's CatalogID diverge from the oracle).
- The **live overlay must not** depend on a live API call for card data; it gets what it needs (the current decklist's cards) from the **local MTGO XML** (see [`../client-agent/spec.md`](../client-agent/spec.md)), preserving the offline-overlay promise.

**Schema**
- `cards` — Scryfall/MTGO catalog (mtgo_id, oracle_id, scryfall_id, name, type, rarity, color_identity, mana_cost, image). Plus a prices representation (Goatbots). Served via API.

## Archetypes

- **Catalog comes from the API** — but must be **cached locally** so the opponent-scout window can classify archetypes **live, mid-game, offline** (it can't wait for server-on-complete). The local mirror is `archetype_catalog` (see [`../client-agent/spec.md`](../client-agent/spec.md)).
- **Detection runs in two places:** local-live (for the scout overlay) and authoritatively in the build worker (for the stored record — see [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md)). The stored archetype is **user-overridable** as today.
- **Global vs owned:** manual archetypes linked by a **nullable `user_id`** (null = global; owned otherwise), admin-promotable to global. Archetype uuids are shared/global across users (verified: the same uuid recurs across different accounts' matches).

**Schema**
- `archetypes` — id, uuid, name, format, color_identity, manual, is_fallback, incomplete, merged_into_id, source_match_id, **`user_id` (nullable → null = global, owned otherwise; admin-promotable)**.
- `archetype_decks`, `archetype_deck_cards` — variants + cardlists (unchanged shape from 0.x).
- `match_archetypes` — match_id, archetype_id, archetype_deck_id, confidence, **`is_opponent` bool** (replaces 0.x `player_id`), `UNIQUE(match_id, is_opponent)`.
