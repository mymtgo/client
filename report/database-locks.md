# SQLite Database Lock Bottleneck Audit

**Date:** 2026-06-11
**Scope:** /Volumes/Dev/mymtgo/client — connection config, queue/cache/session drivers, transaction scope, write patterns, retry coverage, scheduler concurrency, read-path behaviour.

---

## Current Lock Posture (Summary)

| Dimension | Value | Evidence |
|---|---|---|
| Journal mode | WAL (dev connection via config; prod connection via vendor PRAGMA + app config patch) | `config/database.php:42`, `vendor/nativephp/desktop/src/NativeServiceProvider.php:204`, `app/Providers/AppServiceProvider.php:83` |
| busy_timeout | 30,000 ms (overrides NativePHP's 5,000 ms) | `config/database.php:41`, `app/Providers/AppServiceProvider.php:82,90`, vendor `NativeServiceProvider.php:205` |
| synchronous | NORMAL | `config/database.php:43`, `app/Providers/AppServiceProvider.php:84` |
| foreign_keys | ON (`DB_FOREIGN_KEYS` default true) | `config/database.php:40`, vendor `NativeServiceProvider.php:194` |
| Queue driver (production) | `database`, forced by NativePHP onto the **same** SQLite file | vendor `NativeServiceProvider.php:144,201`; `config/queue.php:16` |
| Queue driver (local dev) | `sync` (resolved via `config:show queue.default`) | local `.env` |
| Cache store | `file` (resolved locally; config fallback is `database` — see Low-3) | `config/cache.php:18` |
| Session driver | `file` — forced at runtime in production | vendor `NativeServiceProvider.php:143`; `config/session.php:21` fallback is `database` |
| Concurrent SQLite clients | 4 queue worker processes + scheduler process + embedded HTTP server + boot/migrate process ≈ **7+** | `config/nativephp.php:155-180`, vendor `NativeServiceProvider.php:147-149` |

**Overall:** the fundamentals are in place (WAL, 30s busy_timeout, NORMAL sync, a deliberate single "writer" worker, batched ingestion, `after_commit` queue dispatch, `TimedTransaction` instrumentation). The remaining problems are concentrated in three areas: (1) network I/O performed **inside** the main pipeline write transaction, (2) the database queue's job lifecycle churning ~180 write transactions/minute on the same file with a read→write upgrade pattern that *bypasses* busy_timeout, and (3) transient-error retry protection existing in exactly **one** code path (`ProcessMatchEvents:182`) while every other writer — ingestion cursor saves, league processing, pruning, schedulers, HTTP controllers — will surface or fatally log a `database is locked` error.

Steady-state write-transaction floor (idle app, watcher on): RunPipelineJob heartbeat = 60 inserts + 60 reserves + 60 deletes/min on `jobs` (`app/Managers/MtgoManager.php:247-249` everySecond × vendor `DatabaseQueue.php:285-300,441`), plus 2× ship jobs every 30 s (`MtgoManager.php:262-270`) ≈ **3+ write txns/sec before any real work happens**.

---

## Critical

### C1. Synchronous Electron broadcast (network I/O) inside the main pipeline write transaction

- **Location:** `app/Actions/Matches/AdvanceMatchState.php:63` (transaction opens), `:176-180` (`GameCardsSnapshotChanged::dispatch` inside it), `:273` (`LeagueMatchStarted::dispatch` inside it, via `tryAdvanceToInProgress` called at `:167`)
- **Supporting evidence:** `app/Events/GameCardsSnapshotChanged.php:17-20` returns the `nativephp` broadcast channel; NativePHP's `EventWatcher` (vendor `nativephp/desktop/src/Events/EventWatcher.php:14-40`) listens to `Event::listen('*')` and **synchronously HTTP POSTs** any such event to the Electron bus; the client it uses has `->timeout(60 * 60)` — a one hour timeout (vendor `nativephp/desktop/src/Client/Client.php`).
- **Issue:** `AdvanceMatchState` wraps the entire match-state projection in a single `TimedTransaction` (deliberately, per the comment at `:61-62`). But `GameCardsSnapshotChanged` is dispatched *inside* that transaction — and it fires on **every pipeline tick** (every ~1 s) for every `InProgress` match (`:178-180`). Each dispatch performs a blocking localhost HTTP round-trip to Electron while the SQLite write lock is held. `LeagueMatchStarted` has the same shape on the Started→InProgress transition. `bootstrap/app.php:36-38` documents that the Electron event bus has known auth/race failures during startup — exactly when this POST can stall.
- **Impact:** During live matches (peak write load, overlay windows active), every write-lock hold is extended by an HTTP round-trip; if Electron is slow, hung, or mid-restart, the lock is held until the HTTP call resolves (up to the 1 h client timeout). All other writers (4 queue workers, scheduler tasks, UI saves) stack up behind the 30 s busy_timeout and then fail with `database is locked`. This is the most plausible root cause of the historic "queue worker timing out at 30 s" noted at `ProcessMatchEvents.php:134-136`.
- **Action points:**
  1. Move both event dispatches outside the transaction boundary.
  2. Audit all other `broadcastOn() → ['nativephp']` events (`app/Events/AppNotification.php`) for the same pattern.
- **Fix guidelines:** Collect "events to emit" while inside the transaction and dispatch them after `TimedTransaction::run` returns (return them alongside the match, or use `DB::afterCommit`). Laravel's `afterCommit` callback is the idiomatic approach and matches the existing `after_commit => true` queue convention (`config/queue.php:44`). No behaviour change for listeners; only ordering relative to the commit.

---

## High

### H1. Database queue lives in the same SQLite file as the pipeline — and its reserve/delete pattern bypasses busy_timeout

- **Location:** vendor `nativephp/desktop/src/NativeServiceProvider.php:144` (`queue.default => database`) and `:201` (`queue.connections.database.connection => nativephp`); `config/queue.php:38-45`, `:106`, `:125` (batching + failed jobs also on the main connection); `config/nativephp.php:155-180` (4 worker processes, `sleep => 3`)
- **Issue:** Every job's lifecycle is three write transactions on the shared file: dispatch INSERT, reserve UPDATE, delete DELETE. With `RunPipelineJob` dispatched every second (`app/Managers/MtgoManager.php:247-249`) that is ~180 write txns/min of pure queue bookkeeping competing with `log_events` ingestion and match projection. Worse, Laravel's `DatabaseQueue::pop()` (vendor `Illuminate/Queue/DatabaseQueue.php:285-300`) and `deleteReserved()` (`:441-444`) open a **deferred** transaction, read, then upgrade to a write. In WAL mode, if another writer committed since the read snapshot, SQLite returns `SQLITE_BUSY_SNAPSHOT` (517) **immediately — the busy_timeout handler is not invoked** for snapshot-upgrade conflicts. The 30 s timeout does not protect this path.
- **Impact:**
  - Frequent worker-side `database is locked` exceptions under pipeline load (these are the errors `IsTransientWriteError` was built to classify — extended code 517 is explicitly tested in `tests/Unit/Actions/Pipeline/IsTransientWriteErrorTest.php:44`).
  - **Duplicate job execution:** if `deleteReserved` fails *after* `handle()` succeeded, the job re-runs after `retry_after` (90 s, `config/queue.php:43`). `RunPipelineJob` and `EnqueueCardStats` are idempotent; `ShipCardStats`/`ShipTournamentObservations` are guarded by claim-status transitions; but any future non-idempotent job inherits this hazard silently.
- **Action points:**
  1. Move queue tables (`jobs`, `job_batches`, `failed_jobs`) to a **separate SQLite file** with its own connection (a `DB_QUEUE_CONNECTION` already exists as a hook — `config/queue.php:40`). Queue churn then never contends with pipeline writes, and snapshot-upgrade conflicts between workers become rare and self-contained.
  2. Alternatively (or additionally) reduce heartbeat churn: lengthen the pipeline tick (e.g. every 2-3 s) or run the tick loop inside a long-lived job rather than dispatch-per-second.
- **Fix guidelines:** Define a second sqlite connection in `config/database.php` (same pragma trio: WAL / busy_timeout / NORMAL), point `DB_QUEUE_CONNECTION`, `queue.batching.database`, and `queue.failed.database` at it, and replicate the `AppServiceProvider::configureNativephpDatabase()` patch for the production path since NativePHP rewrites queue connections at boot (vendor `NativeServiceProvider.php:199-201` would need the override applied after `booted`). Add a migration to create the queue tables in the new file.

### H2. Transient-error retry protection exists in exactly one code path

- **Location:** `IsTransientWriteError` is consumed only at `app/Actions/Pipeline/ProcessMatchEvents.php:182`. Unprotected writers include:
  - `app/Actions/Logs/IngestLogInstance.php:54-65` — cursor `increment`/`save` and instance `save` run as bare autocommit writes *outside* the `TimedTransaction` blocks (`:245`, `:254`); a lock error here throws out of the whole tick.
  - `app/Actions/Pipeline/RunPipeline.php:21-63` — Phases 1, 1.5, 2.5, 3, 4, 5 (ingestion, `ProcessLeagueEvents`, `AbandonStaleMatches`, `LinkMatchToTournament` backfill, `EnqueueTournamentObservations`, `RelinkOrphanMatches`) have no transient classification; the catch at `:56` logs and **rethrows**.
  - `app/Jobs/RunPipelineJob.php:24-46` — no `$tries`/`$backoff`; default tries=1, so a single transient error fails the job into `failed_jobs` (another write) and the tick's remaining phases are lost.
  - All HTTP controllers that write (e.g. `app/Http/Controllers/Leagues/LinkMatchController.php:70-74`, `app/Models/Account.php:70-76` activate) — a lock timeout surfaces to the user as "An unexpected error occurred" (`bootstrap/app.php:52-79`).
- **Issue:** The retry helper signals well-known lock pain (it classifies BUSY/LOCKED/READONLY/IOERR including extended codes), yet nearly all writers other than match projection fail hard on the first transient error.
- **Impact:** Under contention bursts (C1/H1 conditions, AV scans on Windows, WAL recovery), pipeline ticks abort mid-run, `failed_jobs` accumulates, league/tournament backfills are delayed, and user-initiated saves fail visibly. Idempotent design means no data loss, but recovery is "wait for the next tick" rather than a controlled retry.
- **Action points:**
  1. Wrap each `RunPipeline` phase so a transient error in one phase skips that phase (logged) without aborting the rest, mirroring the `ProcessMatchEvents` skip-and-retry semantics.
  2. Give `RunPipelineJob` transient-aware handling — either swallow-and-log transient errors (next tick arrives in 1 s anyway) so they never hit `failed_jobs`, or add small `$tries`/`$backoff`.
  3. For HTTP write paths, add a small shared retry wrapper (2-3 attempts, short jittered backoff) around user-initiated writes, reusing `IsTransientWriteError` for classification.
- **Fix guidelines:** Centralise as a `RetriesTransientWrites`-style helper (e.g. extend `TimedTransaction` or sibling support class) so DRY is preserved; do not scatter try/catch blocks. Keep the per-match attempts budget logic unchanged.

### H3. Unbounded single-statement DELETEs in PruneProcessedLogEvents run inline in the scheduler

- **Location:** `app/Actions/Logs/PruneProcessedLogEvents.php:46-48` (delete by `whereIn` over **all** completed-match tokens) and `:65` (hard-cap delete); scheduled inline via `$schedule->call(...)` at `app/Managers/MtgoManager.php:282-284`.
- **Issue:** Both deletes are single statements with no chunking or limit. The file's own comment (`:15-20`) documents a 400k-row `log_events` table arising from a stall — the hard-cap delete after such a stall removes hundreds of thousands of rows in one implicit transaction, holding the write lock for the full duration (multi-second to minutes on a Windows laptop disk, amplified by per-delete index maintenance on the 4-column covering index referenced in `ProcessMatchEvents.php:33-39`). It also runs inside the scheduler process while every-second pipeline ticks continue, and `pruneCompleted` builds a `whereIn` over an unbounded token list.
- **Impact:** Once a day (and precisely when the table is biggest — i.e. after a pipeline stall, the worst time), all writers stall up to 30 s and then start failing with `database is locked`; the pipeline worker logs transient skips; UI writes hang.
- **Action points:**
  1. Chunk both deletes (delete in bounded batches with a limit, looping until done, yielding between batches).
  2. Move the prune onto the writer queue as a job so it serialises with other bulk writers instead of running in the scheduler process.
  3. Consider pruning more frequently with smaller batches (e.g. hourly) so the daily spike disappears.
- **Fix guidelines:** SQLite supports `DELETE ... LIMIT` via Laravel's `limit()` on delete; loop until affected rows < batch size, with a short sleep between iterations to let other writers interleave. Keep the existing logging semantics (info vs warning).

---

## Medium

### M1. EnqueueCardStats performs up to 500 per-row autocommit INSERTs per minute, inline in the scheduler

- **Location:** `app/Actions/Cards/EnqueueCardStats.php:39-57`; scheduled every minute via `$schedule->call(...)` at `app/Managers/MtgoManager.php:272-274` (no overlap guard).
- **Issue:** Each `insertOrIgnore` in the loop is its own write transaction — up to 500 write-lock acquisitions per run, interleaved with payload building. Contrast with `IngestLogInstance.php:243-249`, which correctly chunks 500 rows per transaction.
- **Impact:** Avoidable lock churn every minute after match completion bursts; lengthens the contention window for pipeline and queue writers. Backfill scenarios (`app/Updates/BackfillCardStatsShipQueue.php`) magnify it.
- **Action points:** Accumulate rows and flush in chunked multi-row `insertOrIgnore` batches (the unique constraint on `game_id` already guarantees idempotency); optionally add a schedule-level overlap guard.
- **Fix guidelines:** Same chunk-inside-one-transaction pattern as `IngestLogInstance`; payload building stays outside the transaction.

### M2. AdvanceMatchState transaction is broad: parsing, multi-entity writes, and sub-actions all inside one write lock

- **Location:** `app/Actions/Matches/AdvanceMatchState.php:63-193` — the transaction wraps `ExtractJson` parsing of raw event text (`:79`, and per-game inside `CreateOrUpdateGames`, `app/Actions/Matches/CreateOrUpdateGames.php:30-41`), `DetermineMatchDeck` (`:244`, which re-queries log_events and computes deck signatures — `app/Actions/Matches/DetermineMatchDeck.php:23-50`), `AssignLeague`, `AssignTournament`, `LinkMatchToTournament` (`:134`).
- **Issue:** This was a deliberate consolidation ("held once instead of 10-15 times", `:61-62`) and is *mostly* right — but CPU-bound JSON parsing and read queries inside the write transaction extend lock hold proportionally to event volume. `TimedTransaction` (`app/Support/TimedTransaction.php:19-34`) only logs >1000 ms; it does not bound anything.
- **Impact:** Long-game matches (large `game_state_update` payloads) produce multi-hundred-ms lock holds per tick; combined with C1 this was the historic 30 s-timeout recipe. After C1 is fixed, this drops to Medium.
- **Action points:** Pre-parse outside the transaction (events are already loaded at `:33-38`); pass parsed structures in. Keep the writes consolidated.
- **Fix guidelines:** Extract a pure "compute projection" step (no DB writes, no lock) and a thin "apply writes" transaction. This also aligns with SRP and makes the projection independently testable.

### M3. ComputeCardGameStats delete-then-insert is not atomic and its retries are not transient-aware

- **Location:** `app/Jobs/ComputeCardGameStats.php:48` (bulk DELETE), `:288`, `:372` (insertOrIgnore batches), `:29` (`$tries = 2`, no backoff, no transient classification).
- **Issue:** The delete and the per-game inserts are separate autocommit transactions. A transient lock error mid-job leaves stats half-written until the retry; the second failure (e.g. the same contention burst) abandons the job with stats deleted but not rewritten.
- **Impact:** Temporarily (or, on double failure, persistently until next recompute) missing card stats for a match — user-visible wrong data, recoverable by re-running.
- **Action points:** Wrap delete+rebuild per match (or per game) in one transaction so re-runs see all-or-nothing state; add backoff and use `IsTransientWriteError` to avoid burning tries on lock errors.
- **Fix guidelines:** Single `TimedTransaction` around delete + inserts is acceptable here because all inputs (`$entries`, parsing) can be computed before the transaction opens — mirror the M2 compute/apply split.

### M4. Per-row write loops in league processing and ship-queue failure marking

- **Location:** `app/Actions/Leagues/ProcessLeagueEvents.php:20-33` (one UPDATE per join/drop event, two queries each); `app/Jobs/ShipCardStats.php:146-160` (`markFailure` loops one UPDATE per row, up to 200).
- **Issue:** Row-at-a-time autocommit writes where set-based or chunk-batched updates would take the lock once. League volume is low (Medium only because it sits in the every-second tick path); markFailure runs only on API failure but then issues up to 200 sequential write txns while the writer queue is busy.
- **Impact:** Avoidable lock acquisitions inside the hottest loop (league) and failure-mode bursts (ship queue).
- **Action points:** Batch the `processed_at` stamping (collect ids, single UPDATE); group markFailure rows by computed status/backoff bucket and update per group.
- **Fix guidelines:** Keep idempotency: stamping after successful handling per event is the current correctness guarantee — batch per loop iteration outcome set, not before processing.

### M5. User-facing HTTP writes have no contention strategy

- **Location:** examples: `app/Http/Controllers/Leagues/LinkMatchController.php:70-74`; `app/Models/Account.php:70-76`; deck rename/settings controllers under `app/Http/Controllers/`.
- **Issue:** UI writes share the 30 s busy_timeout. While a bulk writer holds the lock (H3, M3), an Inertia POST blocks the embedded HTTP server thread for up to 30 s, then throws — the user sees a hung click then a generic error (`bootstrap/app.php:52-79` masks the SQL message — good, but the failure remains).
- **Impact:** Perceived app freeze during background bulk work; occasional failed saves.
- **Action points:** Short-budget retry wrapper for UI writes (see H2-3) and — more importantly — fix the long-hold producers (C1, H3) so the 30 s ceiling is never approached.
- **Fix guidelines:** Consider a *lower* per-request busy_timeout for the HTTP context (fail fast + retry) versus the worker context (wait long); SQLite allows setting the pragma per connection at runtime.

---

## Low

### L1. Boot-resolved production connection misses the `synchronous=NORMAL` pragma

- **Location:** `app/Providers/AppServiceProvider.php:88-93` re-applies only `busy_timeout` to the already-resolved `nativephp` connection; vendor `NativeServiceProvider.php:203-206` resolved it earlier with WAL + 5000 ms only.
- **Issue/Impact:** The first process's connection runs `synchronous=FULL` until a reconnect — slower commits (longer lock holds) in the scheduler/server process only. Config-created connections (workers) are correct.
- **Action:** Also execute the `synchronous` pragma in the booted callback alongside busy_timeout.

### L2. No WAL checkpoint strategy

- **Issue:** With ~3 write txns/sec steady-state, the default autocheckpoint (1000 pages) runs inside whichever unlucky writer trips it, causing periodic latency spikes; under continuous write pressure with concurrent readers, the WAL file can grow well past 4 MB.
- **Impact:** Occasional slow ticks misattributed to "contention"; disk growth.
- **Action:** Schedule an explicit periodic `wal_checkpoint(TRUNCATE)` during quiet periods (e.g. when no match is in progress), keeping autocheckpoint as fallback.

### L3. Cache store fallback is `database` — one .env regression away from per-second DB lock writes

- **Location:** `config/cache.php:18` (`env('CACHE_STORE', 'database')`); currently resolves to `file` locally. NativePHP forces session→file and queue→database at runtime (vendor `NativeServiceProvider.php:143-144`) but does **not** force cache.
- **Issue:** `RunPipelineJob` is `ShouldBeUnique` (`app/Jobs/RunPipelineJob.php:24`) — its unique lock is acquired through the cache store every second; `withoutOverlapping` mutexes (`MtgoManager.php:265,270,289`) and `WithoutOverlapping` job middleware (`ShipCardStats.php:36`) likewise. If a packaged build's .env ever drops `CACHE_STORE`, all of that becomes per-second writes to `cache`/`cache_locks` tables on the contended file.
- **Action:** Pin `cache.default` to `file` in code for the NativePHP runtime (same pattern as the existing nativephp DB patch in `AppServiceProvider`), or assert it at boot. Verify the packaged Windows build's resolved value.

### L4. failed_jobs growth as a side-effect of H2

- **Location:** `config/queue.php:123-127` — failed jobs stored in the main DB; every transient-error tick failure (H2) is itself another write to the contended file at the worst moment.
- **Action:** Falls out of H2 (stop failing ticks on transient errors); optionally use the `file` failed-job driver for this single-user desktop context.

---

## Read Paths During Heavy Writes (assessment, no finding)

WAL mode means UI reads (Inertia controllers, overlay windows) never block on writers and never block writers — confirmed posture is correct. The two residual read-side risks are: (a) read→write upgrades inside deferred transactions (covered in H1 — the only in-app `lockForUpdate` claims, `ShipCardStats.php:75-92` and `ShipTournamentObservations.php:77-95`, correctly keep HTTP outside the transaction and are short); (b) checkpoint starvation under continuous reads (covered in L2). No long-lived read transactions were found.

## Positive Findings (existing mitigations worth preserving)

- Pragma trio (WAL / 30 s busy_timeout / NORMAL) on both dev and production connections — `config/database.php:41-43`, `AppServiceProvider.php:74-95`.
- Batched, chunked, idempotent log ingestion with parsing outside transactions — `IngestLogInstance.php:190-262`.
- Deliberate single writer worker with priority-ordered queues — `config/nativephp.php:144-167` and the 2026-04-22 plan doc.
- `after_commit => true` on the database queue prevents job-table writes inside open transactions — `config/queue.php:44`.
- `ShouldBeUnique` pipeline tick prevents stacked overlapping runs — `RunPipelineJob.php:24-40`.
- Claim-pattern ship jobs keep HTTP outside transactions — `ShipCardStats.php:75-92`.
- `TimedTransaction` instrumentation surfaces long holds — `app/Support/TimedTransaction.php`.
- Transient-error classification with extended-code masking is correct and well-tested — `IsTransientWriteError.php:51-65`.

---

## Prioritized Action List

1. **(C1)** Move `GameCardsSnapshotChanged` / `LeagueMatchStarted` dispatches outside the `AdvanceMatchState` transaction (afterCommit). Highest impact, smallest diff.
2. **(H3)** Chunk both deletes in `PruneProcessedLogEvents` and move it onto the writer queue.
3. **(H2)** Per-phase transient-error isolation in `RunPipeline`; make `RunPipelineJob` swallow-and-log transient failures instead of writing to `failed_jobs`.
4. **(H1)** Split queue tables (`jobs`/`job_batches`/`failed_jobs`) into a dedicated SQLite file with its own connection + pragmas; patch the NativePHP runtime override accordingly.
5. **(M2)** Split AdvanceMatchState into compute (outside lock) and apply (inside lock) phases.
6. **(M3)** Make ComputeCardGameStats delete+rebuild atomic and transient-aware.
7. **(M1/M4)** Batch EnqueueCardStats inserts, league `processed_at` stamping, and ShipCardStats markFailure updates.
8. **(M5/H2)** Shared short-retry wrapper for HTTP write paths using `IsTransientWriteError`.
9. **(L1/L3)** Apply `synchronous` pragma to the boot-resolved connection; pin `cache.default => file` for the NativePHP runtime.
10. **(L2)** Scheduled quiet-period WAL checkpoint.
