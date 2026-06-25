# Scalability Audit — MTGO Deck Tracker (client)

**Scope:** scaling issues and scalability of a single-user local app as **data volume grows over time** — months/years of MTGO logs, 10k–100k+ `log_events` rows in flight, thousands of matches/games/decks, growing card stats. Not concurrent-user scaling.

**Method:** evidence-based code review of the pipeline (`RunPipeline` tick, ingestion, pruning), backfill/regeneration jobs, stats computation, schema/indexes, SQLite file-growth behaviour, and controller/page payloads. All findings cite actual code.

**Summary:** the hot pipeline path has already been hardened against the worst historical incident (covering discovery index, 30-day hard-cap prune, cursor-based ingestion). The remaining scaling risks cluster in four areas: (1) **unbounded in-DB blob tables** (`game_timelines`, `game_logs.decoded_entries`, ship queues) with no retention or vacuum strategy; (2) a **prune routine that itself degrades with lifetime match count and will eventually throw**, silently disabling the hard cap that protects the pipeline; (3) **per-tick work that re-scans/re-decodes entire match histories** instead of only new events (O(n²) over a match's life, plus unbounded every-second backfill passes); and (4) **full-recompute regeneration paths** that dispatch one job per historical match with per-job directory scans.

---

## Critical

### C1. `game_timelines` and `game_logs.decoded_entries` grow without bound — no retention, no vacuum

**Location:**
- `/Volumes/Dev/mymtgo/client/app/Actions/Matches/CreateGames.php:212-246` (`replaceTimeline`)
- `/Volumes/Dev/mymtgo/client/app/Actions/Matches/DecodeGameLog.php:22-36`
- `/Volumes/Dev/mymtgo/client/database/migrations/2025_12_31_103703_create_game_timelines_table.php:19-25` (`json content`)
- `/Volumes/Dev/mymtgo/client/database/migrations/2026_03_18_083947_add_decoded_columns_to_game_logs_table.php:14-16` (`json decoded_entries`)
- Only deletion path: `/Volumes/Dev/mymtgo/client/app/Observers/MtgoMatchObserver.php:94-99` (manual match delete)
- No `VACUUM`/`auto_vacuum`/`incremental_vacuum` anywhere in `app/`, `config/`, or `database/` (verified by search); `config/database.php:35-44` sets WAL/busy_timeout only.

**Issue:** every `game_state_update` log event becomes a `game_timelines` row whose `content` column stores the **entire board snapshot JSON** (full `Cards` array, every card instance with zone/owner). A single game can produce dozens-to-hundreds of snapshots, each KB-to-tens-of-KB. Separately, `game_logs.decoded_entries` stores the full decoded game-log text of every match (live and imported) as JSON in the DB, forever. Neither table is touched by `PruneProcessedLogEvents` (which only prunes `log_events`). Because SQLite never returns free pages without VACUUM, even the churn from `replaceTimeline`'s delete+reinsert leaves the file at high-water mark.

**Impact at scale:** O(games × snapshots) blob growth, linear in play time with a large constant. A regular grinder (≈50 games/week × ~100 snapshots × ~10 KB) adds tens of MB per week — **multi-GB SQLite file within 1–2 years**. Every WAL checkpoint, backup, page-cache miss, and unindexed scan gets slower as the file grows; `json_each` scans like `CreateMissingCardsFromTimelines` (`app/Actions/Cards/CreateMissingCardsFromTimelines.php:17-18`) degrade linearly with total timeline bytes.

**Action points:**
1. Decide a retention policy for `game_timelines` (replay feature is the only full-history consumer — `app/Http/Controllers/Games/ShowController.php:18`).
2. Add a prune step for timelines of old completed matches alongside `PruneProcessedLogEvents`.
3. Add a vacuum strategy.

**Fix guidelines:** keep timelines only for the last N days / last N matches (replay of ancient games can be declared out of scope, or re-derived from the on-disk `.dat` the way card-stats regeneration already does via `EnsureGameLogForMatch`). For `game_logs`, prefer storing only `file_path` + derived metadata and re-decoding on demand (the action already supports decode-on-demand), or compress `decoded_entries`. Enable `auto_vacuum = INCREMENTAL` (requires one-time VACUUM to activate) and run `PRAGMA incremental_vacuum` after the daily prune, or schedule a periodic full VACUUM at app idle/boot.

---

### C2. `PruneProcessedLogEvents::pruneCompleted` scales O(lifetime completed matches) and will eventually throw — taking the hard-cap prune down with it

**Location:** `/Volumes/Dev/mymtgo/client/app/Actions/Logs/PruneProcessedLogEvents.php:24-53`; scheduled daily at `/Volumes/Dev/mymtgo/client/app/Managers/MtgoManager.php:282-284`.

**Issue:** `pruneCompleted` plucks the token of **every Complete match ever recorded** (`MtgoMatch::where('state', Complete)->pluck('token')`, line 40) into PHP, then issues `LogEvent::...->whereIn('match_token', $completedTokens)->delete()` (lines 46-48) with the full list as bound parameters. Completed matches are never deleted, so this list grows monotonically forever. Two failure modes:
1. **Linear degradation:** the daily delete carries an IN-list of every match ever played, even when ~all of them have zero remaining events (their events were pruned months ago).
2. **Hard failure:** SQLite's default bound-parameter limit is 32,766. At ~32k lifetime completed matches the query throws "too many SQL variables". Because `run()` calls `pruneCompleted()` **before** `pruneStale()` (lines 26-27) with no per-step error isolation, the exception also kills the 30-day hard cap — the only mechanism that bounds `log_events` when the projection stalls (per the class's own comment, lines 14-21, a stalled prune previously produced a 400k-row table and multi-second pipeline ticks).

**Impact at scale:** a 30–100 match/day grinder hits 32k completed matches in 1–3 years; from that day the prune silently fails daily, `log_events` regrows unbounded, and the documented pipeline-stall incident recurs — this time with the safety net disabled. App becomes effectively unusable (multi-second ticks, write-lock timeouts) some weeks later.

**Action points:**
1. Replace the pluck-then-whereIn with a set-based query.
2. Isolate `pruneStale()` so a `pruneCompleted()` failure can never disable the hard cap.
3. Bound the work to recently completed matches.

**Fix guidelines:** use a subquery join (`whereIn('match_token', MtgoMatch::select('token')->where('state', Complete))`) so SQLite evaluates the set server-side with no parameter list; or track a "pruned" watermark (e.g. only consider matches whose `ended_at` falls after the last successful prune). Wrap each prune phase in its own try/catch and run `pruneStale()` first or independently.

---

## High

### H1. Live-match projection is O(n²): every tick reloads and re-decodes the match's full event history

**Location:**
- `/Volumes/Dev/mymtgo/client/app/Actions/Matches/AdvanceMatchState.php:33` (`LogEvent::where('match_id', $matchId)->orderBy('timestamp')->get()` — all events, all `raw_text` blobs, every tick)
- `/Volumes/Dev/mymtgo/client/app/Actions/Matches/CreateGames.php:84-103` (`extractPerGameData` → `ExtractMetaMessageEntries::run($match->token)` decodes the **entire** MetaMessage stream — called **once per game** per tick via the loop in `/Volumes/Dev/mymtgo/client/app/Actions/Matches/CreateOrUpdateGames.php:30-41`)
- `/Volumes/Dev/mymtgo/client/app/Actions/Matches/CreateGames.php:240-242` (`replaceTimeline`: delete + reinsert **all** timeline rows per game per tick)
- `/Volumes/Dev/mymtgo/client/app/Actions/Pipeline/ResolveMatchFromMetaMessages.php:41` (full re-decode again on the completion path)

**Issue:** the pipeline runs every second (`MtgoManager.php:247-249`). While a match has unprocessed events, each tick: loads every `log_event` for the match into memory (including full game-state JSON), re-decodes every MetaMessage byte array from scratch once per game (3-game match = 3 full-stream decodes), and rewrites every game's full timeline (delete-all + insert-all). Work per tick grows linearly with events-so-far, so total work over a match is quadratic; the timeline rewrite is also quadratic **write** amplification into WAL.

**Impact at scale:** long matches (control mirrors, multi-hour leagues) make tick cost climb continuously — exactly the "holding the SQLite write lock long enough to time out queue workers" regime the 2026-05-29 index migration describes. Doesn't grow with months of history, but it is the dominant live-path scaling defect and amplifies every other write-lock contention issue.

**Action points:**
1. Process only events past a per-match watermark (max processed `log_event.id`) instead of the full history.
2. Decode MetaMessage entries once per match per tick and pass the result down (`CreateOrUpdateGames` → `CreateGames` → `SyncGamePivots`), instead of once per game.
3. Append new timeline rows keyed by source event instead of delete+reinsert.

**Fix guidelines:** the `log_events` schema already has `processed_at` and a per-instance unique `(log_instance_id, byte_offset_start)` identity — a `last_projected_event_id` on the match (or filtering on `processed_at IS NULL` for the projection itself) gives an incremental cursor. For timelines, the unique source-event offset makes idempotent `insertOrIgnore` append possible.

### H2. `RunPipeline` Phase 3 tournament-token backfill is unbounded and runs a non-indexable LIKE per match, every second

**Location:** `/Volumes/Dev/mymtgo/client/app/Actions/Pipeline/RunPipeline.php:43-47`; `/Volumes/Dev/mymtgo/client/app/Actions/Matches/LinkMatchToTournament.php:31-35`.

**Issue:** every tick loads **all** matches with `tournament_event_id` set but `tournament_token` null — no limit, no time window, no attempt budget — and for each runs `where('raw_text', 'like', '%{token}%')` over `tournament_round_info` events (leading-wildcard LIKE; cannot use an index on the filtered subset's `raw_text`). A match whose `round_info` event was never ingested (app closed, log rotated) or was pruned by the 30-day hard cap **can never link**, so it stays in this set forever.

**Impact at scale:** the unlinkable set grows monotonically with tournament play. Each permanent resident costs a model hydration + a LIKE scan per second, forever. A few hundred unlinkable tournament matches over a year turns Phase 3 into hundreds of queries per tick.

**Action points:**
1. Window the query to matches younger than the log-event hard cap (linking is provably impossible once the candidate `round_info` rows are pruned).
2. Add `limit()` and/or an attempts counter.

**Fix guidelines:** mirror `RelinkOrphanMatches` (`app/Actions/Matches/RelinkOrphanMatches.php:29-52`), which already does this correctly with `started_at > now()-7d` + `limit(20)`. Optionally stamp a `tournament_link_failed_at` once a match ages past the cap so the predicate is indexable.

### H3. Ship queues retain sent rows (with full JSON payloads) forever; enqueue scans grow with table size

**Location:**
- `/Volumes/Dev/mymtgo/client/app/Jobs/ShipCardStats.php:132-141` (`markSent` — rows flipped to `sent`, never deleted)
- `/Volumes/Dev/mymtgo/client/app/Actions/Cards/EnqueueCardStats.php:17-54` (every minute: `whereDoesntHave('shipQueueEntry')` anti-join over all eligible `games`; inserts per-game JSON payload)
- `/Volumes/Dev/mymtgo/client/app/Jobs/ShipTournamentObservations.php:53-61` (same `sent` retention)
- `/Volumes/Dev/mymtgo/client/app/Actions/Tournaments/EnqueueTournamentObservations.php:19-27` (every second via `RunPipeline` Phase 4: `whereNotIn('id', subselect over the whole queue table)`)
- Schema: `/Volumes/Dev/mymtgo/client/database/migrations/2026_05_19_113013_create_card_stat_ship_queue_table.php:19-21` (FK to `games`, which are never deleted), `/Volumes/Dev/mymtgo/client/database/migrations/2026_04_21_120200_create_tournament_observation_queue_table.php:18` (FK to `log_events`, cascade — bounded only as a side effect of event pruning).

**Issue:** `card_stat_ship_queue` accumulates one row per game **forever**, each carrying the full card-stats JSON payload — a permanent second copy of per-game card data that is dead weight the moment it ships. The minute-by-minute `EnqueueCardStats` discovery query has to anti-join probe every eligible game in history each run, and the per-second `EnqueueTournamentObservations` `NOT IN` subselect scans the queue's `log_event_id` index each tick.

**Impact at scale:** ~18k rows/year of payload blobs for a regular player (more for grinders), plus O(total games) discovery work per minute, forever. (Side note: the cascade FK on `tournament_observation_queue` means the daily log-event prune deletes **pending** observations for completed matches — a small data-loss wrinkle worth confirming as intended.)

**Action points:**
1. Delete (or aggressively prune) `sent` rows after a short grace period in both queues.
2. Watermark `EnqueueCardStats` (e.g. persist the max game id examined) so discovery doesn't rescan all history every minute.

**Fix guidelines:** add a deletion step to the existing daily prune schedule; the unique constraints (`game_id`, `log_event_id`) already make re-enqueue idempotent if a deleted row's source ever re-qualifies, so pruning is safe.

### H4. Full card-stats regeneration: per-match job fan-out with per-job directory scans, and a destructive global wipe

**Location:**
- `/Volumes/Dev/mymtgo/client/app/Actions/RegenerateCardGameStats.php:49-63` (dispatches `ComputeCardGameStats` for **every** complete match)
- `/Volumes/Dev/mymtgo/client/app/Actions/Matches/EnsureGameLogForMatch.php:48-71` (`locateDatFile`: full `Finder` walk of the MTGO data directory **per match** when no decoded row exists)
- `/Volumes/Dev/mymtgo/client/app/Jobs/BackfillCardGameStats.php:17-27` and `/Volumes/Dev/mymtgo/client/app/Jobs/BackfillDeckJsonAndCardStats.php:34-39` (`DB::table('card_game_stats')->delete()` — full-table wipe, then per-match dispatch)

**Issue:** regeneration dispatches one queued job per historical match (thousands of rows through the SQLite-backed `jobs` table). Each job whose match lacks a decoded `GameLog` row walks the entire `Match_GameLog_*` directory — O(matches × files-on-disk) total, where files-on-disk itself grows with play history. The backfill variants additionally wipe `card_game_stats` globally before recomputing, so all stats pages are empty/wrong for the duration of a rebuild that lengthens as history grows (the wipe is also redundant: `ComputeCardGameStats` already delete-then-inserts per game, `ComputeCardGameStats.php:48`).

**Impact at scale:** with 5k matches and 10k `.dat` files, a regen is ~5k jobs × directory walks of 10k files — tens of millions of filesystem operations, hours of queue churn, and a stats blackout window that grows with history.

**Action points:**
1. Build the token → file-path map once per regeneration run and share it (this exact pattern already exists in `DiscoverGameLogsJob.php:45-57`).
2. Drop the global `card_game_stats` wipe; rely on the per-match delete-then-insert.
3. Chunk/batch dispatch and consider a single chunked job over match ids rather than one job per match.

**Fix guidelines:** pre-warm `GameLog.file_path` for all complete matches in one directory pass before dispatching; keep regeneration incremental (only matches whose inputs changed — decoder version bump already exists as `decoded_version`, `ReDecodeGameLogsJob.php:24`).

### H5. Log ingestion buffers the entire unread region in memory

**Location:** `/Volumes/Dev/mymtgo/client/app/Actions/Logs/IngestLogInstance.php:177-249` (`ingestBytes`: streams lines via `fgets`, but accumulates **all** parsed event rows — including full `raw_text` JSON blobs — in `$rows` until EOF; only then inserts in 500-row chunks, lines 243-249).

**Issue:** reading is streamed but committing is not. On first ingest of a large pre-existing `mtgo.log`, after a missed rotation, or after a cursor reset, the whole remaining file's classified events (raw text retained verbatim, PHP array overhead ~3–5×) sit in memory before the first insert. A 300 MB log day can mean >1 GB of PHP memory in the NativePHP runtime.

**Impact at scale:** O(unread bytes) memory per tick. Normal incremental ticks are tiny; the failure case is exactly the recovery scenario (backlog drain) where the app is already stressed — risk of OOM/worker death that re-triggers the same drain on the next tick, looping.

**Action points:** flush `$rows` to the DB every N parsed events (e.g. the existing 500) during the scan and advance `safeOffset` per flush, instead of single end-of-file commit.

**Fix guidelines:** the per-event byte offsets already computed (`byte_offset_start`/`end`) make mid-file checkpointing safe with the existing `insertOrIgnore` idempotency; cursor advance logic can move inside the flush loop unchanged.

---

## Medium

### M1. Zombie `InProgress` matches are re-evaluated every tick, forever

**Location:** `/Volumes/Dev/mymtgo/client/app/Actions/Matches/AbandonStaleMatches.php:35-56` (loads all `InProgress` matches each second; `evaluate` fetches the match's `match_state_changed` events, and `hasEndSignal` → early `return` leaves the match `InProgress`).

**Issue:** a match that carries an end signal but whose events were all marked processed (e.g. resolution failed transiently, then `markEventsProcessed` ran — `ProcessMatchEvents.php:151-161`) is skipped by the reaper on the assumption it is "resolvable by reprocessing", but nothing ever reprocesses it (discovery keys off **unprocessed** events, `ProcessMatchEvents.php:45-51`). Each such zombie adds 2+ queries per tick permanently.

**Impact at scale:** per-tick cost grows with accumulated zombies — slow drift over months; also a correctness leak (matches stuck `InProgress`).

**Fix guidelines:** give the end-signal branch its own staleness deadline: if a match has an end signal, no unprocessed events, and no activity for > N hours, force a resolution attempt (call `ResolveMatchFromMetaMessages` directly) or mark it terminal. Index-cheap guard: skip evaluation for matches already evaluated this hour (e.g. `last_reaped_at`).

### M2. `SyncDecks` parses every deck XML file every 5 minutes

**Location:** `/Volumes/Dev/mymtgo/client/app/Actions/Decks/SyncDecks.php:20-49` (scheduled `MtgoManager.php:258-260`): `simplexml_load_file` + json round-trip on **every** candidate file each run; the timestamp short-circuit (line 44) happens only **after** the parse.

**Issue/Impact:** O(deck files) XML parses per 5 minutes forever. With hundreds of decks across multiple candidate directories this is constant CPU/IO that grows with collection size, for a no-op in the steady state.

**Fix guidelines:** stat the file mtime (or cache a content hash) and compare against the stored latest `deck_versions.modified_at` before parsing; only parse changed files. Keep the full parse as fallback when timestamps look unreliable (doc'd MTGO trap).

### M3. `card_game_stats` row volume: ~80–150 rows per game, forever, with full-history aggregate queries

**Location:** writers `/Volumes/Dev/mymtgo/client/app/Jobs/ComputeCardGameStats.php:287-288, 371-372`; readers `/Volumes/Dev/mymtgo/client/app/Actions/Cards/GetCardGameStats.php:91-146` (GROUP BY over all rows for a deck's versions; opponent mode adds a second `COUNT(DISTINCT game_id)` pass, lines 99-104); reports variant feeds `whereIn` of potentially hundreds of version ids (`app/Actions/Reports/GetReportDeckVersionIds.php`).

**Issue/Impact:** the table grows ~linearly with games × deck size (1M+ rows after a few thousand matches). The compound index `(deck_version_id, oracle_id)` (migration `2026_04_10_000001:44-46`) keeps per-deck queries indexed, but "all-time, all versions" report aggregations scan an ever-larger fraction with no date dimension on the table — page latency grows linearly with lifetime games. Not dangerous, but it is the largest *structured* table and has no summarisation strategy.

**Fix guidelines:** acceptable for now; if report latency becomes visible, add a `played_at`/`match_started_at` denormalised column (indexable timeframe filter) or maintain incremental per-(deck_version, oracle) rollups and reserve row-level data for drill-down.

### M4. Opponents index aggregates all of `game_player` per page view

**Location:** `/Volumes/Dev/mymtgo/client/app/Http/Controllers/Opponents/IndexController.php:23-51` (5-table join grouped by player; `paginate(25)` must materialise the full grouped set to count; plus a second full distinct-format pass at lines 106-118).

**Issue/Impact:** O(total games) work per page view, growing linearly with history. Fine at 5k games; sluggish at 50k+.

**Fix guidelines:** cache the format list; consider a `player_stats` rollup maintained on match completion, or at minimum a covering index on `game_player(is_local, player_id, game_id)` and dropping the redundant per-page `allFormats` recomputation.

### M5. One-off import scan does O(history × matches) in-PHP filtering

**Location:** `/Volumes/Dev/mymtgo/client/app/Jobs/MatchAndScoreJob.php:48-53` and `/Volumes/Dev/mymtgo/client/app/Jobs/ParseAndFilterHistoryJob.php:49-53` — `in_array($r['Id'], $existingMtgoIds)` inside a filter, with `$existingMtgoIds` a plain array of every match id.

**Issue/Impact:** linear `in_array` inside a loop = O(n×m) string comparisons. 20k history records × 20k existing matches ≈ 400M comparisons — minutes of CPU during an import on a mature install.

**Fix guidelines:** flip the ids into a keyed map once (`array_flip` + `isset`) — O(n+m). Same applies to `findMatchingLog`'s `players LIKE` probe (`MatchAndScoreJob.php:130-135`), which would benefit from an index on `game_logs.first_timestamp`.

### M6. `jobs` table churn from every-second pipeline dispatch through SQLite

**Location:** `/Volumes/Dev/mymtgo/client/app/Managers/MtgoManager.php:247-249` (`RunPipelineJob` every second, `ShouldBeUnique` via cache lock — `app/Jobs/RunPipelineJob.php:24-40`); database queue driver is the default (`config/queue.php:16,38-45`).

**Issue/Impact:** ~86k insert+delete pairs/day into the same SQLite file the pipeline contends on, plus cache-table lock reads. Row count stays bounded; this is write-lock pressure and WAL churn, not growth — but it compounds every other contention finding (H1).

**Fix guidelines:** acceptable trade-off as designed; if contention resurfaces, run the tick from the scheduler process directly (it already serialises via `ShouldBeUnique`) or move queue/cache tables to a separate SQLite file so pipeline data writes don't share a write lock with job bookkeeping.

---

## Low

### L1. Sealed `log_instances` / `log_cursors` rows are never pruned
`/Volumes/Dev/mymtgo/client/app/Actions/Logs/SealLogInstance.php:16-19` seals but never deletes; rotation creates a new instance per day/rotation. Tiny rows, ~hundreds/year — cosmetic. Fold cleanup into the daily prune (delete sealed instances older than the hard cap; `log_events.log_instance_id` rows are pruned by then).

### L2. `import_scans` / `import_scan_matches` retained forever
One batch per manual scan (`app/Jobs/MatchAndScoreJob.php:112`); rows accumulate per scan run. Prune scans older than N days once their matches are imported/dismissed.

### L3. Grouped decks view loads all decks unpaginated
`/Volumes/Dev/mymtgo/client/app/Http/Controllers/Decks/IndexController.php:64-66` — grouped mode calls `$query->get()` (flat mode paginates at 12). Bounded by deck count (hundreds), each with three correlated count subqueries. Acceptable; cap or lazy-load groups if deck counts grow past ~500.

### L4. Per-tick filesystem scan for log paths
`/Volumes/Dev/mymtgo/client/app/Actions/Logs/FindMtgoLogPath.php:17-44` — recursive Finder walk (depth < 8) of the MTGO install tree, cached only 5 s, executed effectively every tick via `MtgoManager::ingestLogs()` (`MtgoManager.php:220-227`). Constant IO proportional to the install tree size. Lengthen the cache TTL (path changes are rare) and only force a rescan when the cached path stops resolving.

### L5. Debug log-events page issues `COUNT(*)` + leading-wildcard search over `log_events`
`/Volumes/Dev/mymtgo/client/app/Http/Controllers/Debug/LogEvents/IndexController.php:15-35` — `paginate(50)` count + `LIKE '%…%'` filters. Debug-only and the table is hard-capped at 30 days, so impact is bounded; switch to `simplePaginate` if it ever matters.

---

## Positive findings (verified)

- **Cursor-based, idempotent ingestion**: byte-offset cursor with rotation/truncation/anchor-hash detection (`IngestLogInstance.php`, `DetectLogRotation.php:12-33`) — reads only new bytes per tick; `insertOrIgnore` + unique offsets give safe reprocessing.
- **Pipeline discovery is covering-index backed** with documented 1850× speedup and deliberate avoidance of `whereNotIn` full scans (`ProcessMatchEvents.php:24-51`, migration `2026_05_29_130946:34-44`).
- **Hard-cap prune** exists precisely because unbounded `log_events` growth was observed in the wild (`PruneProcessedLogEvents.php:12-21`) — right instinct; C2 is about keeping it alive.
- **Indexing discipline**: two dedicated index-audit migrations (`2026_03_24_111622`, `2026_04_10_000001`) cover the hot FK/filter paths; `matches(state, started_at)`, `games(match_id, won)` etc.
- **Frontend payloads are disciplined**: matches/leagues/opponents/decks pages paginate (50/20/25/12); dashboard uses `Inertia::defer` for heavy widgets and SQL-side aggregation (`IndexController.php:35-108`); card stats aggregate in SQL, not PHP (`GetCardGameStats.php`).
- **Chunked backfills where it counts**: `ReDecodeGameLogsJob` uses `chunkById(100)` with per-row child jobs; `RegenerateDeckSignaturesJob` uses `lazyById(100)`; `DiscoverGameLogsJob` bulk-checks tokens in 500-row chunks.
- **SQLite tuned for concurrency**: WAL + `busy_timeout=30000` + `synchronous=NORMAL` (`config/database.php:41-43`); transient write errors retried without burning the match retry budget (`ProcessMatchEvents.php:175-189`).

---

## Prioritized action list

1. **(C2)** Make `pruneCompleted` set-based (subquery, not 32k-parameter IN-list) and isolate `pruneStale` so the hard cap can never be disabled by a sibling failure. *Small change, removes a guaranteed future outage.*
2. **(C1)** Define retention for `game_timelines` + `game_logs.decoded_entries`; add pruning to the daily schedule; enable incremental vacuum. *Biggest lever on DB file size.*
3. **(H2)** Window + limit the `RunPipeline` Phase 3 tournament backfill (mirror `RelinkOrphanMatches`). *One-line-ish fix to an every-second unbounded query.*
4. **(H3)** Prune `sent` ship-queue rows; watermark `EnqueueCardStats` discovery. Confirm the `tournament_observation_queue` cascade-on-event-prune is intended for pending rows.
5. **(H1)** Incremental match projection: per-match event watermark, single MetaMessage decode per tick, append-only timelines. *Largest live-path win; touches core pipeline so do it deliberately with the existing idempotency tests.*
6. **(H5)** Flush ingestion rows in batches during the file scan instead of buffering to EOF.
7. **(H4)** Pre-build the `.dat` token→path map for regeneration; drop the global `card_game_stats` wipe in backfills.
8. **(M1)** Add a terminal path for end-signal zombie matches so the reaper set stays bounded.
9. **(M2)** mtime/hash short-circuit in `SyncDecks` before XML parse.
10. **(M5)** `array_flip`/`isset` in import filtering; index `game_logs.first_timestamp`.
11. **(M3/M4)** Monitor report/opponents query latency as row counts grow; introduce rollups only when measured.
