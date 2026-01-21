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
