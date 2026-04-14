# Match History Backfill — Import Wizard

## Overview

A three-step import wizard that lets users backfill match history from MTGO's local `mtgo_game_history` binary file and `Match_GameLog_*.dat` files. This is an **isolated feature** — no existing pipeline actions are modified.

## Data Sources

### `mtgo_game_history` (binary, .NET BinaryFormatter)
Per-record fields: `Id`, `StartTime`, `Opponents`, `GameWins`, `GameLosses`, `MatchWinners`, `MatchLosers`, `GameIds`, `GameWinsToWinMatch`, `Description`, `Round`, `Format`.

Does NOT contain: deck name, deck ID, or card list.

### `Match_GameLog_*.dat` (binary, per-match)
Contains timestamped game entries with full card names and MTGO CatalogIDs embedded in the format `@[Card Name@:catalogId,instanceId:@]`. Includes player names, play/draw decisions, starting hand sizes, win/loss/concede events.

### Linking the two
Game history records link to game log files by matching `StartTime ± 5 minutes` AND `Opponent username`. This works for ~95% of records. The remaining ~5% are importable with match-level stats only (no per-game detail).

---

## Schema Change

Add `imported` boolean column to `matches` table (default `false`). This flags backfilled matches so the UI can gracefully handle missing data (no timelines, no opening hands, no sideboard tracking).

Migration: `add_imported_to_matches_table`

---

## Navigation — Import Wizard Entry Point

The import wizard is accessed via a dedicated button in the `AppHeader` top-right area, next to the settings button. Both buttons are styled consistently as bordered buttons with icon + text label.

**Current layout** (in `AppHeader.vue`, right-side `div`):
- Account switcher / display
- Settings cog (icon-only, no border)

**New layout**:
- Account switcher / display
- **"Import" button** — bordered button with `Import` icon (lucide `FileUp` or similar) + "Import" text label. Links to the import wizard page.
- **"Settings" button** — bordered button with `Settings` icon + "Settings" text label. Same link as current cog.

Both buttons use the same style: `inline-flex items-center gap-1.5 rounded-md border border-sidebar-border px-2.5 py-1 text-sm text-sidebar-foreground/70 transition-colors hover:text-sidebar-foreground` (consistent bordered look).

---

## Step 1 — Initiate (Backend Processing)

Triggered by the user navigating to the Import Wizard page and clicking a "Scan Match History" button. Runs synchronously — the action uses the standard MTGO file paths in production (via `Mtgo::getLogDataPath()`) and will work against the `storage/app/` directory during development/testing.

### Process

1. **Parse `mtgo_game_history`** via existing `ParseGameHistory::parse()`.
2. **Filter out matches already in DB** by comparing `mtgo_game_history.Id` against `matches.mtgo_id`.
3. **For each importable record, find the matching game log file:**
   - Parse all `Match_GameLog_*.dat` files in the MTGO data directory.
   - Match by `StartTime ± 5 minutes` AND opponent username appearing in the game log's player list.
   - Cache the parsed results (this is expensive — 677 files).
4. **Extract card data from matched game logs:**
   - For the local player: extract all `@[CardName@:catalogId,instanceId:@]` references. Track unique CatalogIDs (mtgo_ids) and associate card names.
   - For the opponent: same extraction, filtered to opponent player prefix.
   - Use `ExtractGameResults::run()` to get per-game results, on-play, starting hand sizes.
5. **Populate missing cards:**
   - Collect all unique mtgo_ids from all matched game logs.
   - Run `CreateMissingCards::run()` to create stubs for any not in the cards table.
   - Dispatch `PopulateMissingCardData` to enrich stubs via the API (resolves oracle_id, name, image, etc.).
   - For cards that can't be resolved by mtgo_id, send card names to `POST /api/cards` with `tokens` array as fallback.
6. **Attempt deck matching:**
   - For each match, collect the local player's unique CatalogIDs from the game log.
   - Resolve CatalogIDs to oracle_ids via the Card model.
   - Compare the oracle_id set against all DeckVersion signatures (active + soft-deleted decks).
   - Scoring: count how many of the game's oracle_ids appear in each deck version's card list. Rank by overlap percentage.
   - If the top-scoring deck version has >= 60% of the game's cards present in its list, assign it as the suggested deck. Otherwise mark as "no match".
   - Include soft-deleted decks in matching, flagged as "(deleted)" in results.

### Output

Return a structured array of importable matches:

```php
[
    [
        'history_id' => 278248231,        // mtgo_game_history Id
        'started_at' => '2025-05-06T20:04:56Z',
        'opponent' => 'seafox311',
        'format' => 'Modern',
        'games_won' => 0,
        'games_lost' => 2,
        'outcome' => 'loss',
        'round' => 0,
        'description' => '',              // match description from history
        'has_game_log' => true,
        'game_log_token' => '02a62508-...', // null if no log found
        'games' => [                      // null if no game log
            [
                'game_index' => 0,
                'won' => false,
                'on_play' => true,
                'starting_hand_size' => 7,
                'started_at' => '...',
                'ended_at' => '...',
            ],
            // ...
        ],
        'local_cards' => [                // CatalogIDs seen, null if no log
            ['mtgo_id' => 155958, 'name' => 'Karn, the Great Creator'],
            // ...
        ],
        'opponent_cards' => [...],
        'suggested_deck_version_id' => 12,  // null if no match
        'suggested_deck_name' => 'Tron',    // null if no match
        'deck_match_confidence' => 0.78,    // 0-1 score
        'deck_deleted' => false,            // true if deck is soft-deleted
    ],
    // ...
]
```

---

## Step 2 — Present Findings (Frontend Wizard Page)

### Layout

A full-page wizard view with:

1. **Warning banner** at the top explaining the limitations of imported data:
   - "Imported matches have reduced data fidelity. Opening hands, sideboard changes, game timelines, and turn estimates will not be available. Card game statistics will be approximate — cards are counted as 'seen' based on game log mentions, not zone tracking."
   - Checkbox: "I understand and accept these limitations" (must be checked to proceed).

2. **Summary stats**: "Found X matches not yet in your database. Y have game logs available. Z have suggested deck matches."

3. **Filterable/sortable table** with columns:
   - Checkbox (select for import)
   - Date (formatted `started_at`)
   - Opponent
   - Format
   - Result (Game 1 / Game 2 / Game 3 as W/L indicators, or just "2-0", "1-2" etc. if no game log)
   - Deck (dropdown selector — pre-populated with suggested deck, shows all deck versions grouped by deck name, soft-deleted decks marked with "(deleted)")
   - Confidence (visual indicator of deck match quality — green/yellow/red)

4. **Deck dropdown behavior:**
   - Pre-selects suggested deck version if confidence >= 60%.
   - Groups options: Active Decks → Deleted Decks → "No deck" option.
   - Each option shows: `Deck Name (version date)` or `Deck Name (deleted) (version date)`.
   - Matches without a selected deck are visually flagged — they ARE importable but the user should understand card_game_stats won't be computed for them.

5. **Bulk actions:**
   - "Select all with deck match" — selects all rows that have a suggested deck.
   - "Select all" — selects everything.
   - "Import selected" button with count.

### Validation

- Matches without a game log: importable (match-level stats only). Show a subtle indicator (e.g., "no game data" in the result column).
- Matches without a deck: importable. Card game stats will be skipped for these.

---

## Step 3 — Import (Backend Processing)

For each selected match, create records in the database.

### Match Record

```php
MtgoMatch::create([
    'token' => Str::uuid(),              // generated, not from MTGO
    'mtgo_id' => $history['Id'],
    'deck_version_id' => $selectedDeckVersionId, // from user's dropdown choice
    'format' => $history['Format'],       // normalized (CMODERN → Modern)
    'match_type' => 'league',             // or infer from Round/Description
    'games_won' => $history['GameWins'],
    'games_lost' => $history['GameLosses'],
    'started_at' => $history['StartTime'],
    'ended_at' => $lastGameEndedAt,       // from game log, or null
    'state' => MatchState::Complete,
    'outcome' => $outcome,                // Win/Loss/Draw from history
    'imported' => true,
]);
```

### Game Records (only if game log was matched)

For each game extracted from the game log:

```php
$game = Game::create([
    'match_id' => $match->id,
    'mtgo_id' => (string) $historyGameIds[$index], // from history GameIds array
    'won' => $gameResult['won'],
    'started_at' => $gameResult['started_at'],
    'ended_at' => $gameResult['ended_at'],
]);
```

### Player Records

```php
// Find or create players
$localPlayer = Player::firstOrCreate(['username' => $localUsername]);
$opponent = Player::firstOrCreate(['username' => $opponentUsername]);

// Attach via pivot — no deck_json, no instance_id (we don't have these)
$game->players()->attach($localPlayer->id, [
    'is_local' => true,
    'on_play' => $gameResult['on_play'] ?? false,
    'starting_hand_size' => $gameResult['starting_hand_size'] ?? 7,
    'instance_id' => 0,    // placeholder
    'deck_json' => null,    // not available from game logs
]);

$game->players()->attach($opponent->id, [
    'is_local' => false,
    'on_play' => !($gameResult['on_play'] ?? false),
    'starting_hand_size' => $gameResult['opponent_hand_size'] ?? 7,
    'instance_id' => 0,
    'deck_json' => null,
]);
```

### No Timeline Records

Game timelines are NOT created for imported matches. The game state JSON snapshots only exist during live play.

### Card Game Stats (Reduced Fidelity)

For matches with both a game log AND a selected deck version:

- **`oracle_id`**: from deck version signature (the full 75).
- **`quantity`**: from deck version (full fidelity).
- **`seen`**: count of unique card names from the game log text that match oracle_ids in the deck. This is approximate — a card mentioned 3 times in the log was "seen" once, and we can't distinguish between multiple copies.
- **`kept`**: 0 (no opening hand data available).
- **`won`**: from game result (full fidelity).
- **`is_postboard`**: game index > 0 (full fidelity).
- **`sided_out`**: false (no per-game deck JSON to compare).

Implemented as a new isolated action: `ComputeImportedCardGameStats`.

### Archetype Estimation

After import, for each match with extracted opponent cards:
- Collect opponent card names from game log.
- Call `POST /api/archetypes/estimate` with format + opponent cards.
- Create `MatchArchetype` records for the opponent.

For the local player, if a deck version was selected and has an associated archetype, link that.

---

## Isolated Code — No Pipeline Changes

All new code lives in its own namespace/directory. Existing pipeline actions are not modified.

### New Actions (in `app/Actions/Import/`)

- `ParseImportableMatches` — Step 1 orchestrator. Parses history + game logs, matches them, extracts cards, attempts deck matching.
- `MatchGameLogToHistory` — Links history records to game log files by time+opponent.
- `ExtractCardsFromGameLog` — Extracts card names and CatalogIDs per player from a parsed game log.
- `SuggestDeckForMatch` — Compares extracted oracle_ids against DeckVersion signatures.
- `ImportMatches` — Step 3 orchestrator. Creates match/game/player/stats records.
- `ComputeImportedCardGameStats` — Reduced-fidelity card_game_stats for imported matches.

### New Controller

- `ImportWizardController` (or split into step-specific controllers following existing patterns):
  - `initiate` — triggers Step 1, returns JSON results.
  - `import` — accepts selected matches + deck choices, triggers Step 3.

### New Vue Page

- `resources/js/pages/import/` — wizard page with the table, dropdowns, and import flow.

### Migration

- `add_imported_to_matches_table` — adds `imported` boolean column.

---

## UI Changes for Imported Matches

The `imported` flag allows the match detail page to gracefully degrade:

- **Match list**: no changes needed — all required fields are populated.
- **Match detail page (`BuildMatchGameData`)**: when `imported` is true:
  - Skip opening hand display (or show "Not available for imported matches").
  - Skip sideboard changes display.
  - Skip turn estimation.
  - Duration: show if game timestamps are available, otherwise hide.
  - Game logs: show decoded entries from the .dat file if available (we have these!).
  - Opponent cards: show cards extracted from game log text (names only, no zone tracking).

The simplest approach: check `match.imported` in the Vue component and conditionally render sections. The backend `BuildMatchGameData` already handles null timelines gracefully (returns null for turns, empty arrays for hands).

---

## Format Normalization

History records use MTGO internal format codes. Map to display names:

| History Format | Display Name |
|---------------|-------------|
| CMODERN | Modern |
| CPAUPER | Pauper |
| CPIONEER | Pioneer |
| CSTANDARD | Standard |
| CLEGACY | Legacy |
| CVINTAGE | Vintage |

---

## Edge Cases

1. **Match already in DB**: Filtered out in Step 1 by `mtgo_id` comparison.
2. **No game log found (~5%)**: Importable with match-level stats only. No games, no cards, no stats.
3. **Game log with 0 cards for local player**: Rare (instant concede). Import match/games but skip card stats.
4. **Deck version from deleted deck**: Valid for matching. Shown with "(deleted)" label.
5. **No deck selected**: Import allowed. Card game stats skipped.
6. **Duplicate import attempt**: Prevented by `mtgo_id` uniqueness check.
7. **Multiple deck versions with similar overlap**: Show top suggestion but user can override via dropdown.
8. **Cards not yet in DB**: Created as stubs during Step 1, enriched via API before Step 2 presentation.
