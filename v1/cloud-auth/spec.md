# Cloud Auth — Passport OAuth2 Server, Identity, Binding

> Extracted from the v1 architecture brainstorm (2026-06-30). Lives in the `../api` project. Server side of auth; client side (auth window, PKCE, tokens) in [`../client-auth/spec.md`](../client-auth/spec.md).

> **Stack (v1):** Laravel 13 / PHP 8.3 / PostgreSQL, DigitalOcean Spaces (`s3` disk) + Horizon (Redis) — see [`../overview/spec.md`](../overview/spec.md) §8. v1 cloud = a NEW Postgres database on a new host; 0.x is frozen on its own subdomain + existing DB (no server-side migration). Auth is built **fresh** (Passport OAuth2 + PKCE, clean v1 migrations); the old Sanctum device-key model stays with 0.x. Enums (`plan`) are string + `casts()`, not native PG enums.

## OAuth2 server (Laravel Passport)

The API is an **OAuth2 authorization server** issuing tokens to desktop clients via **Authorization Code + PKCE** (public client, no secret). Endpoints: `/oauth/authorize`, `/oauth/token`. Standard Passport `oauth_*` tables.

- Login screen (served by the API) offers **Discord** or **email/password**.
- **Discord**: the API performs the Discord OAuth handshake **server-side** — the Discord client secret lives only on the API server, never on the device.
- On success, redirect to the client's custom scheme `mymtgo://oauth/callback?code=...`; the client exchanges `code` + PKCE verifier for **access + refresh tokens**.
- Tokens are **per-device, refreshable, revocable** (revoke a device → forces re-auth).
- Web app (same domain as the API) may use session auth directly; desktop must use tokens (cross-domain — Sanctum cookies won't work).

## Identity & binding schema

- `users` — app account: id, email, password (nullable — set for email/password accounts, null for Discord-only), `discord_id` (nullable), `plan` (`free` | `paid`), `is_active` (deactivation flag), `deactivated_at`, timestamps. On deactivation, PII (email, `discord_id`, linked `mtgo_username`) is **obfuscated** and `mtgo_player_id` released so a fresh sign-up can re-bind (see [`../ops/spec.md`](../ops/spec.md)).
- `mtgo_accounts` — binds an app user to their MTGO identity **1:1**: id, `user_id` FK (nullable), `mtgo_player_id` (stable numeric id, from `PlayerIds`; nullable), `mtgo_username`, `active`. **Partial unique index `UNIQUE(mtgo_player_id) WHERE mtgo_player_id IS NOT NULL`** (one MTGO account maps to exactly one app account) **and `UNIQUE(user_id) WHERE user_id IS NOT NULL`** (one MTGO identity per app account); the columns are nullable so a deactivation-released binding does not collide (Postgres partial unique indexes). Multiple MTGO accounts = multiple app accounts.

## Binding rules

- **Strict 1:1, one MTGO username ↔ one app account.** Storing the local player id lets the compiler resolve "which side am I" from `PlayerIds` deterministically and drives the client's username-mismatch guard (see [`../client-auth/spec.md`](../client-auth/spec.md)).
- Two machines gaming under the same username resolve to the same app account (union of matches).
- The **MTGO username/player-id is read from the logs and is non-editable** by the user.
