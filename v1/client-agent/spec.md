# Client Agent — Ingestion, Compile, Outbox, Push

> Extracted from the v1 architecture brainstorm (2026-06-30). The thin local ingest agent (NativePHP/Electron). Produces [`../contract/spec.md`](../contract/spec.md); pushes to [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md). Auth in [`../client-auth/spec.md`](../client-auth/spec.md).

The client's job: tail MTGO logs → compile per-match `{match}.json` → push to the sink. It holds **no** queryable match data; that lives in the cloud and is served back via the API.

## 1. Live overlay (decoupled)

The overlay needs only a **decklist + where the cards are**, derived directly from live log events in memory. It does **not** read the DB or the API. New game → new decklist → reset. This lets ingestion fire every file-tick (no heavy DB work on the live path) and makes the overlay instant and offline-capable. The opponent-scout archetype is classified **locally, live** (it can't wait for the server) — see [`../catalog/spec.md`](../catalog/spec.md). UI detail in [`../client-ui/spec.md`](../client-ui/spec.md).

## 2. Match compiler + outbox

Tail logs → extract events → compile a per-match file `{match-key}.json` (full current state, **whole-file replace, not a diff**) → write to an **outbox** with sync status + a monotonic version → push to the sink → mark synced on a confirmed 200. The outbox survives offline/wifi-blips and retries. If the MTGO username is unresolved (the read is flaky), hold in the outbox rather than push an orphaned match.

**Match detection (verified against `storage/app/mtgo.log`).** A match is "ours" only if it has **actual game-message traffic** — `GsMessageMessage` lines carrying `MatchToken`+`MatchID`+`GameID`. The client logs `Match State Changed for <token>` lines for many tokens the local player **neither plays nor watches** (challenges / other events broadcast into the client); these carry **no** game traffic. So:

- Detect matches by game-message traffic, **never** from a state-change line or a `MatchJoinedCompletedState` alone → otherwise you compile orphan/empty matches.
- Genuine casual matches pass through `MatchNotJoined*` states early, so "NotJoined" is **not** a valid filter.
- Terminal state is always `...ClosedState` (prefix varies: `LeagueMatch*` / `TournamentMatch*` / plain `Match*`); Match Token is present at every transition including the end, so it binds games + outcome across the whole lifecycle.

**Port from 0.x, don't rewrite.** The MetaMessage decoder, event→match projection, and timestamp handling are **lifted from the 0.x client** — code relocation, not a rewrite. Log timestamps are local-clock; 0.x already normalizes them, so v1 inherits that. Much of the compiler is "pinch from 0.x": log tailing, `LogCursor`/`LogInstance` (rotation/shrink), event classification, deck XML parsing / signature.

**Push trigger — activity-based, never end-gated.** MTGO gives **no reliable match-end signal** — players quit with no signal; disconnect/"match ended" cues are noisy and reverse on reconnect; crashes leave event gaps then resume; maintenance can void + reimburse + roll back + replay a match (possibly vs a new opponent); disk-full silently stops the log. So do **not** gate the push on a terminal state. Trigger on:
- **debounced inactivity** for a match_token (no new events for N sec),
- a **new match_token starting**,
- **app-close flush**,
- a **periodic flush** for long matches.

Terminal `...ClosedState`, when it appears, is only a *hint* to push sooner.

**Never-final + validity.** Push whatever is compiled so far; more events later (reconnect, crash-rejoin, resume) → recompile → re-push → last-write-wins (idempotent). A match is **valid once it has ≥1 game**; zero-game tokens (observed / voided-before-play) are **dropped, never pushed**. Voided-then-replayed: the replay carries a **new match_token** → a clean new record; a voided remnant that had games stays as-is (best-effort — no special void detection).

**Outcome resolution pipeline (app-side).** Outcome is interpreted from events — all in `mtgo.log`, incl. disconnect/concession cues inside the `MetaMessage` binary (there is **no** separate game-log source). Run the match through an **ordered chain of outcome resolvers** (an Action + strategy classes, per SRP): each strategy attempts one detection (explicit result, game-win tally, concession/disconnect, timeout, …); first confident one wins. If **none resolve**, push with `outcome: Unknown` and flag the match into a **local "needs attention" area** in the desktop app (UI in [`../client-ui/spec.md`](../client-ui/spec.md)); the user sets the outcome there → the corrected value is **baked into `{match}.json`** (`outcome_source: "manual"`) and re-pushed. Manual outcome lives in the file, so server re-derivation preserves it. Capture-is-the-floor: the match is pushed and safe *before* it is resolved.

## 3. Raw archive (ship in v1, keep forever)

A separate local cold store (gzipped raw log segments + capture metadata), distinct from the operational SQLite. **Never uploaded.** Compile already isolates each match's log lines to build the json, so archiving the raw segment is **one extra write in the same pass**. Kept **forever** (raw text gzips small; no rolling cap). This grants full recompile reach on all history → compilation-layer bugs (log→json) become fixable: re-run the new compiler over stored raw segments → fresh json → re-push. Without it, compilation bugs in shipped versions bake into history once MTGO rotates the logs.

## 4. Local schema — thin (default connection)

> Runs on the app's **single default connection** — no separate `mymtgo.sqlite`. This repo *is* v1; the display schema was already gutted and the ingest schema consolidated onto the default connection. See [`../RECONCILIATION.md`](../RECONCILIATION.md).

Holds only the ingest machinery, the outbox, and local caches. Everything queryable-for-display moves to the cloud.

**Kept (ingestion machinery — inherently local):**
- `log_instances` — log-file identity across rotations.
- `log_cursors` — byte offset / rotation detection.
- `log_events` — **only the classified events we care about** (NOT every raw line like 0.x, which stored everything). Filtered this set is small, so it is **kept indefinitely** (disk is cheap; no prune step). The gzipped raw archive is the separate full-fidelity floor.
- `jobs`, `cache` — local Laravel queue for ingest / compile / push jobs.

**New:**
- `outbox` — one row per compiled match awaiting/confirming sync: `match_key`, `payload` (the `{match}.json`, or a path to it), `file_version`, `status` (pending / syncing / synced / failed / dead), `attempts`, `last_attempt_at`, `last_error`, `synced_version`. Survives offline; idempotent retry.
- `archetype_catalog` (read-only mirror) — archetypes synced from the API so the scout window can classify **live, offline**. Holds enough to detect: uuid, name, format, color_identity, and variant cardlists.
- `app_account` (or reuse a trimmed `accounts`) — binds the local install to the signed-in app user: OAuth tokens (see [`../client-auth/spec.md`](../client-auth/spec.md)), app `user_id`, resolved `mtgo_username` + `mtgo_player_id`, `active`/`tracked`.
- `raw_archive` index — `match_key`, file path, captured_at, byte range. The blobs live outside SQLite.

**Dropped locally (now cloud-owned, sent inline in `{match}.json`, served back via API):**
`matches`, `games`, `game_player`, `players`, `card_game_stats`, `decks`, `deck_versions`, `archetypes` (+ `archetype_decks`, `archetype_deck_cards`, `match_archetypes`), `leagues`, `tournaments`, `cards` catalog.

**Not persisted at all:** live overlay state (in-memory, rebuilt from events each game). The current deck for the overlay is read from the MTGO XML on disk, not stored.

## 5. Near-realtime ingestion

Goal: minimize the gap between MTGO writing a log line and the agent picking it up — primarily to make the **live overlay** feel instant. Today it's a **150ms stat-poll** (worst-case ≈ 150ms + read). The thin agent has headroom to do better. Three layers:

1. **Event-driven watch (the win).** Watch the log file via the OS change API — on Windows, `ReadDirectoryChangesW`, exposed through Electron/Node `fs.watch`. The **Electron main process watches the file and signals PHP to run a pipeline tick immediately** on change (sub-ms to a few ms). PHP-side watching is out — PHP has no reliable native FS events on Windows; the watcher lives in the Electron layer.
2. **Poll backstop.** `fs.watch` on Windows can coalesce or miss events → keep a **slow poll (~500ms)** purely as a safety net. Event-driven on the happy path; poll for correctness. (The existing cursor `stuck_ticks` force-reset guards this defensively.)
3. **Open read handle.** Read incrementally from the cursor offset (hold the handle open) instead of open→seek→read→close each tick.

**The floor is MTGO's own disk flush** — the agent can only read what MTGO has flushed. It flushes promptly enough that 150ms polling already feels live, so event-watch tracks it closely. Beating the flush entirely would need reading MTGO's in-memory state (MTGOSDK) — **out of scope**: v1 is built on log files, and the SDK is the separate bot-automation track.

**Scope note:** near-realtime serves the **live overlay**. The compile→push path stays deliberately debounced (§2) — the cloud does not need sub-second.
