# Import Performance Redesign

## Problem

The current import pipeline does all work synchronously in a single HTTP request: parsing history, scanning the filesystem for game log files, parsing binary game logs, extracting cards, suggesting decks, and returning the full enriched payload. This doesn't scale — a player with years of MTGO history (potentially 50k+ matches and thousands of game log files) would hit timeouts, OOM errors, and frontend rendering collapse.

## Design Overview

Redesign the import flow around three principles:

1. **Pre-decode game logs** — populate `decoded_entries` at discovery time so the scan never touches the filesystem
2. **Background job** — move the heavy matching work off the HTTP request into a queued job with progress tracking
3. **Deck-first UX** — the user selects a deck upfront; the system finds matches for that deck, eliminating per-match deck suggestion

## User Flow

1. User navigates to Import page
2. Selects a deck from a dropdown, clicks "Load Matches"
3. Sees a progress bar ("Processing 1,200 / 5,000...")
4. When complete, sees a paginated table of candidate matches with confidence scores
5. Reviews page-by-page, selecting matches to import
6. Clicks "Import Selected" — or "Import All" with a confirmation warning dialog

If the user navigates away and returns, the previous scan results are still available to resume.

## Data Model

### New Tables

**`import_scans`**

| Column | Type | Notes |
|--------|------|-------|
| id | integer | PK |
| deck_version_id | integer | FK to deck_versions |
| status | varchar | `processing`, `complete`, `failed`, `cancelled` |
| progress | integer | Current count processed |
| total | integer | Total to process |
| error | text | Nullable, failure message |
| created_at | datetime | |
| updated_at | datetime | |

Only one active scan at a time. Starting a new scan marks any `processing` scan as `cancelled` (the job checks status before each batch and stops if cancelled), then deletes previous scans and their matches.

**`import_scan_matches`**

| Column | Type | Notes |
|--------|------|-------|
| id | integer | PK |
| import_scan_id | integer | FK to import_scans |
| history_id | integer | MTGO match ID from history file |
| started_at | datetime | Match start time |
| opponent | varchar | Opponent username |
| format | varchar | Raw format code (e.g. "100") for DB storage |
| format_display | varchar | Human-readable format (e.g. "Modern") for frontend |
| games_won | integer | |
| games_lost | integer | |
| outcome | varchar | win/loss/draw |
| game_log_token | varchar | Nullable, matched game log token |
| confidence | float | Deck match score 0.0-1.0, nullable if no game log |
| round | integer | League round (0 for non-league) |
| description | varchar | Match description from history |
| game_ids | json | Nullable, array of MTGO game IDs from history |
| local_player | varchar | Nullable, detected local player username |
| created_at | datetime | |
| updated_at | datetime | |

### Existing Table Changes

**`game_logs`** — no schema changes, but `decoded_entries` must always be populated going forward.

## API Endpoints

### `POST /import/scan`

Start a new scan.

- **Body**: `{ deck_version_id: number }`
- **Behavior**: If a `processing` scan exists, marks it as `cancelled`. Deletes all previous scans + their matches. Creates `import_scans` row. Dispatches `ProcessImportScan` job. Returns immediately.
- **Response**: `{ scan_id: number }`

### `GET /import/scan/{id}`

Poll scan status.

- **Response**: `{ status, progress, total, error }`
- When `complete`, also includes first page of matches + pagination meta.

### `GET /import/scan/{id}/matches`

Paginated match results.

- **Query params**: `page`, `per_page` (default 50)
- **Sort**: `started_at` desc
- **Response**: Paginated `import_scan_matches` rows with confidence. Uses `format_display` for the frontend `format` field.

### `POST /import/scan/{id}/import`

Import selected matches.

- **Body**: `{ history_ids: number[] }`
- Creates `MtgoMatch`, `Game`, `GameLog`, player records. Deck version comes from the scan.
- **Response**: `{ imported: number, skipped: number }`

### `POST /import/scan/{id}/import-all`

Import all matches from the scan.

- No body needed.
- Shows confirmation dialog on frontend before sending.
- Same creation logic as selective import, batched.
- **Response**: `{ imported: number, skipped: number }`

### `DELETE /import/scan/{id}`

Cancel a scan.

- Sets `import_scans.status` to `cancelled`. The running job checks status before each batch and stops early if cancelled.
- Deletes the scan row and its `import_scan_matches` after the job acknowledges cancellation (or immediately if the job has already completed/failed).
- **Response**: `204 No Content`

## Background Job: `ProcessImportScan`

Dispatched by the scan endpoint. Steps:

1. **Backfill check**: If `GameLog::whereNull('decoded_entries')->exists()`, dispatch `BackfillGameLogEntries` synchronously first (runs inline within this job). This is a one-time cost.
2. **Parse history**: `ParseGameHistory::parse()` — single binary file, returns all history records.
3. **Filter existing**: Exclude matches already in DB by `mtgo_id`.
4. **Update total**: Write count to `import_scans.total`.
5. **Build game log index**: Load all `GameLog` records that have no associated match (`whereDoesntHave('match')`). For each, extract players and first timestamp from `decoded_entries`. This is a pure DB read.
6. **Match + score in batches** (500 records per batch):
   - For each history record in the batch, find matching game log by timestamp (+-5 min) and opponent name.
   - For matched game logs, extract cards via `ExtractCardsFromGameLog`, then look up `oracle_id` for each `mtgo_id` via the `cards` table. Compare resulting oracle IDs against the selected deck version's oracle IDs. Compute overlap ratio as confidence score.
   - Detect `local_player` from game log entries (the player who isn't the opponent).
   - Bulk insert batch results into `import_scan_matches` (including `game_ids`, `local_player`, both `format` and `format_display`).
   - Update `import_scans.progress`.
   - **Cancellation check**: Before each batch, refresh `import_scans` from DB. If status is `cancelled`, stop early and return.
7. **Complete**: Set `import_scans.status = 'complete'`.

**Card population**: Before scoring, the job must ensure cards referenced by game logs have `oracle_id` populated. Use `PopulateCardsInChunks` (extracted as a shared action from the current `ParseImportableMatches::populateCardsInChunks()`). Run once after building the game log index, before the match/score loop.

Error handling: catch exceptions, write message to `import_scans.error`, set status to `failed`.

### Confidence scoring detail

Game logs yield `mtgo_id` values (printing-specific). Deck versions store `oracle_id` values (card-identity). To bridge:

1. Extract `mtgo_id` list from game log via `ExtractCardsFromGameLog`
2. Look up corresponding `oracle_id` values via `Card::whereIn('mtgo_id', $ids)->pluck('oracle_id')`
3. Compare against deck version's oracle ID list
4. Confidence = count of overlapping oracle IDs / count of oracle IDs from game log

Cards without `oracle_id` in the DB are excluded from the comparison (they don't hurt confidence, they're just not counted).

## Game Log Backfill

### At discovery time (going forward)

Modify `DiscoverGameLogs::run()`: after creating a `GameLog` record, immediately parse the binary file and populate `decoded_entries`, `decoded_at`, `byte_offset`, `decoded_version`. All future game logs arrive pre-decoded.

### Historical game log discovery

`DiscoverGameLogs::run()` only discovers logs for matches with active pipeline states (`Started`, `InProgress`, `Ended`). Historical game log files on disk that don't correspond to active matches need a separate discovery path.

Add a `DiscoverGameLogs::discoverAll(?string $directory)` method that scans for all `Match_GameLog_*.dat` files (using Symfony Finder) and creates `GameLog` records for any tokens not already in the table, regardless of match state. This is called by `ProcessImportScan` before the backfill check, ensuring all on-disk game logs are in the DB.

### One-time backfill job: `BackfillGameLogEntries`

For existing records without `decoded_entries`:

- Queries `GameLog::whereNull('decoded_entries')`
- For each: reads `file_path`, runs `ParseGameLogBinary::run()`, writes decoded data
- Skips missing/corrupt files with a logged warning
- Called synchronously at the start of `ProcessImportScan` if undecoded records exist
- Progress is reflected in the scan's progress bar (the "Processing X / Y" message covers both backfill and matching phases)

## Import Logic Changes

### `ImportMatches` adaptation

The import action no longer receives the full enriched payload from the frontend. Instead:

- Reads match metadata from `import_scan_matches` (including `game_ids`, `local_player`)
- Gets `deck_version_id` from the parent `import_scans` record
- For matches with a `game_log_token`, reads `game_logs.decoded_entries` to build games, extract per-game results, and compute card stats
- Uses `format` (raw) from `import_scan_matches` when creating the `MtgoMatch` record
- Creates `MtgoMatch`, `Game`, `GameLog` link, `GamePlayer` pivots, card game stats
- Dispatches `DetermineMatchArchetypesJob` per match (existing behavior)

### Classes removed

- **`SuggestDeckForMatch`** — user picks the deck upfront
- **`ParseImportableMatches`** — replaced by `ProcessImportScan` job

### New shared action: `PopulateCardsInChunks`

Extracted from `ParseImportableMatches::populateCardsInChunks()` into its own action class. Used by both `ProcessImportScan` (during scanning) and `ImportMatches::ensureCardsPopulated()` (during import).

### Classes simplified

- **`MatchGameLogToHistory`** — works purely from `game_logs.decoded_entries`. No file parsing fallback.

## Frontend Design

Single page with three states:

### State 1: Setup

- Deck dropdown (active decks, deleted decks in separate optgroups)
- "Load Matches" button
- If a previous complete scan exists **for the currently selected deck** (`deck_version_id` matches), offer to resume with a "View previous results" link. If the selected deck differs from the existing scan, start fresh.
- Existing imported count + "Delete all imported" button (unchanged)
- Warning banner about reduced data fidelity (unchanged)

### State 2: Processing

- Progress bar: "Processing 1,200 / 5,000 game logs..."
- Polls `GET /import/scan/{id}` every 2 seconds
- Cancel button sends `DELETE /import/scan/{id}`

### State 3: Results

- Summary: "Found 342 matches for [Deck Name]. 280 high confidence, 62 low confidence."
- Format filter dropdown (uses `format_display`)
- Paginated table (50 per page):
  - Checkbox
  - Date
  - Opponent
  - Format (display name)
  - Result (W/L badges or score)
  - Confidence %
- Per-page "Select All" checkbox
- Sticky bottom bar:
  - Selected count
  - "Import Selected" button
  - "Import All X Matches" button → confirmation dialog: "Deck matching is approximate — cards are matched from game log mentions, not exact deck tracking. Are you sure you want to import all X matches without reviewing?"

### Removed from frontend

- Card display (local_cards, opponent_cards, card modals)
- Jaccard similarity grouping
- Per-match deck dropdowns
- Bulk deck assignment
- Group-based selection

## Match Row Payload

Lightweight — no card data sent to frontend:

```typescript
interface ImportScanMatch {
    id: number;
    history_id: number;
    started_at: string;
    opponent: string;
    format: string; // display-friendly name
    games_won: number;
    games_lost: number;
    outcome: string;
    confidence: number | null;
    game_log_token: string | null;
    round: number;
    description: string;
}
```

## Cleanup

Old scan data is deleted when a new scan starts. There is no long-term accumulation. If needed, a scheduled cleanup could delete scans older than 7 days, but this is not required for v1.
