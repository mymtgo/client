# GameLog Binary Parser & Result Extraction — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the regex-based GameLog parser with a proper binary decoder that parses .dat files incrementally, stores decoded entries as JSON, and extracts game results from clean structured data.

**Architecture:** Three new actions: `ParseGameLogBinary` (binary → structured entries), `ExtractGameResults` (entries → per-game results), and a refactored `GetGameLog` that orchestrates incremental parsing + storage. The incremental storage foundation enables a follow-up task to wire real-time Game.won updates during matches (for live overlay support).

**Note:** Real-time `Game.won` updates during a match (mid-`AdvanceMatchState` cycles) are deferred to a follow-up. This plan establishes the incremental parsing infrastructure; wiring it into the mid-match state transitions requires changes to `AdvanceMatchState`'s InProgress handling and is better as a separate, focused task. `DetermineMatchResult` is intentionally unchanged — it receives cleaner input but its logic (threshold detection, match-level concede handling) remains sound.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, SQLite

**Spec:** `docs/superpowers/specs/2026-03-18-gamelog-binary-parser-design.md`

---

## File Structure

| File | Action | Purpose |
|------|--------|---------|
| `tests/fixtures/gamelogs/*.dat` | Create (already done) | 7 real fixture files for testing |
| `app/Actions/Matches/ParseGameLogBinary.php` | Create | Binary .dat parser — pure function |
| `app/Actions/Matches/ExtractGameResults.php` | Create | Structured entries → per-game results |
| `app/Actions/Matches/GetGameLog.php` | Rewrite | Orchestrate: incremental parse → store → extract |
| `app/Models/GameLog.php` | Modify | Add casts, relationship |
| `database/migrations/..._add_decoded_columns_to_game_logs_table.php` | Create | Add decoded_entries, decoded_at, byte_offset, decoded_version |
| `tests/Feature/Actions/Matches/ParseGameLogBinaryTest.php` | Create | Binary parser tests |
| `tests/Feature/Actions/Matches/ExtractGameResultsTest.php` | Create | Result extraction tests |
| `tests/Feature/Actions/Matches/GetGameLogTest.php` | Create | Integration tests |

---

### Task 1: Migration — Add decoded columns to game_logs

**Files:**
- Create: `database/migrations/..._add_decoded_columns_to_game_logs_table.php`
- Modify: `app/Models/GameLog.php`

- [ ] **Step 1: Create migration**

Run: `php artisan make:migration add_decoded_columns_to_game_logs_table --table=game_logs --no-interaction`

- [ ] **Step 2: Write migration**

```php
public function up(): void
{
    Schema::table('game_logs', function (Blueprint $table) {
        $table->json('decoded_entries')->nullable()->after('file_path');
        $table->dateTime('decoded_at')->nullable()->after('decoded_entries');
        $table->unsignedInteger('byte_offset')->default(0)->after('decoded_at');
        $table->unsignedSmallInteger('decoded_version')->default(0)->after('byte_offset');
    });
}

public function down(): void
{
    Schema::table('game_logs', function (Blueprint $table) {
        $table->dropColumn(['decoded_entries', 'decoded_at', 'byte_offset', 'decoded_version']);
    });
}
```

Note: `decoded_version` defaults to 0 (meaning "not yet decoded"). The parser sets it to 1 when it stores entries.

- [ ] **Step 3: Update GameLog model**

Add casts to `app/Models/GameLog.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'decoded_entries' => 'array',
            'decoded_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 4: Run migration**

Run: `php artisan migrate`

- [ ] **Step 5: Commit**

```bash
git add database/migrations/*add_decoded_columns_to_game_logs_table.php app/Models/GameLog.php tests/fixtures/gamelogs/
git commit -m "feat: add decoded columns to game_logs table and test fixtures"
```

---

### Task 2: ParseGameLogBinary — Binary Parser

**Files:**
- Create: `app/Actions/Matches/ParseGameLogBinary.php`
- Create: `tests/Feature/Actions/Matches/ParseGameLogBinaryTest.php`

@pest-testing

- [ ] **Step 1: Write tests**

Run: `php artisan make:test --pest Actions/Matches/ParseGameLogBinaryTest --no-interaction`

```php
<?php

use App\Actions\Matches\ParseGameLogBinary;

function fixturePath(string $name): string
{
    return base_path("tests/fixtures/gamelogs/{$name}");
}

it('parses a clean 2-0 win file', function () {
    $raw = file_get_contents(fixturePath('clean_2_0_win.dat'));
    $result = ParseGameLogBinary::run($raw);

    expect($result)->not->toBeNull();
    expect($result['match_uuid'])->toBe('6a0f564b-f27a-42c0-acea-464f7929342b');
    expect($result['game_uuid'])->toBe('6a0f564b-f27a-42c0-acea-464f7929342b');
    expect($result['version'])->toBe(1);
    expect($result['entries'])->toHaveCount(253);
});

it('parses a large multi-game file', function () {
    $raw = file_get_contents(fixturePath('large_2_0_win.dat'));
    $result = ParseGameLogBinary::run($raw);

    expect($result)->not->toBeNull();
    expect($result['entries'])->toHaveCount(600);
});

it('parses an instant concede file', function () {
    $raw = file_get_contents(fixturePath('instant_concede.dat'));
    $result = ParseGameLogBinary::run($raw);

    expect($result)->not->toBeNull();
    expect($result['entries'])->toHaveCount(8);
});

it('returns entries with timestamp and message keys', function () {
    $raw = file_get_contents(fixturePath('instant_concede.dat'));
    $result = ParseGameLogBinary::run($raw);

    $entry = $result['entries'][0];
    expect($entry)->toHaveKeys(['timestamp', 'message']);
    expect($entry['timestamp'])->toBeString(); // ISO 8601 format
    expect($entry['message'])->toBeString();
    expect($entry['message'])->toContain('rolled a');
});

it('produces valid timestamps', function () {
    $raw = file_get_contents(fixturePath('clean_2_0_win.dat'));
    $result = ParseGameLogBinary::run($raw);

    $first = $result['entries'][0];
    $ts = \Carbon\Carbon::parse($first['timestamp']);
    expect($ts->year)->toBeGreaterThanOrEqual(2025);
    expect($ts->year)->toBeLessThanOrEqual(2027);
});

it('returns null for empty input', function () {
    expect(ParseGameLogBinary::run(''))->toBeNull();
});

it('returns null for truncated header', function () {
    expect(ParseGameLogBinary::run(str_repeat("\x00", 10)))->toBeNull();
});

it('handles incremental parsing from byte offset', function () {
    $raw = file_get_contents(fixturePath('clean_2_0_win.dat'));

    // Full parse
    $full = ParseGameLogBinary::run($raw);
    $totalEntries = count($full['entries']);

    // Parse first half by truncating file
    $halfSize = intdiv(strlen($raw), 2);
    $firstHalf = ParseGameLogBinary::run(substr($raw, 0, $halfSize));
    $firstHalfCount = count($firstHalf['entries']);
    $firstHalfOffset = $firstHalf['byte_offset'];

    expect($firstHalfCount)->toBeLessThan($totalEntries);
    expect($firstHalfOffset)->toBeLessThanOrEqual($halfSize);

    // Incremental parse from offset
    $remaining = ParseGameLogBinary::run($raw, $firstHalfOffset);
    $remainingCount = count($remaining['entries']);

    expect($firstHalfCount + $remainingCount)->toBe($totalEntries);
});

it('handles messages longer than 127 bytes with varint length', function () {
    // The clean_2_1_win.dat has triggered ability messages > 127 chars
    $raw = file_get_contents(fixturePath('clean_2_1_win.dat'));
    $result = ParseGameLogBinary::run($raw);

    expect($result)->not->toBeNull();
    expect($result['entries'])->toHaveCount(329);

    // Check that long messages are properly decoded (not truncated)
    $longMessages = collect($result['entries'])->filter(fn ($e) => strlen($e['message']) > 127);
    expect($longMessages)->not->toBeEmpty();
    foreach ($longMessages as $entry) {
        // Message should end cleanly (no random binary garbage)
        expect($entry['message'])->toMatch('/[\.\)\]!]$/');
    }
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ParseGameLogBinaryTest`

- [ ] **Step 3: Implement ParseGameLogBinary**

Create `app/Actions/Matches/ParseGameLogBinary.php`:

```php
<?php

namespace App\Actions\Matches;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ParseGameLogBinary
{
    /**
     * .NET epoch offset: ticks between 0001-01-01 and 1970-01-01.
     */
    private const DOTNET_EPOCH_OFFSET = 621355968000000000;

    /**
     * Current parser version. Increment when parsing logic changes
     * to trigger re-parsing of previously decoded entries.
     */
    public const VERSION = 1;

    /**
     * Parse a binary GameLog .dat file into structured entries.
     *
     * @param  string  $raw  Raw file contents
     * @param  int|null  $byteOffset  Resume parsing from this byte position (skip header)
     * @return array{match_uuid: string, game_uuid: string, version: int, type: int, entries: array, byte_offset: int}|null
     */
    public static function run(string $raw, ?int $byteOffset = null): ?array
    {
        $length = strlen($raw);

        if ($length < 78) {
            return null;
        }

        $pos = 0;

        // Parse header (only on full parse, not incremental)
        if ($byteOffset === null) {
            $version = ord($raw[$pos++]);
            $pos++; // unknown flag

            $uuidLen = ord($raw[$pos++]);
            if ($pos + $uuidLen > $length) {
                return null;
            }
            $matchUuid = substr($raw, $pos, $uuidLen);
            $pos += $uuidLen;

            $type = ord($raw[$pos++]);
            $pos++; // unknown flag

            $uuidLen2 = ord($raw[$pos++]);
            if ($pos + $uuidLen2 > $length) {
                return null;
            }
            $gameUuid = substr($raw, $pos, $uuidLen2);
            $pos += $uuidLen2;
        } else {
            // Incremental: skip header, start at offset
            $pos = $byteOffset;
            $matchUuid = null;
            $gameUuid = null;
            $version = null;
            $type = null;
        }

        $entries = [];

        while ($pos + 10 <= $length) {
            $entryStart = $pos;

            // 8-byte timestamp (int64 little-endian, .NET DateTime ticks)
            $ticks = unpack('P', substr($raw, $pos, 8))[1]; // P = unsigned 64-bit LE
            $pos += 8;

            // 1-byte flag
            $pos++; // flag (observed: always 0x00)

            // Varint message length (.NET Write7BitEncodedInt)
            $msgLen = 0;
            $shift = 0;
            do {
                if ($pos >= $length) {
                    // Truncated varint — rewind to entry start, stop parsing
                    $pos = $entryStart;
                    break 2;
                }
                $byte = ord($raw[$pos++]);
                $msgLen |= ($byte & 0x7F) << $shift;
                $shift += 7;
            } while ($byte & 0x80);

            // Read message
            if ($pos + $msgLen > $length) {
                // Truncated message — rewind to entry start, stop parsing
                $pos = $entryStart;
                break;
            }

            $message = substr($raw, $pos, $msgLen);
            $pos += $msgLen;

            // Convert .NET ticks to ISO 8601
            $unixSeconds = ($ticks - self::DOTNET_EPOCH_OFFSET) / 10_000_000;
            $timestamp = Carbon::createFromTimestamp($unixSeconds, 'UTC')->toIso8601String();

            $entries[] = [
                'timestamp' => $timestamp,
                'message' => $message,
            ];
        }

        return [
            'match_uuid' => $matchUuid,
            'game_uuid' => $gameUuid,
            'version' => $version,
            'type' => $type,
            'entries' => $entries,
            'byte_offset' => $pos,
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ParseGameLogBinaryTest`

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Matches/ParseGameLogBinary.php tests/Feature/Actions/Matches/ParseGameLogBinaryTest.php
git commit -m "feat: add binary GameLog parser with incremental support"
```

---

### Task 3: ExtractGameResults — Structured Result Extraction

**Files:**
- Create: `app/Actions/Matches/ExtractGameResults.php`
- Create: `tests/Feature/Actions/Matches/ExtractGameResultsTest.php`

@pest-testing

- [ ] **Step 1: Write tests**

Run: `php artisan make:test --pest Actions/Matches/ExtractGameResultsTest --no-interaction`

```php
<?php

use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameLogBinary;

function parseFixture(string $name): array
{
    $raw = file_get_contents(base_path("tests/fixtures/gamelogs/{$name}"));
    return ParseGameLogBinary::run($raw)['entries'];
}

/*
|--------------------------------------------------------------------------
| Clean Win Scenarios
|--------------------------------------------------------------------------
*/

it('extracts a clean 2-0 win', function () {
    $entries = parseFixture('clean_2_0_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['results'])->toBe([true, true]);
    expect($result['games'])->toHaveCount(2);
    expect($result['games'][0]['winner'])->toBe('anticloser');
    expect($result['games'][0]['end_reason'])->toBe('win');
    expect($result['games'][1]['winner'])->toBe('anticloser');
    expect($result['match_score'])->toBe([2, 0]);
});

it('extracts a 2-1 win', function () {
    $entries = parseFixture('clean_2_1_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['results'])->toBe([true, false, true]);
    expect($result['games'])->toHaveCount(3);
    expect($result['match_score'])->toBe([2, 1]);
});

it('extracts a 2-1 loss', function () {
    $entries = parseFixture('clean_2_1_loss.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['results'])->toBe([true, false, false]);
    expect($result['match_score'])->toBe([1, 2]);
});

/*
|--------------------------------------------------------------------------
| Concede / Disconnect Scenarios
|--------------------------------------------------------------------------
*/

it('extracts results with concedes', function () {
    $entries = parseFixture('concede_2_0.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['results'])->toBe([true, true]);
    expect($result['games'][0]['end_reason'])->toBeIn(['win', 'concede']);
    expect($result['games'][1]['end_reason'])->toBeIn(['win', 'concede']);
});

it('extracts results with disconnect', function () {
    $entries = parseFixture('disconnect_game1.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    // This file has 1 game where opponent disconnects
    expect($result['results'])->toHaveCount(1);
    expect($result['results'][0])->toBeTrue();
});

it('extracts instant concede', function () {
    $entries = parseFixture('instant_concede.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['results'])->toHaveCount(1);
    expect($result['results'][0])->toBeTrue();
    expect($result['games'][0]['end_reason'])->toBeIn(['win', 'concede']);
});

/*
|--------------------------------------------------------------------------
| Metadata Extraction
|--------------------------------------------------------------------------
*/

it('extracts on-play information', function () {
    $entries = parseFixture('clean_2_0_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['on_play'])->toHaveCount(2);
    // Each value is a boolean: true = local player on play
    foreach ($result['on_play'] as $val) {
        expect($val)->toBeBool();
    }
});

it('extracts starting hand sizes', function () {
    $entries = parseFixture('clean_2_0_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['starting_hands'])->not->toBeEmpty();
    foreach ($result['starting_hands'] as $hand) {
        expect($hand)->toHaveKeys(['player', 'starting_hand']);
        expect($hand['starting_hand'])->toBeInt();
        expect($hand['starting_hand'])->toBeBetween(1, 7);
    }
});

it('extracts player names', function () {
    $entries = parseFixture('clean_2_0_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['players'])->toHaveCount(2);
    expect($result['players'])->toContain('anticloser');
});

it('provides per-game timestamps', function () {
    $entries = parseFixture('clean_2_0_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    foreach ($result['games'] as $game) {
        expect($game)->toHaveKeys(['started_at', 'ended_at']);
        expect($game['started_at'])->not->toBeNull();
    }
});

/*
|--------------------------------------------------------------------------
| Large File
|--------------------------------------------------------------------------
*/

it('handles large multi-game files', function () {
    $entries = parseFixture('large_2_0_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    expect($result['results'])->toBe([true, true]);
    expect($result['match_score'])->toBe([2, 0]);
});

/*
|--------------------------------------------------------------------------
| Edge Cases
|--------------------------------------------------------------------------
*/

it('returns player names without @P prefix', function () {
    $entries = parseFixture('clean_2_0_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    foreach ($result['players'] as $player) {
        expect($player)->not->toStartWith('@');
    }
    foreach ($result['games'] as $game) {
        if ($game['winner']) {
            expect($game['winner'])->not->toStartWith('@');
        }
    }
});

it('provides on_play entry for each game', function () {
    $entries = parseFixture('clean_2_1_win.dat');
    $result = ExtractGameResults::run($entries, 'anticloser');

    // Should have on_play for each game that had a "chooses to play" line
    expect(count($result['on_play']))->toBeLessThanOrEqual(count($result['games']));
    expect(count($result['on_play']))->toBeGreaterThan(0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ExtractGameResultsTest`

- [ ] **Step 3: Implement ExtractGameResults**

Create `app/Actions/Matches/ExtractGameResults.php`:

```php
<?php

namespace App\Actions\Matches;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ExtractGameResults
{
    /**
     * Word-to-number mapping for starting hand sizes.
     */
    private const HAND_SIZE_MAP = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4,
        'five' => 5, 'six' => 6, 'seven' => 7,
    ];

    /**
     * Extract per-game results from decoded game log entries.
     *
     * @param  array<int, array{timestamp: string, message: string}>  $entries
     * @param  string  $localPlayer  The local player's username (without @P prefix)
     * @return array{games: array, players: array, match_score: ?array, results: array, on_play: array, starting_hands: array}
     */
    public static function run(array $entries, string $localPlayer): array
    {
        $games = self::splitIntoGames($entries);
        $players = self::detectPlayers($entries);

        $gameResults = [];
        $results = [];
        $onPlay = [];
        $startingHands = [];
        $matchScore = null;

        foreach ($games as $index => $gameEntries) {
            $game = self::analyzeGame($gameEntries, $index, $localPlayer, $players);
            $gameResults[] = $game;

            if ($game['winner'] !== null) {
                $results[] = ($game['winner'] === $localPlayer);
            }

            if ($game['on_play'] !== null) {
                $onPlay[] = ($game['on_play'] === $localPlayer);
            }

            foreach ($game['starting_hands'] as $player => $handSize) {
                $startingHands[] = [
                    'player' => $player,
                    'starting_hand' => $handSize,
                ];
            }
        }

        // Extract match score from "leads the match" / "wins the match" lines
        $matchScore = self::extractMatchScore($entries, $localPlayer, $players);

        // Cross-check: if match score disagrees with counted results, trust MTGO's tally
        if ($matchScore !== null) {
            $countedWins = count(array_filter($results, fn ($r) => $r === true));
            $countedLosses = count(array_filter($results, fn ($r) => $r === false));

            if ($countedWins !== $matchScore[0] || $countedLosses !== $matchScore[1]) {
                Log::channel('pipeline')->warning('GetGameLog: match score cross-check failed', [
                    'counted' => [$countedWins, $countedLosses],
                    'mtgo_score' => $matchScore,
                    'local_player' => $localPlayer,
                ]);

                // Rebuild results from MTGO's authoritative score
                $results = array_merge(
                    array_fill(0, $matchScore[0], true),
                    array_fill(0, $matchScore[1], false),
                );
            }
        }

        return [
            'games' => $gameResults,
            'players' => $players,
            'match_score' => $matchScore,
            'results' => $results,
            'on_play' => $onPlay,
            'starting_hands' => $startingHands,
        ];
    }

    /**
     * Split entries into per-game groups based on "joined the game" boundaries.
     *
     * @return array<int, array<int, array{timestamp: string, message: string}>>
     */
    private static function splitIntoGames(array $entries): array
    {
        $games = [];
        $current = [];
        $gameEndSeen = false;

        foreach ($entries as $entry) {
            $msg = $entry['message'];

            // Detect game-end signals
            if (preg_match('/wins the game|has conceded from the game|has lost connection to the game/', $msg)) {
                $gameEndSeen = true;
            }

            // Detect new game boundary: roll events or @P@P join events after a game end
            // The sequence at game boundaries is: rolls → @P@P joins → chooses to play → begins game
            if ($gameEndSeen && preg_match('/^@P\w+ rolled a \d/', $msg)) {
                // Roll event after a game end = start of new game
                $games[] = $current;
                $current = [];
                $gameEndSeen = false;
            }

            $current[] = $entry;
        }

        // Don't forget the last game
        if (! empty($current)) {
            $games[] = $current;
        }

        return $games;
    }

    /**
     * Detect the two player names from the entries.
     *
     * @return array<int, string>
     */
    private static function detectPlayers(array $entries): array
    {
        $players = [];

        foreach ($entries as $entry) {
            if (preg_match('/^@P@P(\w+) joined the game/', $entry['message'], $m)) {
                $players[$m[1]] = true;
            } elseif (preg_match('/^@P(\w+) rolled a \d/', $entry['message'], $m)) {
                $players[$m[1]] = true;
            }
        }

        return array_keys($players);
    }

    /**
     * Analyze a single game's entries.
     */
    private static function analyzeGame(array $entries, int $index, string $localPlayer, array $players): array
    {
        $winner = null;
        $loser = null;
        $endReason = 'unknown';
        $onPlay = null;
        $startingHands = [];
        $startedAt = null;
        $endedAt = null;

        foreach ($entries as $entry) {
            $msg = $entry['message'];
            $ts = $entry['timestamp'];

            // Track first/last timestamps
            if ($startedAt === null) {
                $startedAt = $ts;
            }
            $endedAt = $ts;

            // On play detection: "@P{player} chooses to play first/second."
            if (preg_match('/^@P(\w+) chooses to play first/', $msg, $m)) {
                $onPlay = $m[1];
            } elseif (preg_match('/^@P(\w+) chooses to play second/', $msg, $m)) {
                $onPlay = self::otherPlayer($m[1], $players);
            }

            // Starting hand: "@P{player} begins the game with {N} cards in hand."
            if (preg_match('/^@P(\w+) begins the game with (\w+) cards? in hand/', $msg, $m)) {
                $handRaw = strtolower($m[2]);
                $handSize = ctype_digit($handRaw)
                    ? (int) $handRaw
                    : (self::HAND_SIZE_MAP[$handRaw] ?? null);

                if ($handSize !== null) {
                    $startingHands[$m[1]] = $handSize;
                }
            }

            // Win detection: "@P{player} wins the game."
            if (preg_match('/^@P(\w+) wins the game/', $msg, $m)) {
                $winner = $m[1];
                $loser = self::otherPlayer($m[1], $players);
                $endReason = 'win';
            }

            // Concede detection: "@P{player} has conceded from the game."
            if (preg_match('/^@P(\w+) has conceded from the game/', $msg, $m)) {
                if ($winner === null) {
                    $loser = $m[1];
                    $winner = self::otherPlayer($m[1], $players);
                    $endReason = 'concede';
                }
            }

            // Disconnect detection: "@P{player} has lost connection to the game."
            if (preg_match('/^@P(\w+) has lost connection to the game/', $msg, $m)) {
                if ($winner === null) {
                    $loser = $m[1];
                    $winner = self::otherPlayer($m[1], $players);
                    $endReason = 'disconnect';
                }
            }
        }

        return [
            'game_index' => $index,
            'winner' => $winner,
            'loser' => $loser,
            'end_reason' => $endReason,
            'on_play' => $onPlay,
            'starting_hands' => $startingHands,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
        ];
    }

    /**
     * Find the other player given one player name and the player list.
     */
    private static function otherPlayer(string $player, array $players): ?string
    {
        foreach ($players as $p) {
            if ($p !== $player) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Extract match score from "leads the match X-Y" or "wins the match X-Y" lines.
     * Returns score as [localWins, opponentWins] or null if not found.
     */
    private static function extractMatchScore(array $entries, string $localPlayer, array $players): ?array
    {
        $lastScore = null;

        foreach ($entries as $entry) {
            // "@P{player} leads the match X-Y" or "@P{player} wins the match X-Y"
            if (preg_match('/^@P(\w+) (?:leads|wins) the match (\d+)-(\d+)/', $entry['message'], $m)) {
                $scorer = $m[1];
                $scorerWins = (int) $m[2];
                $scorerLosses = (int) $m[3];

                if ($scorer === $localPlayer) {
                    $lastScore = [$scorerWins, $scorerLosses];
                } else {
                    $lastScore = [$scorerLosses, $scorerWins];
                }
            }
        }

        return $lastScore;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ExtractGameResultsTest`

If any tests fail due to game boundary splitting edge cases, debug by examining the specific fixture's decoded entries and adjust the `splitIntoGames` logic. The join-event counting approach may need tuning based on real data patterns.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Matches/ExtractGameResults.php tests/Feature/Actions/Matches/ExtractGameResultsTest.php
git commit -m "feat: add structured game result extraction from decoded entries"
```

---

### Task 4: Refactor GetGameLog — Incremental Parse + Store

**Files:**
- Rewrite: `app/Actions/Matches/GetGameLog.php`
- Create: `tests/Feature/Actions/Matches/GetGameLogTest.php`

@pest-testing

- [ ] **Step 1: Write tests**

Run: `php artisan make:test --pest Actions/Matches/GetGameLogTest --no-interaction`

```php
<?php

use App\Actions\Matches\GetGameLog;
use App\Models\GameLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns null when no game log record exists', function () {
    expect(GetGameLog::run('nonexistent-token'))->toBeNull();
});

it('parses and stores decoded entries on first access', function () {
    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_0_win.dat');
    $log = GameLog::create([
        'match_token' => 'test-token-123',
        'file_path' => $fixturePath,
    ]);

    // Mock Mtgo username
    \App\Facades\Mtgo::shouldReceive('getUsername')->andReturn('anticloser');

    $result = GetGameLog::run('test-token-123');

    expect($result)->not->toBeNull();
    expect($result['results'])->toBe([true, true]);

    // Verify entries were stored
    $log->refresh();
    expect($log->decoded_entries)->not->toBeNull();
    expect($log->decoded_entries)->toHaveCount(253);
    expect($log->byte_offset)->toBeGreaterThan(0);
    expect($log->decoded_version)->toBe(1);
});

it('uses stored entries on subsequent access without re-reading file', function () {
    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_0_win.dat');
    $log = GameLog::create([
        'match_token' => 'test-token-456',
        'file_path' => $fixturePath,
    ]);

    \App\Facades\Mtgo::shouldReceive('getUsername')->andReturn('anticloser');

    // First call: parses and stores
    GetGameLog::run('test-token-456');
    $log->refresh();
    $storedOffset = $log->byte_offset;

    // Delete the file reference to prove we don't need it
    $log->update(['file_path' => '/nonexistent/path.dat']);

    // Second call: uses stored entries
    $result = GetGameLog::run('test-token-456');
    expect($result)->not->toBeNull();
    expect($result['results'])->toBe([true, true]);
});

it('returns results in backward-compatible format', function () {
    $fixturePath = base_path('tests/fixtures/gamelogs/clean_2_1_win.dat');
    GameLog::create([
        'match_token' => 'test-token-789',
        'file_path' => $fixturePath,
    ]);

    \App\Facades\Mtgo::shouldReceive('getUsername')->andReturn('anticloser');

    $result = GetGameLog::run('test-token-789');

    // Must have the three keys that downstream consumers expect
    expect($result)->toHaveKeys(['results', 'on_play', 'starting_hands']);
    expect($result['results'])->toBeArray();
    expect($result['on_play'])->toBeArray();
    expect($result['starting_hands'])->toBeArray();

    // results are booleans
    foreach ($result['results'] as $r) {
        expect($r)->toBeBool();
    }
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=GetGameLogTest`

- [ ] **Step 3: Rewrite GetGameLog**

Replace the contents of `app/Actions/Matches/GetGameLog.php`:

```php
<?php

namespace App\Actions\Matches;

use App\Facades\Mtgo;
use App\Models\GameLog;
use Illuminate\Support\Facades\Log;

class GetGameLog
{
    /**
     * Get structured game results for a match.
     *
     * Uses stored decoded entries if available, otherwise parses the binary
     * .dat file incrementally and stores the result for future access.
     *
     * @return array{results: array<int, bool>, on_play: array<int, bool>, starting_hands: array}|null
     */
    public static function run(string $token): ?array
    {
        $log = GameLog::where('match_token', $token)->first();

        if (! $log) {
            return null;
        }

        $you = Mtgo::getUsername();

        if (! $you) {
            throw new \RuntimeException('MTGO username not set');
        }

        // Sync entries from the .dat file (incremental if partially parsed)
        $entries = self::syncEntries($log);

        if (empty($entries)) {
            Log::channel('pipeline')->warning("GetGameLog: no entries decoded for match token {$token}");

            return null;
        }

        // Extract structured results
        $extracted = ExtractGameResults::run($entries, $you);

        return [
            'results' => $extracted['results'],
            'on_play' => $extracted['on_play'],
            'starting_hands' => $extracted['starting_hands'],
        ];
    }

    /**
     * Ensure decoded_entries is populated and up-to-date.
     *
     * If the .dat file has grown since last parse (byte_offset < file size),
     * incrementally parse new entries and append.
     *
     * @return array<int, array{timestamp: string, message: string}>
     */
    private static function syncEntries(GameLog $log): array
    {
        $entries = $log->decoded_entries ?? [];

        // If already decoded and file hasn't grown, use stored entries
        if (! empty($entries) && $log->decoded_version >= ParseGameLogBinary::VERSION) {
            $fileSize = self::getFileSize($log);

            // No new data to parse
            if ($fileSize === null || $log->byte_offset >= $fileSize) {
                return $entries;
            }
        }

        // Need to parse (or re-parse)
        $raw = self::readFile($log);

        if ($raw === null) {
            return $entries; // Return whatever we have stored
        }

        $offset = ! empty($entries) ? $log->byte_offset : null;
        $parsed = ParseGameLogBinary::run($raw, $offset);

        if ($parsed === null) {
            return $entries;
        }

        if (! empty($parsed['entries'])) {
            // Deduplication: if appending incrementally, check that the first new entry
            // doesn't overlap with the last stored entry (guards against app restart edge cases)
            if (! empty($entries) && ! empty($parsed['entries'])) {
                $lastStored = end($entries);
                $firstNew = $parsed['entries'][0];
                if ($lastStored['timestamp'] === $firstNew['timestamp'] && $lastStored['message'] === $firstNew['message']) {
                    array_shift($parsed['entries']);
                }
            }

            $entries = array_merge($entries, $parsed['entries']);

            $log->update([
                'decoded_entries' => $entries,
                'decoded_at' => now(),
                'byte_offset' => $parsed['byte_offset'],
                'decoded_version' => ParseGameLogBinary::VERSION,
            ]);
        }

        return $entries;
    }

    /**
     * Read the raw .dat file contents with Windows path fallback.
     */
    private static function readFile(GameLog $log): ?string
    {
        $raw = @file_get_contents($log->file_path);

        if ($raw === false) {
            $hashDir = basename(dirname(str_replace('\\', '/', $log->file_path)));
            $filename = basename(str_replace('\\', '/', $log->file_path));
            $fallback = storage_path("app/{$hashDir}/{$filename}");
            $raw = @file_get_contents($fallback);
        }

        if ($raw === false) {
            Log::channel('pipeline')->warning("GetGameLog: file not found", [
                'stored_path' => $log->file_path,
            ]);

            return null;
        }

        return $raw;
    }

    /**
     * Get the file size, returning null if file doesn't exist.
     */
    private static function getFileSize(GameLog $log): ?int
    {
        $size = @filesize($log->file_path);

        if ($size === false) {
            $hashDir = basename(dirname(str_replace('\\', '/', $log->file_path)));
            $filename = basename(str_replace('\\', '/', $log->file_path));
            $fallback = storage_path("app/{$hashDir}/{$filename}");
            $size = @filesize($fallback);
        }

        return $size !== false ? $size : null;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=GetGameLogTest`

- [ ] **Step 5: Run ALL existing tests to verify backward compatibility**

Run: `php artisan test --compact`

This is the critical check — all existing tests (AdvanceMatchState, SyncGameResults, etc.) must still pass since they call `GetGameLog::run()` and expect the same return format.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Matches/GetGameLog.php tests/Feature/Actions/Matches/GetGameLogTest.php
git commit -m "feat: refactor GetGameLog to use binary parser with incremental storage"
```

---

### Task 5: Run Pint and Full Test Suite

- [ ] **Step 1: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 2: Run full test suite**

Run: `php artisan test --compact`

Expected: All tests pass (existing + new).

- [ ] **Step 3: Commit formatting fixes if any**

```bash
git add -A
git commit -m "style: apply Pint formatting"
```
