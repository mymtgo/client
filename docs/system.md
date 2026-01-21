# System Overview (MTGO Deck Tracker)

## Purpose

A **local-first** desktop app for MTGO that ingests **local files** (logs + deck XML) to build a structured database of:

* Matches, games, players
* Decks and deck versions
* Actions/events (mulligans, sideboarding, etc.)

There is **no MTGO API integration**.

---

## Data Sources

### 1) Game logs

* Pattern: `Match_GameLog_*.dat`
* Characteristics:

    * Semi-binary / noisy
    * Contain match/game events and state transitions

### 2) Deck XML files

* Produced/updated by MTGO when decks are saved/edited
* Contain deck ID, timestamp, and card list

---

## File Locations & “Latest File” Rule

MTGO files can exist in **multiple places** (install locations, user profile data folders, cloud-sync folders, etc.).

**Invariant:** the app must always prefer the **latest/active** files.

* Logs: ingest from the **current active log** (usually newest log file by mtime/name), and handle rotation safely.
* Decks: consider all candidate deck XML files, but use **timestamps** to create new versions and avoid regressions.

> Any code that reads files should be written assuming there may be multiple candidates and that older copies can exist.

---

## Storage

* Local database (SQLite)
* Laravel/Eloquent models

Core tables (simplified):

* `matches`
* `games`
* `game_player`
* `players`
* `decks`
* `deck_versions`
* `archetypes`

---

## Key Workflows

### A) Log ingestion

* A scheduled task continuously processes the latest log file.
* Uses a **LogCursor** (byte offset) to read only new bytes.
* Parses lines/events, classifies them, and persists normalized records.

### B) Deck syncing

* Periodically scans deck XML files.
* Filters only true deck exports (`GroupingType = Deck`).
* Uses `NetDeckId` as the deck identifier.
* Uses `Timestamp` to decide whether to create a new deck version.

---

## Key Classes (by responsibility)

### Logs

* `GetGameLog` — fetches/cleans raw log content for a match token
* `LogCursor` — tracks read position in the active log
* `IngestLog` — reads new bytes and yields events
* `ClassifyLogEvent` — maps log lines/state into structured events

### Decks

* `GetDeckFiles` — discovers candidate deck XML files
* `SyncDecks` — upserts decks + creates deck versions

---

## Known Edge Cases

* Opponent disconnect vs concede
* League vs non-league metadata differences
* Some states fire during sideboarding (must not be misinterpreted)
* Log rotation can cause missed/duplicated events if cursor logic is wrong

---

## How to Report Bugs (Preferred)

Use short, architecture-aware statements:

* “`SyncDecks` isn’t removing decks when XML files disappear”
* “`LogCursor` skips events after log rotation”
* “Opponent quit in Game 1 is misclassified as concede”

Include:

* Which pipeline (logs or decks)
* Example file(s) or snippet(s)
* Expected vs actual behaviour

---

# END
