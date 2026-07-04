# v1 Cloud-of-Record — Design & Plans

v1 re-architects MyMTGO from local-first SQLite to **cloud-of-record**: the local app becomes a thin ingest agent, and a cloud API (Laravel, on a DigitalOcean droplet — the `../api` project) owns all queryable data. Every device is a client of one API.

Each section below has a `spec.md` (settled design, extracted from the brainstorm) and a `plan.md` (implementation plan). Sections are split so each is a buildable, independently-testable unit.

> **Source brainstorm:** `docs/superpowers/specs/2026-06-30-v1-cloud-architecture-design.md` — the monolith these sections were extracted from (superseded; keep for history).

## Sections

| Section | Deployable | Covers |
|---|---|---|
| [`overview`](./overview/spec.md) | — | Problem, decision, core principles, architecture, phasing, non-goals, codebase + UI strategy |
| [`contract`](./contract/spec.md) | shared | The `{match}.json` contract (client produces, worker consumes) |
| [`client-agent`](./client-agent/spec.md) | client | Thin local schema, compiler (port ingestion core), outbox, push triggers, raw archive, outcome resolver |
| [`client-auth`](./client-auth/spec.md) | client | Auth window, OAuth2 PKCE flow, token storage/refresh, username-mismatch guard |
| [`client-ui`](./client-ui/spec.md) | client | JSON-first pages, overlay-window keep-list, redesign scope (deferred) |
| [`cloud-pipeline`](./cloud-pipeline/spec.md) | api | Cloud schema, dumb sink, build worker, re-derivation |
| [`cloud-api`](./cloud-api/spec.md) | api | Read API endpoints + Reverb realtime |
| [`cloud-auth`](./cloud-auth/spec.md) | api | Passport OAuth2 server, Discord + email/password, identity binding |
| [`catalog`](./catalog/spec.md) | api | Scryfall + Goatbots ingestion (cards, prices, MTGO-id map), archetypes |
| [`migration`](./migration/spec.md) | client | 0.x → `{match}.json` import mapper |
| [`ops`](./ops/spec.md) | api | Authorization, entitlement (free/paid), account deletion, backup, limits, privacy |
| [`tournaments`](./tournaments/spec.md) | client + api | Tournament-observation **broadcast** (client = lift from 0.x, no correlation) + cloud sink/projection/read. Tournaments are **global**. 2nd contract → [`contract/tournament-observations.md`](./contract/tournament-observations.md) |

## Build order

Base first, UI follows (form follows function). Roughly:

1. **`contract`** — lock the shared `{match}.json` shape first; both sides depend on it.
2. **`cloud-pipeline`** + **`cloud-auth`** — sink accepts files, worker builds records, auth issues tokens. (Cloud can be exercised with hand-crafted files before the client exists.)
3. **`client-agent`** + **`client-auth`** — port the ingestion core, compile, authenticate, push.
4. **`cloud-api`** + **`catalog`** — serve the built data; populate card/price/archetype reference data.
5. **`migration`** — import 0.x history through the same pipeline.
6. **`ops`** — authorization, entitlement, deletion, backup, limits (cross-cutting; enforced as endpoints land).
7. **`client-ui`** — JSON-first views; visual redesign is a later, separate pass.
8. **`tournaments`** — client broadcast producer (piggybacks the `client-agent` log tail); cloud observation sink + projection + read (after `cloud-pipeline`). Mostly a 0.x lift.

## Cloud deployment & stack

- **v1 cloud = new PostgreSQL database on a new host.** 0.x is frozen on its own subdomain + existing DB, so old installs keep working with no server-side migration.
- **Stack: Laravel 13 / PHP 8.3 / PostgreSQL**, DigitalOcean Spaces (`s3`) + Horizon (Redis). (Corrects the drafts' L12 / PHP 8.4 / MySQL assumption — see [`overview/spec.md`](./overview/spec.md) §8.)
- **Reuse `../api` code, not its DB:** port the proven Scryfall/Goatbots + archetype logic; build the cloud-of-record schema + Passport/PKCE auth fresh.
- Cloud plans currently carry the old stack in their Global Constraints — being corrected in reconciliation.

## Status

Design settled (all open questions resolved as of 2026-07-01). All 9 plans authored. Reconciliation in progress: aligning cloud plans to the L13/PHP 8.3/Postgres/new-DB reality + resolving cross-section collisions (`deck_versions` ownership, `auth:api`, nullable opponent `mtgo_player_id` for imports).
