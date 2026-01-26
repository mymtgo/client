# Improvement Suggestions

This document outlines potential improvements to the MTGO Deck Tracker application, organized by priority and area.

---

## High Priority

### 1. Test Coverage

**Current State:** Minimal test coverage — only `ExampleTest.php` placeholder files exist in `tests/Feature/` and `tests/Unit/`.

**Recommendations:**
- Add feature tests for critical pipelines:
  - `IngestLog` — test cursor handling, rotation detection, idempotency
  - `SyncDecks` — test version creation, soft-delete/restore, timestamp comparison
  - `BuildMatch` — test match construction from events, edge cases (concede, disconnect)
- Add unit tests for:
  - `ClassifyLogEvent` — event type classification
  - `GenerateDeckSignature` — signature consistency
  - `ExtractJson` / `ExtractKeyValueBlock` — parsing edge cases
- Test idempotency explicitly: same input twice should produce identical state
- Test malformed/partial input scenarios per `/docs/known_mtgo_lies_and_traps.md`

---

### 2. Database Transaction Safety in `BuildMatch`

**Current State:** Transaction code is commented out (`DB::beginTransaction()` / `DB::commit()`).

**Recommendations:**
- Re-enable transactions to ensure atomic match creation
- If a failure occurs mid-build, the database could be left in an inconsistent state (match created, but games missing)
- Consider using `DB::transaction(fn() => ...)` closure pattern for automatic rollback

---

### 3. Error Handling & Logging

**Current State:** Silent failures in many places (e.g., `@fopen`, `@filesize` suppress errors).

**Recommendations:**
- Add structured logging for pipeline failures
- Log when cursor rotation is detected
- Log when deck files disappear (soft-delete trigger)
- Consider a `pipeline_errors` table or dedicated log channel for debugging
- Add monitoring/alerting for repeated failures

---

## Medium Priority

### 4. Deck Soft-Delete Gap

**Current State:** Documented in `/docs/pipelines.md` — when MTGO removes a deck file, the app soft-deletes the deck, but this behavior may not be fully reliable.

**Recommendations:**
- Verify `SyncDecks` correctly soft-deletes decks when files disappear
- Add a scheduled job to reconcile deck state periodically
- Consider a "last seen" timestamp on decks for staleness detection

---

### 5. Model Mass Assignment Protection

**Current State:** Models use `$guarded = []` (allow all attributes).

**Recommendations:**
- Switch to explicit `$fillable` arrays for better security
- Prevents accidental mass assignment of sensitive fields
- Especially important for models like `MtgoMatch`, `Game`, `Player`

---

### 6. Job Retry & Failure Strategies

**Current State:** Jobs exist (`IngestLogs`, `BuildMatch`, `SyncDecks`) but retry/failure handling is unclear.

**Recommendations:**
- Define explicit `$tries`, `$backoff`, `$maxExceptions` on jobs
- Implement `failed()` method for cleanup/notification
- Consider dead-letter handling for persistently failing matches
- Add job batching for related operations (e.g., match + games)

---

### 7. API Resource Layer

**Current State:** Controllers return data directly to Inertia without transformation layer.

**Recommendations:**
- Create Eloquent API Resources for consistent data shaping
- Useful if API endpoints are added later
- Centralizes transformation logic (e.g., date formatting, nested relationships)

---

## Lower Priority / Future Enhancements

### 8. Statistics & Analytics

**Recommendations:**
- Add win rate calculations by deck, archetype, format
- Matchup matrix (deck vs opponent archetype)
- Time-based trends (performance over time)
- Mulligan statistics per deck
- Consider pre-computed aggregates for performance

---

### 9. Data Export

**Recommendations:**
- Export match history to CSV/JSON
- Deck export in standard formats (MTGO .dek, Arena, Moxfield)
- Backup/restore functionality for the SQLite database

---

### 10. UI/UX Improvements

**Recommendations:**
- Add loading states for deferred props (Inertia v2 feature)
- Skeleton loaders for match/deck lists
- Dark mode support (if not already present)
- Keyboard shortcuts for navigation
- Search/filter for matches and decks

---

### 11. Configuration Externalization

**Current State:** Some paths and patterns may be hardcoded.

**Recommendations:**
- Move MTGO file paths to config/env
- Allow user to configure custom deck/log locations
- Support multiple MTGO installations

---

### 12. Performance Optimizations

**Recommendations:**
- Add database indexes review (some exist, verify coverage)
- Consider caching for expensive queries (deck stats, matchup spreads)
- Lazy load relationships in controllers where appropriate
- Chunk large event processing to reduce memory usage

---

### 13. Code Organization

**Recommendations:**
- Extract repeated event-finding logic in `BuildMatch` into helper methods
- Consider a `MatchBuilder` class to encapsulate match construction state machine
- Add interfaces for Actions to enable easier testing/mocking
- Document public Action APIs with PHPDoc

---

### 14. Observability

**Recommendations:**
- Add metrics collection (matches processed, events ingested, errors)
- Health check endpoint for pipeline status
- Dashboard for ingestion lag / queue depth
- Consider Laravel Telescope for local debugging

---

## Architecture Considerations

### Event Sourcing Formalization

The current architecture is event-sourcing-like (LogEvents → derived state). Consider:
- Formalizing event replay capability
- Adding event versioning for schema evolution
- Snapshot mechanism for faster rebuilds
- This would make debugging and "what happened" analysis much easier

### Separation of Ingestion and Projection

Currently `BuildMatch` both reads events and creates entities. Consider:
- Separate "projector" classes that subscribe to event types
- Allows adding new projections without modifying core pipeline
- Easier to test individual projections in isolation

---

## Summary

| Priority | Area | Effort | Impact |
|----------|------|--------|--------|
| High | Test Coverage | High | High |
| High | Transaction Safety | Low | High |
| High | Error Handling | Medium | High |
| Medium | Deck Soft-Delete | Low | Medium |
| Medium | Mass Assignment | Low | Medium |
| Medium | Job Strategies | Medium | Medium |
| Medium | API Resources | Medium | Medium |
| Lower | Statistics | High | High |
| Lower | Data Export | Medium | Medium |
| Lower | UI/UX | Medium | Medium |
| Lower | Config | Low | Low |
| Lower | Performance | Medium | Medium |
| Lower | Code Organization | Medium | Medium |
| Lower | Observability | Medium | Medium |

---

# END
