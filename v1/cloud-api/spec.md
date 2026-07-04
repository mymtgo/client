# Cloud API — Read Endpoints & Realtime

> Extracted from the v1 architecture brainstorm (2026-06-30). Lives in the `../api` project. Serves data built by [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md); consumed by all clients.

## Read API

JSON API (not Inertia-only controllers) serving **matches / games / decks / stats / cards / archetypes**, consumed by desktop Inertia, web, and future mobile alike. Drives shareable public pages (privacy note in [`../ops/spec.md`](../ops/spec.md)).

- Every endpoint is **scoped to the authenticated token's user**; ownership enforced server-side (see [`../ops/spec.md`](../ops/spec.md)).
- Gated endpoints check the user's `plan` (free/paid) server-side — the source of truth, regardless of what the client renders.
- Clients do a **catch-up fetch on reconnect / app-foreground**: matches since last-seen version. This is the correctness path (the socket is only liveness).

## Realtime notifications

Laravel **Reverb** (first-party, self-hosted, no per-message SaaS fee), per-user authenticated channel.

- Emitted **by the worker after DB commit**, never by the sink on receipt.
- **Thin signal** only: `{ event: "match.logged", matchKey, version }` → client refetches via the read API; **the socket never carries match data**.
- Socket delivery is **best-effort** → clients reconcile with the catch-up fetch above. **Socket = liveness; catch-up = correctness.**

## Notes

- Reverb runs on the same fixed DigitalOcean droplet → no surprise per-message cost. Constraint is worker + API performance, not cost.
- The API is tuned to **absorb bursts** rather than throttle legit heavy users (see [`../ops/spec.md`](../ops/spec.md) — limits).
