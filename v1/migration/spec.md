# Migration — 0.x Import

> Extracted from the v1 architecture brainstorm (2026-06-30). Client-side import mapper; pushes through the normal pipeline ([`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md)).

## Approach

> ⚠️ **SUPERSEDED — needs revisit.** This section assumes a separate `mymtgo.sqlite` + a read-only `legacy` connection to a distinct `nativephp.sqlite`. Post-pivot the repo *is* v1 on the single default connection, and an upgraded install's 0.x display tables sit in that same DB — so the "separate DB / legacy connection" framing no longer holds. Rework the 0.x-import approach before building this section. See [`../RECONCILIATION.md`](../RECONCILIATION.md).

- v1 ships with a **new DB file `mymtgo.sqlite`**; the 0.x `nativephp.sqlite` is left **untouched** → safe rollback, no destructive migration on live data.
- An **opt-in import button** reads the old DB, maps 0.x records into the **same `{match}.json`** format v1 produces (see [`../contract/spec.md`](../contract/spec.md)), and pushes them through the **normal sink → worker** pipeline. **Zero bespoke server-side migration code**; idempotent upsert makes re-import safe.
- The one bespoke piece is the **old-schema → json read-mapper** in the v1 client.
- 0.x data is **sparse** (no per-game/timeline) → the worker builds match-only records gracefully (sparse variant of the contract).
- Import respects auth + resolved username like any other push (see [`../client-auth/spec.md`](../client-auth/spec.md)).

## Backfill UX

Reuse the **outbox → sink → worker** path (no bespoke pipeline):
- **Progress** = outbox synced-count (X/Y).
- **Partial failures** isolate in the outbox `failed` state (non-blocking) — a bad old match never stalls the batch.
- **Re-run** is safe by idempotency (`UNIQUE(user_id, match_key)`) — re-import just re-upserts.
