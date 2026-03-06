# Multi-Account Context Switching

Status: **Approved design**

## Overview

The app discovers MTGO accounts automatically from log events. Each account's data (decks, matches, leagues, stats) is scoped via an `account_id` foreign key on decks. Users can switch between accounts via a dropdown in the header, and the app auto-switches when a new login is detected.

## Account Discovery

### Login Detection

Username is extracted from log events with category `Login` and context `MtGO Login Success`:

```
15:52:41 [INF] (Login|MtGO Login Success) Username: anticloser (3022021)
```

Parsed via regex: `Username: (\S+)`

**Bug fix:** Current `IngestLog` only learns the username once per log instance (`if (! $cursor->local_username)`). This must change to always update on login events, since accounts can switch mid-session without the log file rotating.

### Account Registration

- Accounts are stored in NativePHP Settings as a JSON object: `{'anticloser': true, 'db_anticloser': true}`
- All discovered accounts default to tracked (`true`) — otherwise switching accounts for a few days would silently lose data
- Users can manually toggle accounts to untracked (`false`) in Settings if desired
- When a new account is detected, push a desktop notification: "New account detected: {username}" — clicking navigates to Settings page

### Active Account vs Logged-In Account

These are two distinct concepts:

- **Logged-in account** — derived from `LogCursor.local_username`, set by login events. This is who MTGO says is playing. Used by all **pipeline actions** (SyncDecks, DetermineMatchDeck, BuildMatches gate).
- **Active account** — the `active` flag on the `accounts` table. This is the UI viewing context. Used by all **consumer queries** (dashboard, decks, leagues, opponents). Auto-updated on login, but can also be manually switched via header dropdown.

When a login event fires:
- `LogCursor.local_username` is updated (logged-in account)
- `Account.active` is updated (active/viewing account)
- Toast notification shown: "Switched to {username}"

When a user manually switches via the dropdown:
- Only `Account.active` is updated (viewing context changes)
- `LogCursor.local_username` stays as-is (MTGO is still logged in as whoever)
- Pipeline actions continue to use the actual logged-in account

## Data Scoping

### Deck-Level Scoping

Add `account_id` foreign key to `decks` table. `SyncDecks` tags each deck with the active account's ID when syncing XML files from the per-account hash directories.

Everything downstream cascades naturally:

- **Decks** — filtered by active account's `id`
- **Matches** — tied to decks via `deck_version_id`, naturally scoped
- **Leagues** — tied to matches, naturally scoped
- **Opponents** — queried through match joins, naturally scoped
- **Dashboard stats** — derived from matches, naturally scoped

### Consumer Query Changes

All existing queries that start from `Deck` or join through decks will pick up the scoping automatically if the `Deck` model uses a `scopeForActiveAccount` scope. Alternatively, `where('account_id', $accountId)` can be applied at the controller level.

Queries that go directly to `matches` (like the dashboard stats) need to join through to deck and filter by `account_id`.

## Context Switching

### Auto-Switch

When `IngestLog` detects a `MtGO Login Success` event:
1. Update `LogCursor.local_username`
2. Update `active_username` in Settings
3. Show toast: "Switched to {username}"
4. If username is new, register it in accounts settings (tracked by default) + notification

### Manual Switch

- Dropdown in the app header (near settings cog)
- Shows only tracked accounts
- Switching updates `active_username` in Settings
- Redirects to decks index page (safe landing — avoids stale match/league views)

## Settings UI

New section in Settings page: "Accounts"

- List of all discovered usernames
- Toggle per username: tracked / ignored
- Only tracked accounts appear in the header dropdown
- Toggling off doesn't delete data — just hides it and prevents future match building

## Match Building Gate

In `BuildMatches.run()`, before calling `AdvanceMatchState`:
- Check `LogCursor.local_username`
- If that username is not tracked in settings, skip match building
- LogEvents still get ingested (keeps cursor position correct)
- Deck sync still runs (tags decks with username for when/if tracking is enabled later)

## File System Context

MTGO stores per-account data in hash-named directories:
```
AppFiles/
  91F5DC46A0AFBF283E8FD4E9E184F175/   <- account 1
    grouping *.xml                      <- decks
    Match_GameLog_*.dat                 <- game logs
  4FF13DC2AE7EFEC2C79D6195D18388F5/   <- account 2
    grouping *.xml
  application_settings                  <- contains LastLoginName (previous login)
```

There is no direct mapping from hash directory to username in the filesystem. The mapping is inferred: when `SyncDecks` runs, it reads from the data path directories while the active username is known from the log.

## Database Changes

### Migration: add account_id to decks

```sql
ALTER TABLE decks ADD COLUMN account_id INTEGER NULL REFERENCES accounts(id);
```

Existing decks get backfilled with the account created from the current `LogCursor.local_username`.

## What Doesn't Change

- Log ingestion (IngestLog) — still reads same log file, just updates username more aggressively
- Game log ingestion (StoreGameLogs) — unchanged
- Match state pipeline (AdvanceMatchState) — unchanged, just gated by tracked status
- DetermineMatchDeck, DetermineMatchArchetypes — unchanged
- League detection — unchanged

## Testing Strategy

- Test username detection from `MtGO Login Success` events
- Test account auto-registration in settings
- Test match building gate for tracked vs untracked accounts
- Test deck scoping by account_id
- Test context switching updates active account
- Test consumer queries only return data for active account

## Dependencies

- State-based match pipeline (in progress on `feature/state-based-match-pipeline`)

## Enables (future work)

- Per-account deck management
- Cross-account stats comparison
- Account-specific league overlays
