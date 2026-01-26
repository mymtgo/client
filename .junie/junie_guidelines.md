# Junie Guidelines for MTGO Deck Tracker

## Project Overview

This is a **local-first** Laravel 12 + Inertia v2 + Vue 3 desktop application for tracking MTGO (Magic: The Gathering Online) matches and decks. It ingests local MTGO files (logs + deck XML) — there is **no API**.

**Critical reading before any work:**
1. `/docs/system.md` — architecture overview
2. `/docs/pipelines.md` — data flow (log ingestion, deck sync)
3. `/docs/database.md` — schema relationships
4. `/docs/known_mtgo_lies_and_traps.md` — MTGO quirks and defensive coding requirements

---

## Core Architecture Principles

### Data Flow
- **LogEvents are the source of truth** — Matches/Games are derived by projecting events, not inferred from DB tables
- **Append-only, idempotent** — pipelines must handle duplicates, reprocessing, partial state
- **Cursor-based ingestion** — `LogCursor` tracks byte offset in active log file
- **Timestamp-based versioning** — deck versions use XML timestamps, never regress

### MTGO Assumptions (Always True)
- Logs are hostile input — malformed, partial, duplicated
- Match/game boundaries are fuzzy — no clean start/end signals guaranteed
- Silence ≠ completion — absence of events means nothing
- Game results may be unknown/inferred — not all games have winners

---

## Directory Structure

```
app/
├── Actions/           # Business logic (single-responsibility classes)
│   ├── Cards/
│   ├── Decks/         # SyncDecks, GetDeckFiles, etc.
│   ├── Logs/          # IngestLog, ClassifyLogEvent, LogCursor logic
│   ├── Matches/
│   └── Util/
├── Console/           # Artisan commands
├── Data/              # DTOs (GameData, MatchData, etc.)
├── Enums/
├── Facades/           # Mtgo facade (getUsername, etc.)
├── Http/Controllers/  # Single-action controllers by domain
│   ├── Decks/
│   ├── Games/
│   └── Matches/
├── Jobs/              # Queued jobs
├── Managers/
├── Models/            # Eloquent models
└── Providers/

resources/js/
├── Pages/             # Inertia pages (decks/, games/, matches/)
│   └── {domain}/
│       ├── Show.vue
│       └── partials/  # Page-specific components
├── components/        # Shared Vue components
│   └── ui/            # UI primitives (shadcn-vue style)
├── actions/           # Wayfinder-generated route functions
├── routes/            # Wayfinder-generated named routes
├── types/
└── lib/

tests/
├── Feature/
└── Unit/
```

---

## Code Patterns

### Backend (PHP)

**Actions pattern:**
- Business logic lives in `app/Actions/` as invokable classes
- Controllers are thin, single-action, organized by domain

**Models:**
- `MtgoMatch` (not `Match` — reserved word)
- `Game`, `GamePlayer`, `Player`
- `Deck`, `DeckVersion`
- `LogCursor`, `LogEvent`
- Soft deletes used for decks

**DTOs:**
- Located in `app/Data/`
- Used for structured data transfer (GameData, MatchData, etc.)

**Facade:**
- `Mtgo::getUsername()` — authoritative local player identity

### Frontend (Vue 3 + Inertia v2)

**Page structure:**
- Pages in `resources/js/Pages/{domain}/`
- Partials in `partials/` subdirectory
- Use `<Form>` component for forms (Inertia v2)

**Components:**
- Shared components in `resources/js/components/`
- UI primitives in `components/ui/` (shadcn-vue pattern)

**Routing:**
- Wayfinder generates TypeScript route functions
- Import from `@/actions/` or `@/routes/`

---

## Key Classes Reference

| Class | Purpose |
|-------|---------|
| `IngestLog` | Reads new bytes from log, yields events |
| `ClassifyLogEvent` | Maps raw log lines to typed events |
| `LogCursor` | Tracks read position, handles rotation |
| `SyncDecks` | Upserts decks + versions from XML |
| `GetDeckFiles` | Discovers candidate deck XML files |
| `CreateMatch` | Creates match from events |
| `CreateMatchGames` | Builds games within a match |
| `StoreMatchResults` | Persists match outcomes |

---

## Testing

- Use Pest v4 for all tests
- Feature tests for pipelines and controllers
- Factories exist for models — use them
- Test idempotency — same input twice = same result
- Test partial/malformed input scenarios

---

## Common Pitfalls to Avoid

1. **Don't assume clean match boundaries** — matches can start/end without signals
2. **Don't trust log ordering** — timestamps are unreliable
3. **Don't assume winner is known** — games can end without result
4. **Don't use `DB::` directly** — prefer `Model::query()`
5. **Don't hardcode file paths** — multiple candidate locations exist
6. **Don't skip cursor rotation handling** — files can shrink/rotate

---

## When Modifying Pipelines

Before changing log ingestion or deck sync:
1. Understand the full pipeline flow in `/docs/pipelines.md`
2. Check `app/Actions/Logs/` and `app/Actions/Decks/`
3. Ensure changes are idempotent
4. Test with partial/malformed input
5. Verify cursor handling for edge cases
