# Settings

## Purpose

Configuration page for the application. Covers identity, file locations, ingestion controls, display preferences, and data/privacy options.

---

## Layout

Grouped sections on a single page. Each section has a heading and a short description of what it controls.

---

## Sections

### Identity

- **MTGO Username** — The player name to treat as "you" when parsing log files. Used to determine which player in a match is the local player.
  - Text input, saved on change or on submit
  - Validation: must not be empty

---

### File Paths

- **Log file directory** — Where to look for MTGO match log files (`Match_GameLog_*.dat`). MTGO may store these in different locations depending on installation type.
  - Directory picker input
  - Show current resolved path
  - Warn if path doesn't exist or no log files are found there

- **Deck XML directory** — Where to look for MTGO deck XML files used for deck sync.
  - Directory picker input
  - Show current resolved path
  - Warn if path doesn't exist

Multiple candidate paths may exist — consider allowing a list of paths to search, or at minimum showing what was auto-detected vs. manually set.

---

### Watcher & Ingestion

- **File watcher status** — Show whether the file system watcher is currently active (running / stopped).
  - Toggle to start/stop the watcher

- **Manual triggers**
  - "Ingest logs now" — Manually trigger log ingestion (runs IngestLogs job)
  - "Sync decks now" — Manually trigger deck XML sync (runs SyncDecks job)
  - Show last run time for each

- **Ingestion log / activity** — A brief recent activity readout (last N events processed, any errors) to give confidence the watcher is working.

---

### Display Preferences

- **Theme** — Light / Dark / System (if dark mode is supported across the app)
- **Date format** — How dates are displayed throughout the app (e.g. relative "3 days ago" vs. absolute "12 Feb 2026")
- **Default stats period** — The default time window used for win rate calculations on the dashboard and deck views (e.g. All time / Last 90 days / Last 30 days)

---

### Data & Privacy

- **Usage tracking** — Opt in or out of sending anonymous usage statistics. Clear explanation of what is and isn't collected. Off by default.

---

## Navigation

Accessible via the Settings link in the sidebar nav (gear icon at the bottom, or top-level nav item).

---

## Data Considerations

- Settings are stored locally (SQLite or a config file) — no remote sync
- File path changes should trigger a re-scan or at least prompt the user to do so
- Watcher state changes take effect immediately without a page reload
