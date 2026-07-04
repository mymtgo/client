# v1 Handoff — state as of 2026-07-04

**For:** an agent picking up the v1 cloud-of-record build on the Windows machine.
**Branches:** client (this repo) = `1.x` · API (`E:\mymtgo\api`) = `v1`.
**Read first:** `docs/v1/README.md` (map + build order), `docs/v1/RECONCILIATION.md` (authoritative for cross-cutting decisions), `docs/v1/overview/spec.md`, `docs/v1/contract/spec.md` (the `{match}.json` both sides depend on).

## The one-paragraph summary

v1 makes the cloud the system of record: the client watches MTGO logs, compiles each match into a `{match}.json`, and pushes it to the API; a cloud worker builds the queryable tables; all viewing surfaces read from the cloud API. The client-side agent and auth workstreams are **complete and fully tested**. The API side has finished repo prep only — **no v1 server endpoints exist yet, so the client currently pushes into a void. The API is the critical path.**

## Client repo (`1.x`) — where things stand

| Workstream | Plan | Status |
|---|---|---|
| Gut to v1 baseline | — | ✅ Done — old frontend + overlays deleted, migrations reset to ingest baseline |
| client-agent | `docs/v1/client-agent/plan.md` | ✅ Tasks 1–13 all done — tail/classify → project → outcome resolvers → `AppAccount` binding → `CompileMatch` → raw gzip archive → outbox → push client → activity triggers |
| client-auth | `docs/v1/client-auth/plan.md` | ✅ Tasks 1–10 all done — PKCE, encrypted token storage, exchange, silent refresh, `Http::mymtgoAuthed` 401-retry macro, `mymtgo://` deep-link callback, session-gated boot, logout |
| client-ui | `docs/v1/client-ui/plan.md` | Task 1 (prune shell) covered by the gut. Tasks 2–9 **not started** — and page-building deliberately waits for the design track (below) |
| migration (0.x import) | `docs/v1/migration/plan.md` | Not started (7 tasks) |

- Test suite: **301 passing** (`php artisan test --compact`, 2026-07-04).
- Latest commit at handoff: `3868950b` (client-auth Tasks 8–10).

### Needs doing ON WINDOWS: observed verification of the Electron seams

The PHP suites are green, but several client-auth/agent pieces are explicitly **manually/observed-verified on Windows** (the mac session could not run the app). Nobody has done this pass yet:

- `mymtgo://oauth/callback` round trip: login in the auth window → API redirect → OS reactivates app via `second-instance` → listener fires → main window swaps in (client-auth Tasks 8–9; needs `NATIVEPHP_DEEPLINK_SCHEME=mymtgo`).
- Session-gated boot: no tokens → auth window; valid/refreshable tokens → main window (Task 9).
- Logout flow (Task 10).
- Near-realtime file-watch tick (client-agent Task 2b).

Blocked on the API's auth endpoints existing — fold it into cloud-auth verification when that lands.

## API repo (`E:\mymtgo\api`, branch `v1`) — where things stand

Prep phase (`docs/v1/HANDOFF.md` **in the API repo** — the prep checklist): tasks 1–4 done — composer deps, Postgres switch, DO Spaces disk + Redis queue, Passport OAuth2 server installed + public PKCE client seeded, Socialite Discord configured (`4b46c37`).

**Prep remaining (finish before section plans):**

1. Prep Task 5 — port the keepers to v1 shape: Scryfall ingestion, Goatbots mapping, archetype classification, deck signatures (map per `docs/v1/catalog/plan.md`).
2. Prep Task 6 — finish the 0.x removal: migrations are dropped, but `Device`, `ReportedMatch`, `ReportedMatchGame`, `MatchLogSample` models + their controllers/jobs/middleware still exist. Grep before deleting — some code is shared with the keepers.
3. Commit the working tree: all of `docs/v1/` is untracked, `.env.example` + `config/services.php` modified uncommitted.

**Then the section plans, in build order:** cloud-pipeline (12 tasks) → cloud-auth (7 tasks; Task 1 partially covered by prep's Passport install) → catalog → cloud-api → ops. Each has a `plan.md` with TDD tasks. **Zero section tasks started.**

The first thing that unblocks the client end-to-end is the cloud-pipeline sink (its Tasks 8–9: `StoreMatchFile` + the authenticated sink endpoint) plus cloud-auth's token endpoints.

## Design track (parallel, owner-driven — not your job)

The v1 UI gets a fresh visual language before pages are built (client-ui spec §"redesign"). A self-contained design brief for **claude.ai/design** exists at `docs/v1/client-ui/design-brief.md` on the owner's mac working copy (deliberately uncommitted — don't be surprised it's not in git); the owner runs that process on the web and the resulting design system gets translated into the repo later. Client-ui Tasks 2–9 build each page once against that final look — don't start them ahead of it without checking with the owner.

## Guardrails (repeated because they bite)

- Never run destructive migrations against a live/0.x database; v1 is a fresh Postgres DB, 0.x stays frozen on its own subdomain.
- LogEvents are source of truth; matches/games are projected, never inferred. Logs are hostile input.
- No dependency changes, no new base folders, no committing `.env`.
- `vendor/bin/pint --dirty --format agent` before finalizing PHP; commit only when asked.
- Client work stays on `1.x`, API work on `v1`.
