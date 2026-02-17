# MTGO Deck Tracker — Claude Code Guidelines

## Related Projects

- **API**: `E:\mymtgo\api` — the companion API project. You may need to read or modify files there when fixing/creating code that spans both projects.

---

## Critical Context — Read First

This project uses **local MTGO files** (logs + XML). There is **NO API** for data ingestion — all data comes from local files. The separate API project at `E:\mymtgo\api` provides other functionality.

Before working on this project, read these docs in order:
1. `/docs/system.md` — architecture overview
2. `/docs/pipelines.md` — data flow (log ingestion, deck sync)
3. `/docs/database.md` — schema relationships
4. `/docs/known_mtgo_lies_and_traps.md` — MTGO quirks and defensive coding

**Core truth**: Matches and Games are BUILT by projecting LogEvents, not inferred from database tables. If a proposed solution assumes clean match starts, clean match ends, or deterministic MTGO behaviour, the solution is incorrect.

---

## Stack & Versions

- PHP 8.4, Laravel 12, NativePHP 2.0 (Electron)
- Inertia.js v2, Vue 3, Tailwind CSS v4, Vite 7, TypeScript
- Laravel Wayfinder v0, Pest v4, Pint v1
- Spatie Laravel Data (DTOs), Spatie TypeScript Transformer
- SQLite (local, file-based)

---

## Architecture

### Data Flow
MTGO log files → FileSystemWatcher → IngestLogs → ClassifyLogEvent → BuildMatch → SQLite

### Key Principles
- **LogEvents are source of truth** — matches/games derived by projecting events
- **Append-only, idempotent** — pipelines handle duplicates, reprocessing, partial state
- **Cursor-based ingestion** — `LogCursor` tracks byte offset in active log file
- **Timestamp-based versioning** — deck versions use XML timestamps, never regress
- Logs are hostile input — malformed, partial, duplicated
- Match/game boundaries are fuzzy — no clean start/end signals guaranteed

### Directory Structure
- `app/Actions/` — Business logic (single-responsibility invokable classes)
- `app/Data/` — DTOs (Spatie Laravel Data)
- `app/Models/` — Eloquent models (`MtgoMatch` not `Match`)
- `app/Jobs/` — Queued: IngestLogs, SyncDecks, BuildMatch, ProcessLogEvents
- `app/Managers/MtgoManager.php` — High-level orchestration
- `app/Facades/` — `Mtgo::getUsername()` for local player identity
- `app/Http/Controllers/` — Single-action controllers by domain
- `resources/js/Pages/{domain}/` — Inertia pages with `partials/` subdirs
- `resources/js/components/ui/` — UI primitives (shadcn-vue pattern)

---

## Coding Conventions

### PHP
- PHP 8 constructor property promotion
- Explicit return type declarations and type hints
- Curly braces for all control structures
- PHPDoc blocks over inline comments
- Enum keys in TitleCase
- Avoid `DB::` — prefer `Model::query()`
- Use `config()` not `env()` outside config files
- Run `vendor/bin/pint --dirty` before finalizing PHP changes

### Frontend (Vue/TypeScript)
- Use Wayfinder imports from `@/actions/` or `@/routes/` for routing
- Use Inertia `<Form>` component or `useForm` for forms
- Tailwind v4: CSS-first config with `@theme`, `@import "tailwindcss"`
- Use gap utilities for spacing (not margins)
- Dark mode support with `dark:` if existing pages support it
- Check existing components before creating new ones

### Laravel
- Use `php artisan make:*` commands with `--no-interaction`
- Form Request classes for validation (not inline)
- Eager loading to prevent N+1 queries
- Named routes with `route()` function
- Middleware configured in `bootstrap/app.php` (Laravel 12 style)

---

## Testing

- **Pest v4** for all tests — `php artisan make:test --pest {name}`
- Feature tests in `tests/Feature/`, unit in `tests/Unit/`
- Use model factories (check for custom states first)
- Test idempotency — same input twice = same result
- Test partial/malformed input scenarios
- Run minimal tests: `php artisan test --compact --filter=testName`
- Never remove tests without approval

---

## Common Pitfalls

1. Don't assume clean match boundaries
2. Don't trust log ordering — timestamps are unreliable
3. Don't assume winner is known — games can end without result
4. Don't hardcode file paths — multiple candidate locations exist
5. Don't skip cursor rotation handling — files can shrink/rotate
6. Don't create new base folders without approval
7. Don't change dependencies without approval
