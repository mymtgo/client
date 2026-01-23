# Pipelines

This doc describes **how data flows** from MTGO files into the local database.

---

## 1) Log Ingestion Pipeline

### Inputs

* One or more candidate log file locations
* Files matching `Match_GameLog_*.dat`

### Invariants

* Always ingest from the **latest/active** log file.
* Use a **cursor** to avoid reprocessing.
* Handle log rotation safely.

### Flow

```
Discover log files -> Select active log -> Read new bytes (LogCursor)
                  -> Normalize lines -> Classify events -> Persist
```

### Components

* `GetLogFiles` / Finder logic

    * Finds candidate log files across possible directories
    * Selects the active log (typically newest mtime)

* `LogCursor`

    * Stores byte offset per active log identity
    * On rotation/new log:

        * Detect switch
        * Reset/roll cursor safely

* `IngestLog` (Job/Action)

    * Reads from last cursor position
    * Produces parseable records (lines/events)

* `ClassifyLogEvent`

    * Converts raw records into typed events
    * Ignores MetaMessage JSON blocks
    * Groups by Match/Game

---

## 1a) Match & Game Construction (Authoritative)

Matches and Games are **not inferred from the database schema**.
They are built incrementally by projecting classified LogEvents
into domain state.

The database represents the *result* of this projection — not the source.

---

### Core Principle

> **LogEvents are the source of truth.**
> Matches/Games are *derived* state.

Agents must reason about matches by following the event pipeline,
not by inspecting tables in isolation.

---

## Match Context

While ingesting logs, the system maintains a **current match context**.

Conceptually:

- A match begins when a log record emits a Match identifier
  (`MatchID`, `MatchToken`, or equivalent MTGO header).
- This identifier becomes the **active match context**.
- All subsequent game-related events are attributed to this match
  until the context changes or is explicitly closed.

Important constraints:

- Log files may:
    - Re-emit match headers
    - Interleave non-game events
    - Emit game events before a clean “match started” signal
- The parser must tolerate all of the above.

**Never** assign events to a match using timestamps or ordering heuristics alone.

---

## Game Construction

Games are built **inside** an active match.

A new Game is created when:
- A “Game Started” / “New Game” / equivalent MTGO event is observed
- OR when a prior game has conclusively ended and a new game signal appears

A Game may end when:
- A player concedes
- A player’s life total reaches zero
- A disconnect / client close pattern is detected
- MTGO emits a definitive game-end signal

Important:
- A game may end **without** an explicit “winner” event
- Game results are often **inferred**, not declared

---

## Player Attribution

Players are associated with games via `game_player`.

Rules:
- The local player is identified via `Mtgo::getUsername()`
- Opponents are inferred from:
    - Match headers
    - Game start events
    - Repeated opponent name references

Player attribution is stable per match, even if:
- A game ends abruptly
- A rematch occurs in a league context

---

## Match Completion

A match is considered complete when:
- All constituent games have conclusively ended
- OR MTGO emits a match-complete signal
- OR no further game events appear for that match and context switches

Important:
- Not all matches end cleanly
- Some matches remain partially observed
- The system must allow incomplete matches to exist

---

## Failure & Edge Cases (First-Class)

The following are *expected* and must not corrupt state:

- MTGO client crashes mid-game
- Opponent disconnects
- User closes MTGO window
- Logs end abruptly
- Duplicate or delayed log lines
- Reprocessing due to cursor reset

The ingestion pipeline must be:
- Idempotent
- Append-only in nature
- Safe to re-run without duplicating matches or games

---

## Authoritative Code Paths

When reasoning about this pipeline, defer to:

- `IngestLog`
- `ClassifyLogEvent`
- `app/Actions/Matches/*`
- `app/Actions/Games/*`

These classes collectively define:
- How context is maintained
- How events mutate match/game state
- How inference rules are applied

Do not assume that match logic lives in a single file.


### Outputs

* `matches`, `games`, `game_player`, event tables (if present)

---

## 2) Deck Sync Pipeline

### Inputs

* One or more candidate deck XML locations

### Invariants

* Deck XML may exist in multiple places.
* Always prefer **the latest timestamp** for versioning.
* Never regress a deck to an older version.

### Flow

```
Discover deck files -> Parse XML -> Identify deck (NetDeckId)
                   -> Compare timestamps -> Upsert deck + versions
```

### Components

* `GetDeckFiles`

    * Discovers candidate XML files (multiple locations)
    * Filters to deck group exports:

        * `GroupingType = Deck`

* `SyncDecks`

    * Locates deck by `NetDeckId`
    * Determines file modified timestamp (from XML Timestamp)
    * If newer than latest version:

        * Create new `deck_versions` entry
        * Store mainboard/sideboard snapshot
    * Uses soft deletes (restore when deck reappears)

### Missing behaviour (known gap)

* If MTGO removes a deck file, the app currently may **not** remove/soft-delete the corresponding deck.

    * Desired behaviour: detect missing files and soft-delete decks/versions as appropriate.

---

## 3) Purging / Retention

* Old processed logs may be purged after ~1 day grace.
* The app may keep only what is needed for stats.

---

# END
