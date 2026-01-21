# MTGO Deck Tracker

## What is this?

A desktop app (NativePHP/Electron-style) that:

* Parses MTGO log files
* Tracks matches, games, players
* Syncs deck XML files
* Stores structured data locally (SQLite)
* Shows stats, winrates, matchup data

---

## Key Features

* Automatic log ingestion
* Byte-offset cursor parsing
* Deck versioning
* Archetype detection
* Mulligan & sideboard tracking

---

## Documentation

| File                 | Purpose                    |
| -------------------- | -------------------------- |
| `/docs/system.md`    | Full architecture overview |
| `/docs/pipelines.md` | Log + deck ingestion       |
| `/docs/database.md`  | Schema + relationships     |

---

## Tech Stack

* Laravel
* NativePHP
* SQLite
* Inertia / Vue

---

## Running locally

```bash
composer install
php artisan migrate
php artisan native:serve
```

---

## Common Terms

| Term         | Meaning                    |
| ------------ | -------------------------- |
| MTGO         | Magic The Gathering Online |
| LogCursor    | Byte offset tracker        |
| Deck Version | Snapshot at time of play   |

---

## Reporting Bugs

Use short-hand referencing architecture:

> "SyncDecks not deleting removed deck files"

> "LogCursor skipped events after rotation"

---

# END
