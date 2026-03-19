# GameLog Binary Parser & Result Extraction Overhaul — Design Spec

## Problem

The MTGO GameLog `.dat` files use a binary format with .NET DateTime ticks, length-prefixed strings, and varint message lengths. The current parser (`GetGameLog`) strips all non-printable bytes with regex, losing timestamps, message boundaries, and structural data. Game results are then extracted from the flattened text using regex pattern matching and file offset ordering — fragile and lossy.

## Goal

Replace the regex-based parsing with a proper binary parser that:
1. Decodes `.dat` files into structured, timestamped entries
2. Parses incrementally (game-by-game as the match progresses, not just at completion)
3. Stores decoded entries as JSON in the database (parse once, use forever)
4. Uses clean structured data for watertight game/match result detection
5. Enables the overlay to show live results from the database
6. Enables future features like game log replay viewing

## Binary Format (Decoded)

```
Header:
  1 byte   - version (observed: 0x01)
  1 byte   - unknown/flags (observed: 0x00)
  1 byte   - match UUID string length (0x24 = 36)
  36 bytes  - match UUID (ASCII)
  1 byte   - type (observed: 0x04)
  1 byte   - unknown/flags (observed: 0x00)
  1 byte   - game UUID string length (0x24 = 36)
  36 bytes  - game UUID (ASCII)

Entry (repeating until EOF):
  8 bytes   - timestamp (.NET DateTime ticks, int64 little-endian)
              100-nanosecond intervals since Jan 1, 0001
              Convert: (ticks - 621355968000000000) / 10_000_000 = Unix seconds
  1 byte    - flag (observed: always 0x00)
  1+ bytes  - message length (.NET Write7BitEncodedInt varint: high bit = continuation flag,
              each byte contributes 7 bits. 1 byte for 0-127, 2 bytes for 128-16383, etc.)
  N bytes   - message text (UTF-8)
```

**Observed file sizes:** 2KB (short game) to 54KB (600 entries, 2-game match). Estimated worst case: ~1500 entries, ~200KB as JSON.

**Observed quirks:**
- Match UUID and game UUID in the header may be identical. A single `.dat` file can contain multiple games despite the header suggesting a single game UUID.
- Messages retain the `@P` prefix before player names (e.g. `@Panticloser wins the game.`). Join events have a double prefix: `@P@Panticloser joined the game.` (the `@P@P` pattern from the current regex approach is actually `@P` + `@P{playername}` in the original binary).
- Starting hand sizes use word form: "seven cards in hand", "six cards in hand" etc.

## Architecture

### Component 1: `ParseGameLogBinary` (Action)

Pure function. Binary bytes in → structured data out.

**Input:** `string $raw` (raw file contents)

**Output:**
```php
[
    'match_uuid' => string,
    'game_uuid' => string,
    'version' => int,
    'type' => int,
    'entries' => [
        ['timestamp' => Carbon, 'message' => string],
        ...
    ]
]
```

**Incremental parsing:** Accepts an optional `int $byteOffset` parameter. When provided, skips the header and starts reading entries from the given byte position. Returns entries parsed from that offset onward, plus the new byte offset (end of last successfully parsed entry). This allows the caller to read only new entries appended since the last parse.

**Error handling:** Returns null if file is too short, header is malformed, or entry parsing fails mid-stream (truncated file). Logs a warning with match context. When parsing incrementally, a truncated final entry is silently skipped (the file may still be actively written to).

### Component 2: `ExtractGameResults` (Action)

Structured entries in → per-game results out.

**Input:** Array of `{timestamp, message}` entries, `string $localPlayer`

**Output:**
```php
[
    'games' => [
        [
            'game_index' => 0,
            'winner' => string,
            'loser' => string,
            'end_reason' => 'win' | 'concede' | 'disconnect' | 'unknown',
            'on_play' => string|null,       // player who chose to play first
            'starting_hands' => [
                'PlayerA' => 7,
                'PlayerB' => 6,
            ],
            'started_at' => Carbon,
            'ended_at' => Carbon,
        ],
        ...
    ],
    'players' => [string, string],
    'match_score' => [int, int]|null,   // from "wins/leads the match X-Y" line
    'results' => [bool, ...],            // per-game: true = local player won
    'on_play' => [bool, ...],            // per-game: true = local player on play
    'starting_hands' => [                // per-game per-player (existing format)
        ['player' => string, 'starting_hand' => int],
        ...
    ],
]
```

**Game boundary detection:**

The observed sequence at each game boundary is: roll events → join events (`@P@P{player} joined the game.`) → "chooses to play first/second" → "begins the game with N cards in hand". Each game has exactly 2 join events (one per player).

- Primary signal: clusters of `"joined the game"` messages (note the `@P@P` double prefix pattern)
- Secondary signal: timestamps — a gap between game-end and next game-start confirms the boundary
- Roll events may appear before joins — the boundary starts at the first roll/join after a game-end event
- An `'unknown'` end reason is used when a new game boundary is detected without an explicit end signal for the previous game (per MTGO quirks documented in `docs/known_mtgo_lies_and_traps.md`)

**Result extraction per game:**
1. Look for `"{player} wins the game"` → explicit winner
2. If no win line, look for `"{player} has conceded from the game"` or `"lost connection"` → infer winner as other player
3. Cross-check with `"leads the match X-Y"` / `"wins the match X-Y"` lines — if the running score disagrees with counted results, trust the match score line (MTGO computed it)

**On-play/draw extraction:**
- `"{player} chooses to play first"` / `"chooses to play second"` within each game boundary

**Starting hands:**
- `"{player} begins the game with {N} cards in hand"` within each game boundary
- N is word-form ("seven", "six", etc.) — requires word-to-number mapping (same as current `GetGameLog` logic)

**Return format compatibility:** The `results`, `on_play`, and `starting_hands` keys match the current `GetGameLog` return format, so downstream consumers (`DetermineMatchResult`, `SyncGameResults`, `CreateGames`) work without interface changes.

### Component 3: Storage — `decoded_entries` Column

**Migration:** Add to `game_logs` table:
- `decoded_entries` — JSON column (nullable). Stores the full array of `{timestamp, message}` entries.
- `decoded_at` — datetime (nullable). When the binary was last parsed/updated.
- `byte_offset` — integer, default 0. Tracks how far into the `.dat` file we've read (like `LogCursor` for log ingestion). Enables incremental parsing.
- `decoded_version` — integer, default 1. Tracks which parser version produced the stored entries. If the parser is improved later, entries with an older version can be selectively re-parsed.

**No migration-time backfill.** This is a Windows desktop app — migrations should be structural only, no file I/O. Historical matches are backfilled lazily: when `GetGameLog` is called for a match without `decoded_entries`, it reads the `.dat` file, parses it, and stores the result. This happens naturally as users view old matches or when `RepairCorruptMatches` runs.

### Component 3a: Incremental Parsing Flow

The `.dat` file is appended to during a live match. Instead of waiting for match completion to parse, we parse incrementally on each `AdvanceMatchState` cycle:

1. **On each cycle:** Read the `.dat` file from `byte_offset`, parse new entries with `ParseGameLogBinary`, append to `decoded_entries`, update `byte_offset`.
2. **When a game-end signal appears** ("wins the game", concede, disconnect) in the new entries: run `ExtractGameResults` on the full `decoded_entries` to extract that game's result. Update the `Game` record's `won` field immediately.
3. **The overlay** reads game results from the database — as games complete during a match, their results appear in real-time without re-parsing the binary file.
4. **At `tryAdvanceToComplete`:** The entries are already fully stored. Run `ExtractGameResults` one final time as a cross-check with the match score line. This is the same codepath, just with all entries already present.

This mirrors the existing `LogCursor` pattern — cursor-based, append-only, idempotent. If the same entries are re-read (e.g. app restart), the append is deduplicated by checking timestamps of the last stored entry.

### Component 4: Refactored `GetGameLog`

**New flow:**
```
GetGameLog::run(token)
  1. Find GameLog record by match_token
  2. Sync entries from .dat file (incremental parse from byte_offset)
     - Read file from byte_offset
     - Parse new entries with ParseGameLogBinary
     - Append to decoded_entries, update byte_offset + decoded_at
  3. Run ExtractGameResults on full decoded_entries
  4. Return results in existing format
```

When `decoded_entries` is already populated and the `.dat` file hasn't grown (byte_offset matches file size), step 2 is a no-op — just uses stored entries. This means completed matches never re-read the file.

The return type stays identical. All callers (`AdvanceMatchState`, `CreateGames`, `RepairCorruptMatches`) continue working unchanged.

### Component 5: Match Score Cross-Check

New behavior in `DetermineMatchResult` or `GetGameLog`: when `ExtractGameResults` provides a `match_score` (from "wins/leads the match X-Y" lines), compare it against the counted game results. If they disagree:
- Log a warning with both values
- Trust the match score line (MTGO's own tally)
- Adjust the returned results array to match

This is the "watertight" guarantee — even if our game boundary detection misses or double-counts a game, MTGO's own match score line serves as ground truth.

## Data Flow (Before vs After)

### Before:
```
.dat file → file_get_contents
  → preg_replace (strip non-printable)
  → str_replace (split on @P)
  → regex (extract wins, concedes, hands)
  → sort by file offset
  → build results array
```

### After:
```
.dat file → file_get_contents (from byte_offset)
  → ParseGameLogBinary (proper binary decoding, incremental)
  → Append to decoded_entries JSON in DB, update byte_offset
  → ExtractGameResults (walk structured entries)
     → split on game boundaries (join events + timestamps)
     → extract results per game (win/concede/disconnect)
     → update Game records immediately on game completion
     → cross-check with match score line at match completion
  → return results array (same format)
```

**Incremental during match:**
```
AdvanceMatchState cycle (InProgress)
  → GetGameLog::run(token)
     → read .dat from byte_offset, parse new entries, append
     → if new game-end detected → update Game.won immediately
     → overlay sees updated results via database
```

## What Changes

| Component | Change |
|-----------|--------|
| `ParseGameLogBinary` | **New** — binary parser |
| `ExtractGameResults` | **New** — structured result extraction |
| `game_logs` migration | **New** — add `decoded_entries` + `decoded_at` + `byte_offset` + `decoded_version` (schema only, no backfill) |
| `GetGameLog` | **Refactored** — uses binary parser + stored entries |
| `DetermineMatchResult` | **Minor** — add match score cross-check |
| `SyncGameResults` | **No change** — same interface |
| `CreateGames` | **No change** — same interface |
| `RepairCorruptMatches` | **No change** — calls GetGameLog |

## What Stays the Same

- `DetermineMatchResult` still handles match-level concede detection from state changes
- `SyncGameResults` still backfills `game.won` fields
- `CreateGames` still calls `GetGameLog` for per-game data
- The return format of `GetGameLog` is preserved
- All existing tests continue to pass

## Testing

- **ParseGameLogBinary:** Test with real `.dat` fixture files (copy a few from storage). Assert correct UUIDs, entry count, timestamps, message content. Test truncated files return null. Test incremental parsing from byte offset returns only new entries.
- **ExtractGameResults:** Test with fixture entry arrays covering: clean 2-0 win, 2-1 with concede, disconnect mid-game, missing win line, match score disagreement, `@P`/`@P@P` prefix handling, word-form starting hand sizes, unknown end reason when game boundary detected without explicit end signal.
- **GetGameLog integration:** Test that existing callers get the same results from binary-parsed data as they would from regex-parsed data (regression test against known matches).
- **Incremental parsing:** Test that parsing mid-match (partial file) correctly appends entries, and a subsequent parse picks up new entries without duplicating.
- **Lazy backfill:** Test that calling GetGameLog for a match without decoded_entries reads the .dat file, stores the result, and returns correct data.

## Edge Cases

- **Truncated .dat file** (app crash): `ParseGameLogBinary` returns null for the incomplete entry, stops at last valid entry. Next incremental parse picks up where it left off.
- **Missing .dat file** (deleted): Same as today — returns null, logged as warning. If `decoded_entries` was already populated, uses that.
- **Messages > 127 bytes**: Varint length decoding handles this (observed in real data with long triggered ability text)
- **No "wins the game" line**: Fallback to concede/disconnect inference (same as today, but cleaner)
- **Match score line disagrees with counted results**: Trust match score line, log warning
- **Multiple concedes before win line**: Game boundary detection prevents double-counting since each concede is within a specific game's entry range
- **App restart mid-match**: `byte_offset` persists in DB, incremental parse resumes from last position. Deduplication by checking last stored entry timestamp prevents double-appending.
- **File written to while reading**: Incremental parser only reads up to the last complete entry (varint length tells us exactly where the message ends). Incomplete trailing bytes are silently ignored until next cycle.
