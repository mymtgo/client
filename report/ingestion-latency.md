# End-to-End Latency Audit: MTGO Log Line → Match Visible in UI

Date: 2026-06-11
Updated: 2026-06-12 — C1 corrected after code re-verification (LIKE substring matching narrows the signal gap; concede-and-quit path added), M2 expanded, and a target-architecture plan for **~300 ms ingestion** appended (see "Reaching the ~300 ms Target").
Scope: `/Volumes/Dev/mymtgo/client` (NativePHP/Electron desktop app, Laravel 12, SQLite)

## Summary

There is **no FileSystemWatcher** in this codebase despite the architecture docs naming one. Ingestion is a pure poll loop: an `everySecond()` scheduled dispatch of `RunPipelineJob` (app/Managers/MtgoManager.php:247-249) consumed by a dedicated single `pipeline` queue worker with `--sleep=3` (config/nativephp.php:155-161). One pipeline tick does the *entire* chain inline — ingest bytes, classify, project matches, resolve results (app/Actions/Pipeline/RunPipeline.php:22-55) — so the backend pipeline itself adds essentially zero inter-stage scheduling latency.

**A match only becomes visible in the UI once its state is `Complete`** (`MtgoMatch::complete()` scope, app/Models/MtgoMatch.php:111-115; dashboard queries at app/Http/Controllers/IndexController.php:35,96). Matches never appear mid-play in match lists. On completion the only push to the UI is a toast notification (app/Observers/MtgoMatchObserver.php:48-53 → resources/js/AppLayout.vue:18); **no page data auto-refreshes**, so the rendered match list is stale until the user navigates or clicks the toast.

**Total latency estimate (MTGO writes the match-completed log line → match row is Complete in SQLite):**

- **Best case: ~1.5–5 s** (≤1 s dispatch + ≤3 s worker poll sleep + sub-second tick work + instant Native event-bus toast).
- **Typical worst case (no failures): ~10–40 s** (tick backlog/long tick + up to 30 s SQLite `busy_timeout` contention + retry on next tick).
- **Bounded pathological cases: +60 s** (scheduler dead until next minute boundary after boot/resume), **+300 s** (orphaned `ShouldBeUnique` lock after a hard kill), **+5 min** (deck-scoped visibility waiting on `SyncDecks`), **+60 min** (disconnect-forfeit matches waiting on the stale-match reaper).
- **Unbounded / never:** matches stuck in `Ended` due to a completion-signal set mismatch, matches `Abandoned`, matches with `failed_at` after 5 attempts, and matches whose events are flushed after 2 minutes with no resolvable username. And on screen: **time-to-visible is unbounded by user navigation** because nothing refreshes the match list automatically.

---

## Latency Budget Table

| # | Stage | Best case | Worst case | Source (file:line) |
|---|-------|-----------|------------|--------------------|
| 0 | MTGO flushes line to `mtgo.log` on disk | ~0 | unknown (MTGO-internal buffering; outside app control) | n/a — no code can observe pre-flush |
| 1 | File change detection | n/a — **no watcher exists**; detection is the tick itself reading `stat()` size vs cursor | n/a | app/Actions/Logs/IngestLogInstance.php:74-101, 179; `watcher_active` is only a boolean gate: app/Managers/MtgoManager.php:197-204 |
| 1a | New-log-file discovery cache | 0 s | 5 s (path list cached) | app/Actions/Logs/FindMtgoLogPath.php:19 |
| 1b | Log rotation handling | 1 extra tick (~1–4 s) — sealed instance recreated next tick | ~4 s | app/Actions/Logs/IngestLogInstance.php:33-35, 144-155 |
| 1c | Stuck-cursor force reset | n/a | 60 ticks ≈ **3–4 min** of stalled ingestion before force-seal (threshold assumes 1 s ticks; effective tick is ~3–4 s, see #3) | app/Actions/Logs/IngestLogInstance.php:16 (`STUCK_THRESHOLD = 60`), 41-49 |
| 2 | Scheduler cadence — `RunPipelineJob` dispatch | ≤1 s (`everySecond()`) | ≤1 s steady state; **up to 60 s after app boot / OS resume** (Electron starts `schedule:run` only at the next minute boundary, then every 60 s; Laravel sub-minute scheduling fills the gaps within each run) | app/Managers/MtgoManager.php:247-249; vendor/nativephp/desktop/resources/electron/electron-plugin/src/index.ts:256-271 (boundary delay at :258); suspend/resume stop-start at :108-115 |
| 2a | Duplicate-dispatch suppression | 0 (by design) | dropped dispatches while a tick is in flight; **up to 300 s total pipeline blackout if the worker is hard-killed mid-tick** (unique lock persists in SQLite `cache` store until `uniqueFor` TTL) | app/Jobs/RunPipelineJob.php:24-30 (`ShouldBeUnique`, `uniqueFor = 300`); config/cache.php:18 (`database` store) |
| 3 | Queue pickup — `pipeline` worker | ~0 s (worker polling) | 3 s (`--sleep=3` when queue is empty; effective tick cadence ≈ 3–4 s, not 1 s) | config/nativephp.php:155-161; vendor/nativephp/desktop/src/QueueWorker.php:27-46 (`queue:work --sleep`); config/queue.php:16 (`database` driver default) |
| 4 | Pipeline internals: ingest → classify → persist LogEvents | ~0.1–1 s, same tick (classification inline during read; chunked `insertOrIgnore`) | seconds on large backlogs; long transactions logged >1000 ms | app/Actions/Logs/IngestLogInstance.php:243-249, 352; app/Support/TimedTransaction.php:20-37 |
| 5 | Match projection: events → `Started`/`InProgress`/`Ended` | same tick, ~ms | n/a (no batch thresholds, no deliberate delays) | app/Actions/Pipeline/RunPipeline.php:22-55; app/Actions/Pipeline/ProcessMatchEvents.php:45-94; app/Actions/Matches/AdvanceMatchState.php:31-194 |
| 6 | Match completion: `Ended` → `Complete` (UI-visibility gate) | same tick as the `*CompletedState` log line | **forever**, if the match reached `Ended` via `MatchCompletedState`, `MatchEndedState`, or a concede-and-quit with no close line — none of which the resolver's completed-signal gate recognises. (`TournamentMatchClosedState` *is* covered: the gate's `%MatchClosedState%` LIKE matches it by substring.) | app/Actions/Matches/AdvanceMatchState.php:287-295 (5 signals + concede) vs app/Actions/Pipeline/ResolveMatchFromMetaMessages.php:98-109 (LIKE patterns); gate at :37 |
| 6a | Killed-client fallback (reaper) | 60 min after last activity (disconnect → `Complete` win/loss) | never visible (no disconnect → `Abandoned`, excluded from `complete()` scope) | app/Actions/Matches/AbandonStaleMatches.php:33-39; config/mtgo.php:16 (`match_abandon_after_minutes` = 60); reaper covers `InProgress` only: AbandonStaleMatches.php:36 |
| 7 | Lock/retry-induced latency | 0 | +1 tick (~3–4 s) per transient SQLite error (BUSY/LOCKED/READONLY/IOERR — skipped without consuming retry budget); each write can block up to 30 s on `busy_timeout`; **5 non-transient failures → `failed_at` → match never appears** | app/Actions/Pipeline/IsTransientWriteError.php:17-35; app/Actions/Pipeline/ProcessMatchEvents.php:175-206 (budget at :193); config/database.php:41 (`busy_timeout` 30000), :42 (WAL) |
| 7a | Username-resolution flush | 0 (fallback to active account) | events marked processed after 2 min with no username → **match never created** | app/Actions/Pipeline/ProcessMatchEvents.php:108-117, 215-226, 228-239 |
| 8 | Deck linking (affects deck name + deck-scoped views) | same tick if DeckVersion already exists | ~5 min (`SyncDecks` cadence) + 1 tick for `RelinkOrphanMatches` | app/Managers/MtgoManager.php:258-260 (`everyFiveMinutes`); app/Actions/Matches/AdvanceMatchState.php:243-250; app/Actions/Pipeline/RunPipeline.php:52-55 |
| 9 | UI refresh — match lists | toast within ~0–1 s of `Complete` (Native event bus, no polling) | **unbounded** — dashboard/match list props never auto-reload; refresh requires navigation, pagination click, or toast click | app/Observers/MtgoMatchObserver.php:48-53; resources/js/AppLayout.vue:17-26 (toast only); resources/js/pages/partials/RecentMatches.vue:19-25 (reload only on page change); no `usePoll`/listener reload anywhere in match views |
| 9a | UI refresh — overlay windows (contrast) | event-driven reload (draw-odds popout) / 5 s poll (opponent scout) | 5 s | resources/js/pages/decks/Popout.vue:24-25; resources/js/pages/leagues/OpponentScout.vue:27-29 |

Job chaining answer (stage 5 of the brief): ingestion does **not** dispatch a separate processing job — `RunPipeline::run()` calls `ingestLogs()` then `ProcessMatchEvents::run()` sequentially in one tick (app/Actions/Pipeline/RunPipeline.php:23-32), so there is no additive cross-schedule latency between ingestion and match building. The standalone `IngestLogs` job exists only for manual debug/settings buttons (app/Jobs/IngestLogs.php:9-13).

---

## Findings

### Critical

#### C1. `Ended → Complete` gate misses two signals and the entire concede path — matches stuck in `Ended` forever
- **Location:** app/Actions/Pipeline/ResolveMatchFromMetaMessages.php:98-109 vs app/Actions/Matches/AdvanceMatchState.php:287-295
- **Issue (corrected 2026-06-12):** `hasCompletedSignal` matches by SQL `LIKE` substring, so the gap is narrower than originally reported but has an extra member the original audit missed:
  - `%MatchClosedState%` (line 106) **also matches `TournamentMatchClosedState`** by substring (`Tournament` + `MatchClosedState`), so tournament closes are *not* a gap. Likewise `%MatchJoinedCompletedState%` (line 105) already covers `LeagueMatchJoinedCompletedState`, making line 104 redundant.
  - Genuine signal gaps: **`MatchCompletedState`** and **`MatchEndedState`** — both advance the match to `Ended` (AdvanceMatchState.php:289-290) and match no resolver pattern.
  - **Missed entirely by the original audit: the concede path.** `tryAdvanceToEnded` also advances on `DetermineMatchResult::localPlayerConceded($stateChanges)` (AdvanceMatchState.php:295) with *no end-signal line at all*. `hasCompletedSignal` has no concede pattern, so a concede-and-quit that MTGO never follows with a `*ClosedState` line strands the match in `Ended`. The resolver's own comment (ResolveMatchFromMetaMessages.php:70-76) acknowledges `ConcedeReqState→NotJoined` is recognised downstream in `DetermineMatchResult` — but the gate returns at :37 before that code can run.

  Once in `Ended`, events get marked processed (app/Actions/Pipeline/ProcessMatchEvents.php:161) and nothing revisits the match: discovery keys off *unprocessed* events (:45-51, :69-75) and the `AbandonStaleMatches` reaper only scans `InProgress` (app/Actions/Matches/AbandonStaleMatches.php:36).
- **Impact:** Match reaches a terminal-looking state in the DB but never enters the `complete()` scope — **never visible in any match list**, never submitted, never enriched. Per `docs/known_mtgo_lies_and_traps.md` ethos, do not assume MTGO always pairs these signals.
- **Action points (reordered — reaper first):** (1) **Primary fix: extend the reaper (or add a second pass) to cover quiet `Ended` matches**, resolving from whatever game results exist — signal-set unification alone cannot cover the concede path, the reaper covers everything including signals nobody has catalogued yet. (2) Unify the literal signal sets behind one shared constant/helper so they cannot drift; note the two checks read different columns (`tryAdvanceToEnded` reads `context`, `hasCompletedSignal` LIKEs `raw_text`) — pick one. (3) Add a Pest test asserting every path that can reach `Ended` (each of the five signals *and* the concede path) either satisfies the resolver gate or is picked up by the `Ended` reaper pass.
- **Fix guidelines:** Single source of truth for end-signal contexts (e.g., an enum or const array consumed by both classes); reaper treats `Ended` + no-new-activity like `InProgress` + disconnect today — decide from extracted game results, else mark Abandoned. No schema change needed.

#### C2. Five non-transient failures permanently hide a match with no surfaced recovery
- **Location:** app/Actions/Pipeline/ProcessMatchEvents.php:190-206 (`failed_at` at attempt 5), gate at :100-102
- **Issue:** Any recurring non-transient exception (parser edge case, malformed MetaMessage) consumes the 5-attempt budget across ~5 ticks (≈15–20 s) and sets `failed_at`; `processMatch` then skips the token forever. There is no user-facing surface or automatic re-attempt after an app update ships a parser fix.
- **Impact:** Match never appears; user perceives silent data loss.
- **Action points:** (1) On app update (`RunAppUpdates`), clear `failed_at`/`attempts` for recent matches so shipped fixes retroactively recover them. (2) Surface failed matches in the debug screen with a retry affordance. (3) Consider exponential attempt spacing rather than 5 consecutive ticks — five attempts within ~20 s of the same in-flight log state retries against essentially identical data.
- **Fix guidelines:** Keep the budget, but make attempts time-spaced (e.g., next attempt gated on `updated_at` age) so the budget spans minutes of genuinely new log data instead of one burst; pair with a version-bump reset migration.

#### C3. Username flush discards match events after 2 minutes
- **Location:** app/Actions/Pipeline/ProcessMatchEvents.php:113-117, 215-226
- **Issue:** If no `username` can be resolved (no Login line in the active log instance *and* no account row — e.g., fresh install while a match is already underway, or the known `Mtgo::getUsername()` flakiness), events older than 2 minutes are marked processed and the match is never created.
- **Impact:** Match never appears, and because events are flagged processed the data is unrecoverable by the live tick.
- **Action points:** (1) Lengthen the no-username grace window substantially (events are pruned daily, not at 2 min — there is no pressure to flush this fast; see app/Managers/MtgoManager.php:282-284). (2) Prefer parking (skip without marking processed) until an account exists, with a cap. (3) Emit an `AppNotification` prompting account setup when this path triggers.
- **Fix guidelines:** Treat "no username yet" like a transient condition (same philosophy as IsTransientWriteError) rather than a terminal one; only flush at a horizon comparable to the abandon window (60 min).

### High

#### H1. Match lists never auto-refresh — time-to-visible is unbounded after the data is ready
- **Location:** resources/js/AppLayout.vue:17-26 (toast only); resources/js/pages/partials/RecentMatches.vue:19-25 (reload only on pagination); app/Http/Controllers/IndexController.php:55-109 (no polling props)
- **Issue:** The backend completes a match within seconds, dispatches `AppNotification` over the NativePHP event bus (app/Observers/MtgoMatchObserver.php:48-53), but the only consumer shows a toast. Dashboard KPIs, `recentMatches`, reports, and deck match lists keep rendering stale data until the user navigates. The infrastructure for event-driven reload already exists and is used by the deck popout (resources/js/pages/decks/Popout.vue:24-25).
- **Impact:** Perceived ingestion latency is minutes-to-forever even though the pipeline is ~3 s. This is the single biggest contributor to "TTL between ingestion and seeing matches."
- **Action points:** (1) In the dashboard/matches pages (or a shared layout-level composable), listen for `App\Events\AppNotification` with `match_win`/`match_loss` types (or add a dedicated `MatchCompleted` event) and trigger a partial `router.reload({ only: [...] })` of match props. (2) Alternatively add Inertia v2 polling (`usePoll`) on match-list pages at a modest interval (15–30 s) as a fallback for missed events.
- **Fix guidelines:** Prefer the existing Native event bus (zero polling cost, already broadcast on the `nativephp` channel) with partial reloads; keep deferred-prop skeletons so reloads stay cheap. Follow the established Popout.vue listener pattern.

#### H2. Disconnect-forfeit matches take 60 minutes to appear
- **Location:** app/Actions/Matches/AbandonStaleMatches.php:33 with config/mtgo.php:16 (60 min default)
- **Issue:** When the opponent (or local player) drops and MTGO writes no close, the match is decided by the reaper only after 60 minutes of silence — even when the last logged action is an unambiguous terminal disconnect with no possible reconnect (opponent never returned, match scene closed).
- **Impact:** A real win/loss is invisible for an hour; users checking right after a league match see nothing.
- **Action points:** (1) Differentiate "quiet because mid-sideboard" from "quiet because the local log shows the match scene was torn down" and use a much shorter window (e.g., 5–10 min) for the latter. (2) Make the window configurable per signal type rather than one global 60-min knob.
- **Fix guidelines:** Keep 60 min as the conservative floor for ambiguous silence; add an early-exit path when a terminal disconnect entry is the last action *and* a subsequent scene-close/context-switch event exists. Test against real log fixtures per project feedback rules.

#### H3. Hard-killed pipeline tick blacks out ingestion for up to 5 minutes
- **Location:** app/Jobs/RunPipelineJob.php:28-30 (`timeout = 300`, `uniqueFor = 300`); config/cache.php:18 (lock stored in SQLite `cache` table, survives restart)
- **Issue:** `ShouldBeUnique` lock is held from dispatch to completion. If the Electron app (and its persistent `queue:work` child, vendor/nativephp/desktop/src/QueueWorker.php:31-46) is hard-killed mid-tick, the lock row survives in the database cache store. After relaunch, every `everySecond` dispatch is silently dropped until the 300 s TTL lapses.
- **Impact:** Up to 5 minutes of zero ingestion after every crash/force-quit — precisely the moment users relaunch to check a match.
- **Action points:** (1) Clear the `pipeline:run` unique lock during app boot (`NativeAppServiceProvider::boot`, app/Providers/NativeAppServiceProvider.php:24) before the first tick can be dispatched. (2) Reduce `uniqueFor` — 300 s is sized to the worst-case backlog drain, but a boot-time sweep makes a smaller TTL safe.
- **Fix guidelines:** Boot-time lock sweep is the safe, surgical fix; document the invariant (`uniqueFor >= timeout`) stays intact. Avoid moving locks to a volatile store without checking other consumers.

### Medium

#### M1. Scheduler dead zone: up to 60 s after app boot and after OS resume
- **Location:** vendor/nativephp/desktop/resources/electron/electron-plugin/src/index.ts:256-271 (first `schedule:run` deferred to the next minute boundary, line 258); suspend/resume restart at :108-115
- **Issue:** NativePHP aligns `schedule:run` to minute boundaries. Launch the app at hh:mm:01 and no pipeline tick runs until hh:mm+1:00. Same after laptop resume.
- **Impact:** Up to ~60 s of added latency exactly when users open the app to see results; compounds with H3 after a crash.
- **Action points:** Dispatch one `RunPipelineJob` directly from `NativeAppServiceProvider::boot()` (app/Providers/NativeAppServiceProvider.php:24-63) so the first tick is immediate; uniqueness already dedupes against the scheduler.
- **Fix guidelines:** A single boot-time dispatch (after `runInitialSetup`) is enough; do not try to patch vendor scheduler timing.

#### M2. `retry_after` (90 s) < job `timeout` (300 s) on the database queue
- **Location:** config/queue.php:43 (`DB_QUEUE_RETRY_AFTER` default 90) vs app/Jobs/RunPipelineJob.php:28 (`timeout = 300`); writer worker also allows 300 s jobs (config/nativephp.php:165)
- **Issue:** Laravel requires `retry_after` to exceed the longest job timeout. A legitimate long tick (backlog drain after H3/M1, import jobs on the writer queue) is re-released at 90 s while still running; with `--tries` defaulting to 1, the re-reserved attempt can fail with MaxAttemptsExceeded, and duplicate sequential runs waste write-lock time. Note also that the pipeline *worker* is configured with `timeout: 60` (config/nativephp.php:159) while the job declares `$timeout = 300` — the job property wins in Laravel, so 300 s is the effective ceiling, but the numbers disagree on their face.
- **Impact:** Spurious failed jobs and duplicated tick work during exactly the recovery windows where throughput matters; can extend a backlog drain by minutes.
- **Action points:** Set `DB_QUEUE_RETRY_AFTER` (or per-connection `retry_after`) above 300 s, or cap the pipeline tick's work-per-run so 90 s is a true ceiling. First confirm `DB_QUEUE_RETRY_AFTER` is not already overridden in `.env` (not readable during this audit).
- **Fix guidelines:** Single config change plus a comment tying the **four** numbers together (worker `timeout`, job `$timeout`, `uniqueFor`, `retry_after`); verify `ShipCardStats`/import jobs on the shared writer worker observe the same ceiling.

#### M3. Deck-scoped visibility lags up to ~5 minutes behind match visibility
- **Location:** app/Managers/MtgoManager.php:258-260 (`syncDecks` `everyFiveMinutes`); app/Actions/Matches/AdvanceMatchState.php:243-250 (deliberately does not trigger sync); app/Actions/Pipeline/RunPipeline.php:52-55 (relink pass)
- **Issue:** If the deck XML for a new/edited deck hasn't been synced when the match starts, the match completes with `deck_version_id = null`: it shows "Unknown" deck on the dashboard (resources/js/pages/partials/RecentMatches.vue:79) and is absent from deck-scoped match views until the next `SyncDecks` + relink tick. The inline-sync alternative was correctly rejected for lock contention (AdvanceMatchState.php:240-243).
- **Impact:** Up to ~5 min of wrong/missing deck attribution per match; also delays `submittable` (requires `deck_version_id`, app/Models/MtgoMatch.php:105-109).
- **Action points:** Dispatch a queued (not sync) `SyncDecks` when `DetermineMatchDeck` misses — `SyncDecks::dispatch()` lands on the separate writer worker so the stated lock concern doesn't apply; debounce so repeated misses within a tick dispatch once.
- **Fix guidelines:** Event-driven sync on miss (e.g., listener on the existing miss path) with `ShouldBeUnique` on `SyncDecks`; keep the 5-min schedule as backstop.

#### M4. Stuck-cursor recovery threshold is ~3–4 minutes, not the intended ~1 minute
- **Location:** app/Actions/Logs/IngestLogInstance.php:16 (`STUCK_THRESHOLD = 60` ticks) measured in ticks, while effective tick cadence is ~3–4 s (everySecond dispatch throttled by `--sleep=3` single worker, config/nativephp.php:160)
- **Issue:** The threshold counts ticks assuming 1 tick ≈ 1 s. At the real cadence, a wedged cursor stalls ingestion for 3–4 minutes before force-seal, and the seal itself costs one more tick (IngestLogInstance.php:33-35).
- **Impact:** Multi-minute ingestion stalls in the (rare) stuck-cursor scenario.
- **Action points:** Convert the threshold to wall-clock (`last_advance_at` age) instead of tick count; the column already exists (IngestLogInstance.php:58).
- **Fix guidelines:** Compare `now() - last_advance_at` against a configured duration; delete `stuck_ticks` bookkeeping or keep it as telemetry only.

### Low

#### L1. Pipeline worker `sleep=3` quietly triples the advertised 1 s cadence
- **Location:** config/nativephp.php:160 vs app/Managers/MtgoManager.php:248
- **Issue:** With a single pipeline worker, an empty-queue poll sleeps 3 s, so the effective tick is ~3–4 s despite `everySecond()` dispatch. Every "next tick" retry path (transient write errors, rotation reseal, orphan-token pickup) is paced by this.
- **Impact:** ~2–3 s added to best-case latency; multiplies through M4's threshold.
- **Action points:** Set the pipeline worker's `sleep` to 1 (leave writer/downloads at 3). CPU cost is one empty SQLite poll per second.
- **Fix guidelines:** One-line config change; verify no battery/CPU regression on the Windows build.

#### L2. Documentation drift: docblock says 2-second schedule, watcher that doesn't exist
- **Location:** app/Jobs/RunPipelineJob.php:13 ("Dispatched on a 2-second schedule") vs app/Managers/MtgoManager.php:248 (`everySecond`); CLAUDE.md / docs/system.md data-flow line names `FileSystemWatcher` and `IngestLogs` job as the live path, but the live path is scheduler → `RunPipelineJob` → `IngestLogInstance` (app/Jobs/IngestLogs.php:9-13 is debug-button only).
- **Impact:** Misleads future latency work (this audit's own brief assumed a watcher).
- **Action points:** Correct the docblock and update docs/system.md + CLAUDE.md data-flow line.
- **Fix guidelines:** Docs-only change.

#### L3. SQLite contention is well-mitigated — keep the current design
- **Location:** config/database.php:41-42 (WAL + 30 s busy_timeout); app/Actions/Pipeline/IsTransientWriteError.php:17-35; app/Support/TimedTransaction.php:20-37; single shared writer worker (config/nativephp.php:146-167)
- **Positive finding:** Transient BUSY/LOCKED/READONLY/IOERR errors retry next tick without consuming the failure budget; long transactions are instrumented; writer queues are serialized onto one worker by design. Worst case a write blocks 30 s (busy_timeout) — acceptable. No action needed beyond C2/M2 above.

#### L4. New-log discovery cache and rotation each add a bounded ≤5 s
- **Location:** app/Actions/Logs/FindMtgoLogPath.php:19 (5 s path cache); app/Actions/Logs/IngestLogInstance.php:33-35 (rotation reseal costs one tick)
- **Impact:** Only matters at MTGO client start / log rotation; bounded and idempotent. No action required.

---

## Reaching the ~300 ms Target (added 2026-06-12)

**Target:** log line flushed to disk → match `Complete` in SQLite in **~300 ms typical**, → visible in the UI within one partial-reload round trip (**~300–600 ms** line-to-pixels). Stage 0 (MTGO's internal buffering) remains outside app control, so all numbers are measured from the byte landing on disk.

The scheduler → DB queue → worker hot path cannot get there: every dispatch hop costs 1–4 s by construction (stages 2–3 in the budget table). Two of the three legs need rework; the third — the pipeline internals themselves — already fits the budget (discovery is a ~6 ms covering-index seek per the code's own comment; projection is a handful of small indexed queries).

### Leg 1 — Detection & processing: resident ingest daemon (recommended)

Move the hot path out of the queue into a long-running watcher process:

- **New artisan command** (e.g. `mtgo:watch`) hosting a loop: `stat()` candidate log files every **100–150 ms** (`FindMtgoLogPath`'s 5 s path cache is already suitable, app/Actions/Logs/FindMtgoLogPath.php:19); on size change, run the existing hot path inline — `IngestLogInstance` → `ProcessLeagueEvents` → `ProcessMatchEvents` (resolution included). Same code as `RunPipeline` phases 1–2; only the host changes.
- **Launch at boot** from `NativeAppServiceProvider::boot()` (app/Providers/NativeAppServiceProvider.php:24) via `ChildProcess::artisan('mtgo:watch', 'ingest-watcher', persistent: true)`. The `persistent` flag gives Electron-side crash restart (vendor/nativephp/desktop/src/ChildProcess.php:88-130) — no new supervision infrastructure.
- **Slow phases** (`AbandonStaleMatches`, tournament backfill/observations, `RelinkOrphanMatches`) run on an internal 30–60 s timer inside the loop, or stay on the scheduler unchanged.
- **Demote `RunPipelineJob` to backstop** (e.g. `everyThirtySeconds()`), gated on a daemon heartbeat cache key (skip when heartbeat < 5 s old). Wrap each daemon iteration *and* the backstop tick in a short shared `Cache::lock('pipeline:tick')` so the two hosts never project the same events concurrently — the pipeline is idempotent, but there is no reason to exercise that under concurrency.
- **Process hygiene:** check `Mtgo::canRun()` every iteration (honours the `watcher_active` setting); self-exit on a memory ceiling or every few hours and let `persistent` restart the process; treat transient DB errors the same way `IsTransientWriteError` does today.

**Why a fast stat-poll, not an FS watcher:** NativePHP ships no file-watcher API (verified — no chokidar/watch support anywhere in the vendor package), Windows directory-change notifications are historically unreliable against MTGO's write/rotation behaviour, and a 100–150 ms `stat()` on a handful of paths is effectively free. The architecture docs' "FileSystemWatcher" never existed (L2); this plan makes the polling design intentional and fast instead of accidental and slow.

**Latency math:** detection ≤150 ms (avg ~75 ms) + warm-process hot path ~50–150 ms ≈ **150–300 ms typical**. p99 is governed by SQLite write contention with the shared writer worker (bounded by the 30 s `busy_timeout`; mitigations already in place per L3) — occasional slow ticks during bulk imports are acceptable.

**What this deletes from the latency table:** stage 2 (scheduler cadence), stage 2a/H3 (unique-lock blackout — the hot path is no longer queue-dispatched), stage 3/L1 (worker `sleep`), and M1's dead zone for ingestion (the daemon starts immediately at boot; only backstop/maintenance work stays minute-aligned).

**Alternative considered and rejected:** a self-looping tick job (`RunPipelineJob::handle()` runs a ~50 s `usleep(250_000)` loop then exits; `everySecond()` redispatch continues it). Less new infrastructure, but it inherits every queue pathology this section removes — unique-lock blackouts after hard kills, `retry_after` coupling, worker-timeout tuning — for a worse floor (~250 ms detection plus queue edges). Not worth it when `ChildProcess` supervision already exists.

### Leg 2 — UI: event-driven partial reload (H1, now mandatory)

A 300 ms backend is invisible without it. Dispatch a dedicated `MatchCompleted` broadcast event from the observer (the daemon can broadcast — `AppNotification` already crosses process boundaries via the Native event bus, as the queue worker proves today); match-list pages listen and `router.reload({ only: [...] })` per the established Popout.vue:24-26 pattern. Keep the toast as-is.

### Leg 3 — Correctness fixes cadence can't help

C1/C2/C3 produce **never-visible** matches at any tick rate. They stay at the top of the action list — a 300 ms pipeline that permanently eats a concede-quit match is worse than a 4 s one that doesn't.

### Knock-on adjustments

- **M4 becomes a prerequisite, not a nice-to-have:** at ~150 ms ticks, `STUCK_THRESHOLD = 60` trips after ~9 s of cursor stall instead of the intended ~minute. Convert to wall-clock (`last_advance_at` age) *before* the daemon ships.
- **M2 still applies** to the writer worker's 300 s jobs even after the pipeline leaves the queue.
- **L2 docs update** should describe the daemon as the canonical ingestion path.

---

## Prioritized Action List (revised 2026-06-12 for the ~300 ms goal)

1. **(C1)** Extend the reaper to quiet `Ended` matches (primary — covers the concede path); unify the end-signal sets behind one constant as the secondary hardening. Prevents permanently invisible matches.
2. **(M4 → daemon)** Convert stuck-cursor threshold to wall-clock (`last_advance_at`), then build the resident `mtgo:watch` daemon: `ChildProcess::artisan(..., persistent: true)` at boot, 100–150 ms stat-poll, inline hot path, heartbeat-gated `RunPipelineJob` backstop. This is the ~300 ms backbone and retires H3, M1, and L1 for the hot path.
3. **(H1)** Dedicated `MatchCompleted` broadcast + `router.reload({ only: [...] })` on match-list pages (Popout.vue listener pattern). Completes the line-to-pixels story.
4. **(C3)** Replace the 2-minute no-username flush with a parked/transient treatment and a far longer horizon.
5. **(C2)** Time-space the 5-attempt match failure budget and reset `failed_at` on app update; surface failed matches in debug UI.
6. **(H2)** Short-circuit the 60-min reaper window when the last action is a terminal disconnect followed by scene teardown.
7. **(M2)** Raise database-queue `retry_after` above the 300 s writer-job timeout (check `.env` for an existing override first); document the four coupled numbers.
8. **(M3)** Dispatch queued `SyncDecks` on deck-link miss to close the 5-min deck-attribution gap.
9. **(L2)** Fix docblock/docs drift — and update docs to describe the daemon as the canonical ingestion path once it ships.
