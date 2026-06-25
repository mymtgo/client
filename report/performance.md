# Performance Audit — MTGO Deck Tracker (client)

**Date:** 2026-06-11
**Scope:** Pipeline actions (`app/Actions/Pipeline`, `app/Actions/Logs`, `app/Actions/Matches`), jobs, `MtgoManager`, controllers/Inertia data loading, migrations/indexes, and the deck card-stats frontend.

**Summary.** The hot path is the every-second pipeline tick (`MtgoManager::schedule` → `RunPipelineJob` → `RunPipeline`). Indexing on `log_events` and `matches` is in good shape (the covering discovery index work in `2026_05_29` was effective), and the Inertia controllers are largely well-optimized (aggregate queries, deferred props, pagination). The dominant remaining problems are *per-tick repetition*: every second the app (a) walks the MTGO filesystem tree twice, (b) re-loads and re-projects the **entire** event history of every active match — including re-decoding every MetaMessage and rewriting every game timeline row — and (c) re-runs unbounded "retry forever" backfill passes (`LinkMatchToTournament` `LIKE '%token%'` scans, `RelinkOrphanMatches`). These are O(match-history) per tick, i.e. quadratic over the life of a match, and they hold the single SQLite write lock — the exact contention the code comments repeatedly fight ("database is locked", queue worker timeouts). The expensive MetaMessage decode is never cached, so the same bytes are regex-decoded dozens of times across the pipeline, reaper, card-stats jobs, and the match Show page.

---

## Critical

### C1. Live matches are fully re-projected every tick — O(n) per tick, O(n²) per match

**Location:**
- `app/Actions/Matches/AdvanceMatchState.php:33` (`LogEvent::where('match_id', $matchId)->orderBy('timestamp')->get()`)
- `app/Actions/Matches/CreateOrUpdateGames.php:16-42` (called from `AdvanceMatchState.php:176` and `:236` on every tick while `InProgress`)
- `app/Actions/Matches/CreateGames.php:86` (`extractPerGameData` → `ExtractMetaMessageEntries::run($match->token)` — once **per game**, inside the games loop)
- `app/Actions/Matches/CreateGames.php:212-243` (`replaceTimeline`: `GameTimeline::where(...)->delete()` + `GameTimeline::insert($events)` of **all** snapshots, every tick, per game)

**Issue.** `ProcessMatchEvents` correctly discovers work via unprocessed events, but `AdvanceMatchState` then loads **all** `log_events` for the match (processed included — `game_state_update` rows carry full game-state JSON snapshots in `raw_text`), re-parses each snapshot with the char-by-char `ExtractJson`, and `CreateGames` re-runs `ExtractMetaMessageEntries` (query + JSON extraction + 24-pattern regex decode of every MetaMessage byte array) once per game per tick. `replaceTimeline` then deletes and re-inserts every timeline row for every game, every second, inside the `AdvanceMatchState` write transaction.

**Impact.** Work per tick grows linearly with match length; total work grows quadratically. A long game with hundreds of snapshots and thousands of MetaMessage events turns each 1-second tick into hundreds of ms of CPU plus a large delete+insert write transaction — precisely when the user is mid-game and the overlay windows are querying. This is the primary driver of the SQLite write-lock contention the codebase keeps patching around (`TimedTransaction`, `IsTransientWriteError`, the "no outer transaction" comment in `ProcessMatchEvents.php:130`).

**Action points.**
1. Track per-match progress (e.g. last projected `log_events.id` on the match, or only select events with `processed_at IS NULL` plus the minimal anchor events needed: the join event, latest snapshot per game).
2. Make `replaceTimeline` incremental: insert only snapshots newer than the latest stored `game_timelines.timestamp` for the game instead of delete-all + reinsert-all.
3. Hoist `ExtractMetaMessageEntries` out of the per-game loop in `CreateOrUpdateGames`/`CreateGames` — decode once per match per tick and pass the entries down (the per-game data is already index-keyed).
4. Skip `CreateOrUpdateGames` entirely when no new `game_state_update`/`game_management_json` rows arrived this tick (cheap unprocessed-count check).

**Fix guidelines.** Preserve idempotency: the projection must still converge from any partial state (cursor resets, reprocessing). Gate the *work*, not the *correctness* — a "nothing new for this match" early-return keeps full reprojection available as the recovery path. Do not key incremental timeline inserts on log order alone (logs lie about order); key on `(game_id, timestamp, byte_offset)` style identity.

---

### C2. Two recursive filesystem scans of the MTGO tree on every 1-second tick

**Location:**
- `app/Actions/Pipeline/RunPipeline.php:17` (`pathsAreValid()`)
- `app/Managers/MtgoManager.php:220-227` (`ingestLogs()` → `canRun()` at `:200` → `pathsAreValid()` again at `:234-239`)
- `app/Actions/Settings/ValidatePath.php:23-30` (`Finder` over the whole log path, `depth('< 8')`, `iterator_count`)
- Tick frequency: `app/Managers/MtgoManager.php:248` (`->everySecond()`)

**Issue.** `RunPipeline` validates paths, then `ingestLogs()` validates them again — each validation is a full recursive `Finder` walk of the configured log root. The default root is `%USERPROFILE%\AppData\Local\Apps\2.0` (`MtgoManager::defaultLogPath`), the Windows ClickOnce store, which routinely contains tens of thousands of files/directories. `FindMtgoLogPath::all()` is sensibly cached for 5 s, but `ValidatePath::forLogs` has **no cache** and runs 2× per second.

**Impact.** Constant disk + CPU churn every second for the lifetime of the app; on cold NTFS caches or AV-scanned directories this alone can consume the whole tick budget and delay ingestion. Unbounded degradation as the ClickOnce store grows.

**Action points.**
1. Cache the `pathsAreValid()` result with the same 5-second (or longer) TTL as `FindMtgoLogPath::all()`, or derive validity from `FindMtgoLogPath::all()->isNotEmpty()` so one cached scan serves both.
2. Remove the duplicate check: `RunPipeline` already verified paths; `ingestLogs()` does not need `canRun()` to re-verify within the same tick (split "watcher active" from "paths valid").
3. Consider invalidating the cache only on settings change + a slow (60 s) background revalidation.

**Fix guidelines.** Keep one source of truth for "where are the logs" (the cached scan) and have validity be a view over it. The failure mode to protect is the user pointing at a wrong directory — that does not need re-detection at 1 Hz.

---

## High

### H1. `LinkMatchToTournament` backfill: unindexed `LIKE '%token%'` scan per unlinked match, every tick, forever

**Location:** `app/Actions/Pipeline/RunPipeline.php:43-47`; `app/Actions/Matches/LinkMatchToTournament.php:33-37` (`where('raw_text', 'like', '%'.$match->token.'%')`).

**Issue.** Phase 3 selects every match with `tournament_event_id` set but no `tournament_token`, and for each runs a `LIKE '%…%'` over `log_events.raw_text` — a full-table scan of the largest column in the largest table (no index can serve a leading-wildcard LIKE). If the `round_info` event never arrives (common — the user closed MTGO before it logged), the same scan repeats every second until the events are pruned, and the match retries **forever** (no attempt budget, no time window).

**Impact.** With a few permanently-unlinkable tournament matches and a six-figure `log_events` table, this adds multiple full scans per tick — the same class of regression the `2026_05_29` migration fixed for discovery.

**Action points.**
1. Narrow the scan: filter on `event_type = 'tournament_round_info'` first (indexed) — already done — but also add a `whereNotNull('tournament_token')` + match via the token column where possible instead of `raw_text`.
2. Window the backfill: only retry matches with `started_at` within N days, and/or stamp an `attempts`/`last_checked_at` with exponential backoff.
3. Better: at classification time, extract the per-match token from the round_info payload into `log_events.match_token` so this becomes an indexed join instead of a substring search.

**Fix guidelines.** The right long-term shape is "parse once at ingest, join on columns later" — never substring-search `raw_text` on the hot path. Keep one slow-path recovery sweep (daily) for stragglers.

### H2. `RelinkOrphanMatches` runs every tick with no backoff — up to 40 `DetermineMatchDeck`/`AssignLeague` retries per second for 7 days

**Location:** `app/Actions/Pipeline/RunPipeline.php:55`; `app/Actions/Matches/RelinkOrphanMatches.php:31-52`; `app/Actions/Matches/DetermineMatchDeck.php` (games pluck + `deck_used` event fetch + `ExtractJson` + signature + `DeckVersion` lookup with `whereHas` subquery, plus a diagnostic `count()` query on miss).

**Issue.** Matches whose deck signature will *never* match a local `DeckVersion` (borrowed/imported decks, rental services) stay `deck_version_id = NULL` and are re-evaluated every single second for `withinDays = 7`. Each evaluation is 4-6 queries plus JSON parsing and signature hashing. Same for the league re-assignment pass (which runs two `LIKE '%MatchJoinedEventUnderwayState%'` queries per match — scoped by token index, but still per-tick). It is additionally invoked from `SyncDecks` with `limit: 200`.

**Impact.** A handful of permanently-orphaned recent matches converts into a steady ~50-100 queries/second of pure retry noise competing for the write lock window.

**Action points.**
1. Run the relink pass on deck-sync events (after `SyncDecks` creates versions — already done) and on a slow schedule (every 1-5 min), not inside the 1 Hz tick.
2. Or store `relink_attempted_at` and skip matches retried within the last few minutes (exponential backoff).
3. Cache the computed signature per match so retries skip re-extracting/re-hashing the deck JSON.

**Fix guidelines.** The trigger for a relink succeeding is "a new DeckVersion appeared", not "a second elapsed". Make the cheap path event-driven and keep the per-tick path empty.

### H3. `GameCardsSnapshotChanged` fires every tick while a match is in progress — deck-odds window reloads at 1 Hz

**Location:** `app/Actions/Matches/AdvanceMatchState.php:178-180` (dispatch inside the per-tick `InProgress` branch, unconditional); `resources/js/pages/decks/Popout.vue:24-27` (`router.reload({ only: ['drawOdds'] })` on every event); `app/Actions/Cards/ComputeDrawOdds.php` (per reload: latest game query, latest timeline snapshot query, `Card::whereIn` metadata fetch, full odds computation).

**Issue.** The event is named "snapshot changed" but is dispatched on every pipeline tick for an in-progress match regardless of whether any new snapshot arrived. With the deck-odds overlay open, that is a full Inertia partial reload + 3-4 queries + JSON decode of the latest (large) snapshot, every second, for the whole match.

**Impact.** Continuous renderer + HTTP + DB load in the Electron overlay during gameplay — exactly when the machine is busiest; also contends with C1's write transactions.

**Action points.**
1. Dispatch only when `CreateOrUpdateGames` actually ingested at least one new `game_state_update` event this tick (it knows; thread a boolean back up).
2. Optionally include the latest snapshot id/timestamp in the event payload so the frontend can skip reloads it has already rendered.

**Fix guidelines.** Make the event mean what it says. The reload path itself is fine — the frequency is the problem.

### H4. First/backlog ingest builds the entire pending event set in memory, in one tick

**Location:** `app/Actions/Logs/IngestLogInstance.php:190-249` (`$rows[]` accumulation across the whole unread region; insert chunked at `:244` but the array is fully materialized first; no per-tick byte/row cap).

**Issue.** Reading is correctly streamed with `fgets`, but every classified event row (including full `raw_text` — game-state JSON and MetaMessage int arrays run to tens of KB each) is accumulated into one PHP array before any insert. A first run against a large `mtgo.log` (hundreds of MB after a long session) processes the entire backlog in a single tick: a large memory spike, and a burst of 500-row insert transactions back-to-back holding the write lock while `RunPipelineJob` has a 300 s timeout.

**Impact.** Memory pressure (worst case: OOM in the NativePHP worker) and a multi-minute lock-hostile catch-up during which the UI's own queries stall — typically at first launch, the worst possible first impression.

**Action points.**
1. Add a per-tick budget (e.g. read at most N MB or M events per tick); the cursor design already makes resumption free.
2. Flush + clear `$rows` every chunk instead of accumulating the full set before inserting.
3. Optionally yield between chunks (sleep 0) so queue workers can interleave.

**Fix guidelines.** The cursor (`safeOffset`) only advances after parse, so capping a tick mid-file is safe — just stop at the last complete event boundary, exactly as the EOF partial-event logic already does.

### H5. MetaMessage decode results are never persisted — the same bytes are regex-decoded many times

**Location:** `app/Actions/Matches/ExtractMetaMessageEntries.php:23-54` (per call: query + `ExtractJson` + `DecodeMetaMessageText` over every `game_management_json` event). Callers: `app/Actions/Pipeline/ResolveMatchFromMetaMessages.php:41` (per tick once the completed signal exists), `app/Actions/Matches/CreateGames.php:86` (per game per tick — see C1), `app/Actions/Matches/AbandonStaleMatches.php` (`resolveByDisconnect`), `app/Jobs/ComputeCardGameStats.php` (per regeneration), `app/Actions/Matches/GetGameLogEntries.php:?` (per game on the match Show page — see M3).

**Issue.** Decoding one event means a char-by-char balanced-JSON extraction over the raw text, building a candidate ASCII string from a byte array, and up to 24 `preg_match` attempts (`DecodeMetaMessageText::PATTERNS`). The result is deterministic per row, yet it is recomputed on every call site, every tick.

**Impact.** Multiplies C1's cost; CPU-bound regex work dominating pipeline ticks for chatty matches.

**Action points.**
1. Persist the decoded message once: either a nullable `decoded_text` column on `log_events` populated at classification time (`ClassifyLogEvent` already has the JSON in hand for `game_management_json`), or a per-match decoded-entries cache invalidated by max event id.
2. Have `ExtractMetaMessageEntries` read the stored text and fall back to decoding only legacy/null rows.

**Fix guidelines.** Decode-at-ingest fits the architecture ("parse once, project many"). Size cost is small — the decoded line is a short sentence vs. the multi-KB raw payload — and `PruneProcessedLogEvents` already bounds table growth.

---

## Medium

### M1. `AbandonStaleMatches` runs at 1 Hz and scales with stuck `InProgress` matches

**Location:** `app/Actions/Matches/AbandonStaleMatches.php:34-46` (loads every `InProgress` match each tick), `:177-183` (`lastActivityAt`: `where('match_token', …)->orWhere('match_id', …)->max('logged_at')` — the OR forces SQLite to either union two index scans or fall back to a scan).

**Issue.** Each tick, every non-failed `InProgress` match costs at least 2 queries (state changes + last activity) even though the reaper's cutoff is measured in **minutes** (default 60). A backlog of stuck matches (the very situation the reaper exists for) multiplies per-tick load.

**Action points.** Run the reaper every 30-60 s (separate schedule entry), not inside every pipeline tick; pre-filter candidates with a single aggregated query (max `logged_at` grouped by token) instead of per-match queries.

**Fix guidelines.** Nothing the reaper decides is time-critical at 1 s granularity; its semantics are unchanged at 1/min.

### M2. Every-second job dispatch through the SQLite-backed queue + cache

**Location:** `app/Managers/MtgoManager.php:247-249` (`$schedule->job(new RunPipelineJob)->everySecond()`); `config/queue.php:16` (default `database`); `RunPipelineJob` is `ShouldBeUnique` (unique lock via cache, also database-backed per `0001_01_01_000001_create_cache_table.php`).

**Issue.** Each second the scheduler inserts a `jobs` row + acquires/releases a unique lock in the `cache` table; the worker updates `reserved_at` and deletes the row — ~4-6 writes/sec of pure orchestration on the same SQLite file the pipeline needs the write lock for. Code comments (`ProcessMatchEvents.php:130`, `AdvanceMatchState.php:241`) show this contention has already caused 30 s queue timeouts. Add the per-tick `log_instances.last_seen_at` save (`IngestLogInstance.php:64-65`) and cursor saves, and the app has a constant background write load even when fully idle.

**Action points.**
1. Run the pipeline tick in-process from the scheduler with a file-based lock (`onOneServer`/`Cache::lock` on a `file` store) instead of round-tripping through the `jobs` table.
2. Or move queue + cache to separate SQLite files (`database.connections` per store) so orchestration writes never contend with domain writes.
3. Skip the `last_seen_at` save when nothing was ingested, or update it at most every N ticks.

**Fix guidelines.** SQLite has one writer; the cheapest win is making the idle tick write nothing at all.

### M3. Match Show page decodes the full MetaMessage stream once per game

**Location:** `app/Http/Controllers/Matches/ShowController.php:66-68` (`GetGameLogEntries::run($game)` per game); `app/Actions/Matches/GetGameLogEntries.php` (each call runs `ExtractMetaMessageEntries::run($match->token)` over the whole match, then filters to the game window).

**Issue.** A 3-game match decodes the identical entry set 3 times in one request. Combined with eager-loading `games.timeline` (every full snapshot JSON) the Show page does significant duplicate work.

**Action points.** Decode once in the controller and pass entries into a per-game filter; benefits compound with H5 (persisted decode makes this nearly free).

**Fix guidelines.** Pure call-site refactor; output identical.

### M4. `EnqueueTournamentObservations` anti-join runs every tick

**Location:** `app/Actions/Tournaments/EnqueueTournamentObservations.php:20-27` (`whereIn(event_type, …)->whereNotIn('id', select log_event_id from tournament_observation_queue)` each second).

**Issue.** The `NOT IN` materializes the queue's full `log_event_id` set per tick, and the pass runs even when the tick ingested zero tournament events (the overwhelmingly common case).

**Action points.** Only run when the tick classified at least one tournament event (ingest can flag it), or move to the 30 s `ShipTournamentObservations` cadence; keep the anti-join as a slow-path sweep.

**Fix guidelines.** The unique FK already guarantees idempotency — frequency is free to reduce.

### M5. `PruneProcessedLogEvents` uses an unbounded `whereIn` token list

**Location:** `app/Actions/Logs/PruneProcessedLogEvents.php:40-47` (`pluck('token')` of **all** completed matches ever, then `whereIn('match_token', $tokens)->delete()`).

**Issue.** The token list grows with total match history; large IN lists risk SQLite's bound-parameter limits and produce a slow single delete. Also `log_events.ingested_at` (used by the hard-cap prune at `:66`) has no index — a daily full scan, acceptable today but it competes with the tick when the table is at its largest (the exact stall scenario the hard cap exists for).

**Action points.** Invert the predicate (join/`whereIn` on a subquery instead of a PHP-materialized list), chunk deletes, and consider pruning a match's events at the moment it transitions to `Complete` (bounded, incremental) with the daily job as the sweep.

### M6. `SyncDecks` does synchronous external API calls and full XML re-parses per run

**Location:** `app/Actions/Decks/SyncDecks.php` — `simplexml_load_file` + `json_decode(json_encode(...))` for every deck file every 5 min regardless of mtime; `prefillArchetype` → `DetermineDeckArchetype::run` (HTTP) inline in the loop for every deck without an archetype, every run; `ComputeDeckIdentity` per updated deck.

**Issue.** Decks that the estimate API can never classify are re-submitted every 5 minutes forever; unchanged XML files are re-parsed every run (the timestamp short-circuit happens *after* parsing).

**Action points.** Skip files whose filesystem mtime hasn't changed since the last sync (cheap stat before parse); stamp `archetype_prefill_attempted_at` and back off; move the HTTP call onto a queued job so a slow API can't stretch the sync.

### M7. `GetDeckViewSharedProps` trophy computation loads all league matches per page view

**Location:** `app/Actions/Decks/GetDeckViewSharedProps.php:34-46` (fetch all league matches for the deck, group/filter in PHP) — runs on **every** deck sub-page request (Matches, CardStats, GameStats, …), alongside `GetDeckStats` which independently computes trophies via aggregates.

**Action points.** Replace with one aggregate query (leagues having 5/5 wins, as `GetDeckStats.php` already does) and reuse between the two call sites; result is per-deck cacheable keyed on latest match id.

---

## Low

### L1. Card-stats table renders all rows with per-row ContextMenu wrappers, no virtualization

**Location:** `resources/js/components/cards/CardStatsView.vue:635-655` (`v-for` over `filteredAndSortedStats`, each row wrapped in `ContextMenu`/`ContextMenuTrigger`; `DeckCardStatsRow` adds per-cell Tooltip providers).

**Issue.** Fine at ~75 maindeck cards; the "Their Cards" perspective can return several hundred distinct opponent cards, making initial render and re-sort noticeably heavier (each Reka UI ContextMenu/Tooltip instantiates floating-ui state). The computed pipeline itself (`useShrinkage` + decorate-sort-undecorate, `CardStatsView.vue:336-359`) is well-structured and not a concern.

**Action points.** If "theirs" lists grow: lazy-mount the ContextMenu (single shared menu positioned on `contextmenu` event) and/or add list virtualization beyond ~200 rows. Not worth it for the current row counts.

### L2. Per-tick log spam in the pipeline channel

**Location:** `app/Actions/Matches/AdvanceMatchState.php:160-163` ("gameMeta keys" info logged every tick per active match); `CreateGames.php:80` ("game … created/updated" per game per tick); `AdvanceMatchState.php:51` (per-tick warning for matches awaiting a join event).

**Issue.** Disk writes and log-file growth proportional to tick rate × active matches; also drowns real signals.

**Action points.** Demote to debug or log only on state transition / first occurrence per match.

### L3. `EnsureGameLogForMatch::locateDatFile` re-walks the data directory per call

**Location:** `app/Actions/Matches/EnsureGameLogForMatch.php` (Finder over all `*Match_GameLog*` files per match token).

**Action points.** Build the filename→token map once per job batch (regeneration runs iterate many matches), or cache the directory listing for the job's lifetime.

### L4. Minor duplicate work in `BuildMatchGameData`

**Location:** `app/Actions/Matches/BuildMatchGameData.php:118` and `:148` (timeline `sortBy('timestamp')` computed twice per game).

**Action points.** Sort once and share; trivial.

### L5. Missing index: `log_events.ingested_at`

**Location:** used by `PruneProcessedLogEvents::pruneStale` (daily full scan) and the 2-minute staleness probes in `ProcessMatchEvents.php:217-222/229-234` (those are match_token-scoped first, so indexed adequately).

**Action points.** Add only if M5's at-completion pruning is not adopted; otherwise the daily scan is acceptable.

---

## Prioritized Action List

1. **(C2)** Cache/deduplicate `pathsAreValid()` — removes 2 recursive filesystem walks per second. Smallest change, immediate constant-cost win.
2. **(H3)** Dispatch `GameCardsSnapshotChanged` only when a new snapshot was actually ingested — stops the 1 Hz overlay reload loop.
3. **(C1)** Make the live-match projection incremental: skip `CreateOrUpdateGames` when no new events; incremental `replaceTimeline`; hoist `ExtractMetaMessageEntries` out of the per-game loop. Biggest in-game smoothness win.
4. **(H5)** Persist decoded MetaMessage text at classification time; make all consumers read it. Multiplies the value of #3 and fixes M3 almost for free.
5. **(H1)** Replace the `LIKE '%token%'` tournament backfill with an indexed token column + windowed/backoff retries.
6. **(H2)** Move `RelinkOrphanMatches` off the 1 Hz tick (event-driven after `SyncDecks` + slow sweep with backoff).
7. **(H4)** Cap ingest per tick and flush row chunks during parse — bounds first-run memory and lock bursts.
8. **(M2)** Run the pipeline tick without the SQLite `jobs`/`cache` round-trip (file lock or separate DB files); skip idle-tick `last_seen_at` writes.
9. **(M1, M4)** Demote `AbandonStaleMatches` and `EnqueueTournamentObservations` to 30-60 s cadences.
10. **(M5, M6, M7)** Prune events at match completion; mtime-gate and de-loop SyncDecks' API calls; aggregate the trophies query.
11. **(L1-L5)** Polish items as touched.
