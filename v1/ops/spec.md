# Ops — Authorization, Entitlement, Deletion, Backup, Limits, Privacy

> Extracted from the v1 architecture brainstorm (2026-06-30). Cross-cutting cloud policy (`../api` project). Enforced as endpoints land.

> **Stack (v1):** Laravel 13 / PHP 8.3 / PostgreSQL, DigitalOcean Spaces (`s3` disk) + Horizon (Redis) — see [`../overview/spec.md`](../overview/spec.md) §8. v1 cloud = a NEW Postgres database on a new host; 0.x is frozen on its own subdomain + existing DB (no server-side migration). Reuse code, not the DB: ingestion/catalog logic is ported; schema + Passport/PKCE auth are built fresh (the old Sanctum device-key model stays with 0.x). DB backups are `pg_dump` (Postgres).

## Authorization

Every API endpoint is scoped to the authenticated token's user; ownership is enforced **server-side** (policies), including the sink (a client may only write files under its own user + match-key). The server **never trusts the client** for scoping.

## Entitlement — binary free/paid, no tiers

A single `plan` flag (`free` | `paid`) on the user (see [`../cloud-auth/spec.md`](../cloud-auth/spec.md)). Because desktop and web are built together, gating is decided **per page / per endpoint**: each gated read endpoint checks `plan` server-side (the source of truth) and the UI locks the corresponding page. No tier ladder. (Payment provider TBD; the gate model is a simple binary.)

## Account deletion — deactivate + obfuscate, retain anonymized data

On a deletion request:
- Mark the account inactive (`is_active=false`, `deactivated_at`).
- **Obfuscate PII** (email, `discord_id`, `mtgo_username`) and **release `mtgo_player_id`**.
- **Retain the gameplay data**, dissociated from the person.
- Re-signing up creates a **brand-new account from scratch** — no recovery/relink of old data. (Releasing `mtgo_player_id` lets the same MTGO account bind cleanly to the new account.)

## Backup / DR

Object storage = **DigitalOcean Spaces** (S3-compatible): holds the `{match}.json` files (system of record) and receives **DB backups**. DB loss is recoverable by re-running the worker over the stored files — so the *files* are what must never be lost; Spaces durability + backups are the floor.

## Limits — forgiving, sized for heavy usage

Expect heavy pushers. The API absorbs bursts rather than throttling legit users: generous per-user ceilings, the dumb sink accepts liberally. Guard only against pathological abuse (oversized files, runaway loops), not normal heavy play. The constraint is worker + API **performance/capacity**, not cost (fixed droplet).

## Privacy

- **Raw logs never leave the device**, in any form. The local raw archive is the user's own data on their own disk — same exposure MTGO already creates.
- **Structured match data** (incl. opponent handle) goes to the cloud — the line the user already accepts by pushing matches at all.
- **Opponent scoping:** global `opponents`, `UNIQUE(mtgo_player_id)`. MTGO handles are already public knowledge (third-party sites let anyone search a handle's match history), so a global store adds no new exposure and powers cross-user archetype intel.
- **Public share pages: handle-masking is optional, not required** (same public-knowledge reasoning). Revisit only if a user asks for it.
