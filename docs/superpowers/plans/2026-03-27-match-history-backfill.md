# Match History Backfill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Three-step import wizard that backfills match history from MTGO's local binary files into the database, with deck matching and reduced-fidelity card game stats.

**Architecture:** Six isolated actions in `app/Actions/Import/`, two single-action controllers, one Vue wizard page, one migration. No existing pipeline code is modified. Game history and game log binaries are parsed, cross-referenced by time+opponent, cards extracted, deck versions matched by oracle_id overlap, and user confirms before import.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, Inertia v2, Vue 3 Composition API, Tailwind v4, Wayfinder

**Spec:** `docs/superpowers/specs/2026-03-27-match-history-backfill-design.md`

---

## File Map

### New Files

| File | Responsibility |
|------|---------------|
| `database/migrations/xxxx_add_imported_to_matches_table.php` | Add `imported` boolean column |
| `app/Actions/Import/ExtractCardsFromGameLog.php` | Extract card names + CatalogIDs per player from parsed game log entries |
| `app/Actions/Import/MatchGameLogToHistory.php` | Link history records to game log files by time+opponent |
| `app/Actions/Import/SuggestDeckForMatch.php` | Compare oracle_id set against DeckVersion signatures |
| `app/Actions/Import/ParseImportableMatches.php` | Step 1 orchestrator — parse, match, extract, suggest |
| `app/Actions/Import/ImportMatches.php` | Step 3 — create match/game/player/stats records |
| `app/Actions/Import/ComputeImportedCardGameStats.php` | Reduced-fidelity card_game_stats for imported games |
| `app/Http/Controllers/Import/IndexController.php` | GET /import — render wizard page |
| `app/Http/Controllers/Import/ScanController.php` | POST /import/scan — run ParseImportableMatches, return results |
| `app/Http/Controllers/Import/StoreController.php` | POST /import — run ImportMatches with user selections |
| `resources/js/pages/import/Index.vue` | Import wizard page |
| `tests/Feature/Actions/Import/ExtractCardsFromGameLogTest.php` | Tests for card extraction |
| `tests/Feature/Actions/Import/MatchGameLogToHistoryTest.php` | Tests for history-to-log matching |
| `tests/Feature/Actions/Import/SuggestDeckForMatchTest.php` | Tests for deck suggestion |
| `tests/Feature/Actions/Import/ImportMatchesTest.php` | Tests for match import |
| `tests/Feature/Actions/Import/ComputeImportedCardGameStatsTest.php` | Tests for reduced-fidelity stats |

### Modified Files

| File | Change |
|------|--------|
| `routes/web.php` | Add import route group |
| `resources/js/components/AppHeader.vue` | Add Import button, restyle Settings button |
| `resources/js/pages/matches/Show.vue` | Conditional rendering for imported matches |
| `app/Models/MtgoMatch.php` | Add `imported` to casts |

---

### Task 1: Migration — add `imported` column

**Files:**
- Create: `database/migrations/xxxx_add_imported_to_matches_table.php`
- Modify: `app/Models/MtgoMatch.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration add_imported_to_matches_table --no-interaction
```

Replace the generated file contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->boolean('imported')->default(false)->after('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('imported');
        });
    }
};
```

- [ ] **Step 2: Add `imported` cast to MtgoMatch model**

In `app/Models/MtgoMatch.php`, add `'imported' => 'boolean'` to the `$casts` array:

```php
protected $casts = [
    'started_at' => 'datetime',
    'ended_at' => 'datetime',
    'submitted_at' => 'datetime',
    'failed_at' => 'datetime',
    'attempts' => 'integer',
    'state' => MatchState::class,
    'outcome' => MatchOutcome::class,
    'imported' => 'boolean',
];
```

- [ ] **Step 3: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "Add imported boolean column to matches table"
```

---

### Task 2: ExtractCardsFromGameLog action

**Files:**
- Create: `app/Actions/Import/ExtractCardsFromGameLog.php`
- Create: `tests/Feature/Actions/Import/ExtractCardsFromGameLogTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

use App\Actions\Import\ExtractCardsFromGameLog;
use App\Actions\Matches\ParseGameLogBinary;

it('extracts cards for each player from game log entries', function () {
    $raw = file_get_contents(base_path('tests/fixtures/gamelogs/clean_2_0_win.dat'));
    $parsed = ParseGameLogBinary::run($raw);
    $entries = $parsed['entries'];

    $result = ExtractCardsFromGameLog::run($entries);

    expect($result)->toHaveKeys(['players', 'cards_by_player']);
    expect($result['players'])->toBeArray()->not->toBeEmpty();

    // Each player should have cards extracted
    foreach ($result['players'] as $player) {
        expect($result['cards_by_player'][$player])->toBeArray();
    }

    // Cards should have mtgo_id and name
    $firstPlayer = $result['players'][0];
    $firstCard = $result['cards_by_player'][$firstPlayer][0] ?? null;
    expect($firstCard)->not->toBeNull();
    expect($firstCard)->toHaveKeys(['mtgo_id', 'name']);
    expect($firstCard['mtgo_id'])->toBeInt();
    expect($firstCard['name'])->toBeString()->not->toBeEmpty();
});

it('returns empty cards for instant concede games', function () {
    $raw = file_get_contents(base_path('tests/fixtures/gamelogs/instant_concede.dat'));
    $parsed = ParseGameLogBinary::run($raw);
    $entries = $parsed['entries'];

    $result = ExtractCardsFromGameLog::run($entries);

    expect($result['players'])->toBeArray();
    // May have zero or minimal cards
});

it('deduplicates cards by mtgo_id per player', function () {
    $raw = file_get_contents(base_path('tests/fixtures/gamelogs/clean_2_1_win.dat'));
    $parsed = ParseGameLogBinary::run($raw);
    $entries = $parsed['entries'];

    $result = ExtractCardsFromGameLog::run($entries);

    foreach ($result['players'] as $player) {
        $mtgoIds = array_column($result['cards_by_player'][$player], 'mtgo_id');
        expect($mtgoIds)->toBe(array_unique($mtgoIds));
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=ExtractCardsFromGameLog
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write implementation**

```php
<?php

namespace App\Actions\Import;

use App\Actions\Matches\ExtractGameResults;

class ExtractCardsFromGameLog
{
    /**
     * Extract unique card names and CatalogIDs per player from parsed game log entries.
     *
     * @param  array<int, array{timestamp: string, message: string}>  $entries
     * @return array{players: string[], cards_by_player: array<string, array<int, array{mtgo_id: int, name: string}>>}
     */
    public static function run(array $entries): array
    {
        $players = ExtractGameResults::detectPlayers($entries);
        $cardsByPlayer = [];

        foreach ($players as $player) {
            $cardsByPlayer[$player] = [];
        }

        $seen = []; // [player][mtgo_id] => true

        foreach ($entries as $entry) {
            $msg = $entry['message'];

            foreach ($players as $player) {
                if (! str_contains($msg, '@P'.$player)) {
                    continue;
                }

                preg_match_all('/@\[([^@]+)@:(\d+),(\d+):@\]/', $msg, $matches, PREG_SET_ORDER);

                foreach ($matches as $m) {
                    $name = $m[1];
                    $mtgoId = (int) $m[2];

                    if (isset($seen[$player][$mtgoId])) {
                        continue;
                    }

                    $seen[$player][$mtgoId] = true;
                    $cardsByPlayer[$player][] = [
                        'mtgo_id' => $mtgoId,
                        'name' => $name,
                    ];
                }
            }
        }

        return [
            'players' => $players,
            'cards_by_player' => $cardsByPlayer,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --compact --filter=ExtractCardsFromGameLog
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Import/ExtractCardsFromGameLog.php tests/Feature/Actions/Import/ExtractCardsFromGameLogTest.php
git commit -m "Add ExtractCardsFromGameLog action"
```

---

### Task 3: MatchGameLogToHistory action

**Files:**
- Create: `app/Actions/Import/MatchGameLogToHistory.php`
- Create: `tests/Feature/Actions/Import/MatchGameLogToHistoryTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

use App\Actions\Import\MatchGameLogToHistory;
use App\Actions\Matches\ParseGameHistory;
use App\Actions\Matches\ParseGameLogBinary;
use App\Actions\Matches\ExtractGameResults;
use App\Facades\Mtgo;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('matches history records to game log files by time and opponent', function () {
    $dataPath = storage_path('app/91F5DC46A0AFBF283E8FD4E9E184F175');
    Mtgo::shouldReceive('getLogDataPath')->andReturn($dataPath);

    $historyPath = $dataPath.'/mtgo_game_history';
    $records = ParseGameHistory::parse($historyPath);

    // Take a small sample
    $sample = array_slice($records, 0, 5);

    $result = MatchGameLogToHistory::run($sample, $dataPath);

    expect($result)->toBeArray();
    expect(count($result))->toBe(count($sample));

    // Each result should have the history_id and a game_log_token (possibly null)
    foreach ($result as $item) {
        expect($item)->toHaveKeys(['history_id', 'game_log_token', 'game_log_entries']);
        expect($item['history_id'])->toBeInt();
    }

    // Most should have a game log match
    $matched = array_filter($result, fn ($item) => $item['game_log_token'] !== null);
    expect(count($matched))->toBeGreaterThanOrEqual(3);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=MatchGameLogToHistory
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write implementation**

```php
<?php

namespace App\Actions\Import;

use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameLogBinary;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MatchGameLogToHistory
{
    /**
     * Match history records to game log files by StartTime ± 5 minutes and opponent.
     *
     * @param  array<int, array>  $historyRecords  Parsed game history records
     * @param  string  $dataPath  MTGO data directory path
     * @return array<int, array{history_id: int, game_log_token: ?string, game_log_entries: ?array}>
     */
    public static function run(array $historyRecords, string $dataPath): array
    {
        $logIndex = self::buildLogIndex($dataPath);
        $results = [];

        foreach ($historyRecords as $record) {
            $historyStart = Carbon::parse($record['StartTime']);
            $opponent = $record['Opponents'][0] ?? null;
            $match = null;

            if ($opponent) {
                foreach ($logIndex as $log) {
                    $logStart = Carbon::parse($log['first_timestamp']);
                    $timeDiff = abs($historyStart->diffInSeconds($logStart));

                    if ($timeDiff < 300 && in_array($opponent, $log['players'])) {
                        $match = $log;
                        break;
                    }
                }
            }

            $results[] = [
                'history_id' => $record['Id'],
                'game_log_token' => $match['token'] ?? null,
                'game_log_entries' => $match['entries'] ?? null,
            ];
        }

        return $results;
    }

    /**
     * Build an index of all game log files with their players and timestamps.
     *
     * @return array<int, array{token: string, first_timestamp: string, players: string[], entries: array}>
     */
    private static function buildLogIndex(string $dataPath): array
    {
        $pattern = $dataPath.'/Match_GameLog_*.dat';
        $files = glob($pattern);

        if (! $files) {
            return [];
        }

        $index = [];

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            $parsed = ParseGameLogBinary::run($raw);

            if (! $parsed || empty($parsed['entries'])) {
                continue;
            }

            $token = str_replace('Match_GameLog_', '', pathinfo($file, PATHINFO_FILENAME));
            $players = ExtractGameResults::detectPlayers($parsed['entries']);

            $index[] = [
                'token' => $token,
                'first_timestamp' => $parsed['entries'][0]['timestamp'],
                'players' => $players,
                'entries' => $parsed['entries'],
            ];
        }

        return $index;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --compact --filter=MatchGameLogToHistory
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Import/MatchGameLogToHistory.php tests/Feature/Actions/Import/MatchGameLogToHistoryTest.php
git commit -m "Add MatchGameLogToHistory action"
```

---

### Task 4: SuggestDeckForMatch action

**Files:**
- Create: `app/Actions/Import/SuggestDeckForMatch.php`
- Create: `tests/Feature/Actions/Import/SuggestDeckForMatchTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

use App\Actions\Import\SuggestDeckForMatch;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;

it('suggests a deck version when oracle_ids overlap sufficiently', function () {
    // Create cards with oracle_ids
    $cards = collect([
        Card::factory()->create(['mtgo_id' => '100', 'oracle_id' => 'oracle-a', 'name' => 'Card A']),
        Card::factory()->create(['mtgo_id' => '200', 'oracle_id' => 'oracle-b', 'name' => 'Card B']),
        Card::factory()->create(['mtgo_id' => '300', 'oracle_id' => 'oracle-c', 'name' => 'Card C']),
    ]);

    // Create a deck version with those oracle_ids in its signature
    $deck = Deck::factory()->create(['name' => 'Test Deck']);
    $signature = base64_encode('oracle-a:4:false|oracle-b:4:false|oracle-c:4:false|oracle-d:4:false');
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $signature,
    ]);

    // Match cards seen: 3 out of 4 oracle_ids in deck = 75% of seen cards match
    $localCards = [
        ['mtgo_id' => 100, 'name' => 'Card A'],
        ['mtgo_id' => 200, 'name' => 'Card B'],
        ['mtgo_id' => 300, 'name' => 'Card C'],
    ];

    $result = SuggestDeckForMatch::run($localCards);

    expect($result)->not->toBeNull();
    expect($result['deck_version_id'])->toBe($version->id);
    expect($result['deck_name'])->toBe('Test Deck');
    expect($result['confidence'])->toBeGreaterThanOrEqual(0.6);
});

it('returns null when no deck matches above threshold', function () {
    Card::factory()->create(['mtgo_id' => '100', 'oracle_id' => 'oracle-x', 'name' => 'Unrelated Card']);

    $deck = Deck::factory()->create(['name' => 'Other Deck']);
    $signature = base64_encode('oracle-z:4:false|oracle-y:4:false');
    DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $signature,
    ]);

    $localCards = [
        ['mtgo_id' => 100, 'name' => 'Unrelated Card'],
    ];

    $result = SuggestDeckForMatch::run($localCards);

    expect($result)->toBeNull();
});

it('includes soft-deleted decks in matching', function () {
    $card = Card::factory()->create(['mtgo_id' => '500', 'oracle_id' => 'oracle-del', 'name' => 'Del Card']);

    $deck = Deck::factory()->create(['name' => 'Deleted Deck']);
    $deck->delete(); // soft-delete

    $signature = base64_encode('oracle-del:4:false');
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $signature,
    ]);

    $localCards = [
        ['mtgo_id' => 500, 'name' => 'Del Card'],
    ];

    $result = SuggestDeckForMatch::run($localCards);

    expect($result)->not->toBeNull();
    expect($result['deck_deleted'])->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=SuggestDeckForMatch
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write implementation**

```php
<?php

namespace App\Actions\Import;

use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;

class SuggestDeckForMatch
{
    private const MIN_CONFIDENCE = 0.6;

    /**
     * Suggest the best matching DeckVersion for the given cards seen in a game log.
     *
     * @param  array<int, array{mtgo_id: int, name: string}>  $localCards
     * @return array{deck_version_id: int, deck_name: string, confidence: float, deck_deleted: bool}|null
     */
    public static function run(array $localCards): ?array
    {
        if (empty($localCards)) {
            return null;
        }

        $mtgoIds = array_column($localCards, 'mtgo_id');
        $oracleIds = Card::whereIn('mtgo_id', $mtgoIds)
            ->whereNotNull('oracle_id')
            ->pluck('oracle_id')
            ->unique()
            ->values()
            ->toArray();

        if (empty($oracleIds)) {
            return null;
        }

        $versions = DeckVersion::with(['deck' => fn ($q) => $q->withTrashed()])->get();
        $bestMatch = null;
        $bestScore = 0;

        foreach ($versions as $version) {
            $deckOracleIds = collect($version->cards)->pluck('oracle_id')->toArray();

            if (empty($deckOracleIds)) {
                continue;
            }

            $overlap = count(array_intersect($oracleIds, $deckOracleIds));
            $score = $overlap / count($oracleIds);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $version;
            }
        }

        if ($bestMatch === null || $bestScore < self::MIN_CONFIDENCE) {
            return null;
        }

        return [
            'deck_version_id' => $bestMatch->id,
            'deck_name' => $bestMatch->deck?->name ?? 'Unknown Deck',
            'confidence' => round($bestScore, 2),
            'deck_deleted' => $bestMatch->deck?->trashed() ?? false,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --compact --filter=SuggestDeckForMatch
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Import/SuggestDeckForMatch.php tests/Feature/Actions/Import/SuggestDeckForMatchTest.php
git commit -m "Add SuggestDeckForMatch action"
```

---

### Task 5: ParseImportableMatches orchestrator (Step 1)

**Files:**
- Create: `app/Actions/Import/ParseImportableMatches.php`

- [ ] **Step 1: Write implementation**

This is the Step 1 orchestrator. It ties together the previous actions. Testing is covered by integration tests added in Task 9.

```php
<?php

namespace App\Actions\Import;

use App\Actions\Cards\CreateMissingCards;
use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameHistory;
use App\Facades\Mtgo;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class ParseImportableMatches
{
    /**
     * Parse MTGO history + game logs and return importable match data.
     *
     * @return array<int, array>
     */
    public static function run(): array
    {
        $dataPath = Mtgo::getLogDataPath();
        $historyRecords = ParseGameHistory::parse();

        if (empty($historyRecords)) {
            return [];
        }

        // Filter out matches already in DB
        $existingMtgoIds = MtgoMatch::pluck('mtgo_id')->filter()->toArray();
        $newRecords = array_filter(
            $historyRecords,
            fn ($r) => ! in_array($r['Id'], $existingMtgoIds)
        );

        if (empty($newRecords)) {
            return [];
        }

        // Match history records to game log files
        $logMatches = MatchGameLogToHistory::run($newRecords, $dataPath);
        $logMatchesByHistoryId = collect($logMatches)->keyBy('history_id');

        // Extract cards from all matched game logs and collect unique mtgo_ids
        $allMtgoIds = [];
        $cardDataByHistoryId = [];

        foreach ($logMatches as $logMatch) {
            if ($logMatch['game_log_entries'] === null) {
                continue;
            }

            $cardData = ExtractCardsFromGameLog::run($logMatch['game_log_entries']);
            $cardDataByHistoryId[$logMatch['history_id']] = $cardData;

            foreach ($cardData['cards_by_player'] as $cards) {
                foreach ($cards as $card) {
                    $allMtgoIds[$card['mtgo_id']] = true;
                }
            }
        }

        // Create missing card stubs
        if (! empty($allMtgoIds)) {
            CreateMissingCards::run(array_keys($allMtgoIds));
        }

        // Build importable match array
        $results = [];

        foreach ($newRecords as $record) {
            $logMatch = $logMatchesByHistoryId->get($record['Id']);
            $hasGameLog = $logMatch && $logMatch['game_log_token'] !== null;
            $cardData = $cardDataByHistoryId[$record['Id']] ?? null;

            $localPlayer = null;
            $localCards = null;
            $opponentCards = null;
            $games = null;

            if ($hasGameLog && $cardData) {
                $opponent = $record['Opponents'][0] ?? null;
                $players = $cardData['players'];

                // Determine local player (the one who isn't the opponent)
                $localPlayer = collect($players)->first(fn ($p) => $p !== $opponent) ?? $players[0] ?? null;

                $localCards = $cardData['cards_by_player'][$localPlayer] ?? [];
                $opponentCards = $cardData['cards_by_player'][$opponent] ?? [];

                // Extract per-game results
                $gameResults = ExtractGameResults::run(
                    $logMatch['game_log_entries'],
                    $localPlayer
                );

                $games = collect($gameResults['games'])->map(fn ($g) => [
                    'game_index' => $g['game_index'],
                    'won' => $g['winner'] === $localPlayer,
                    'on_play' => $g['on_play'] === $localPlayer,
                    'starting_hand_size' => $g['starting_hands'][$localPlayer] ?? 7,
                    'opponent_hand_size' => $g['starting_hands'][$opponent] ?? 7,
                    'started_at' => $g['started_at'],
                    'ended_at' => $g['ended_at'],
                ])->toArray();
            }

            // Attempt deck matching
            $deckSuggestion = ($localCards && ! empty($localCards))
                ? SuggestDeckForMatch::run($localCards)
                : null;

            $wins = $record['GameWins'];
            $losses = $record['GameLosses'];
            $outcome = $wins > $losses ? 'win' : ($wins < $losses ? 'loss' : 'draw');

            $results[] = [
                'history_id' => $record['Id'],
                'started_at' => $record['StartTime'],
                'opponent' => $record['Opponents'][0] ?? 'Unknown',
                'format' => MtgoMatch::displayFormat($record['Format'] ?? ''),
                'format_raw' => $record['Format'] ?? '',
                'games_won' => $wins,
                'games_lost' => $losses,
                'outcome' => $outcome,
                'round' => $record['Round'] ?? 0,
                'description' => $record['Description'] ?? '',
                'has_game_log' => $hasGameLog,
                'game_log_token' => $logMatch['game_log_token'] ?? null,
                'games' => $games,
                'local_player' => $localPlayer,
                'local_cards' => $localCards,
                'opponent_cards' => $opponentCards,
                'suggested_deck_version_id' => $deckSuggestion['deck_version_id'] ?? null,
                'suggested_deck_name' => $deckSuggestion['deck_name'] ?? null,
                'deck_match_confidence' => $deckSuggestion['confidence'] ?? null,
                'deck_deleted' => $deckSuggestion['deck_deleted'] ?? false,
                'game_ids' => $record['GameIds'] ?? [],
            ];
        }

        return $results;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Actions/Import/ParseImportableMatches.php
git commit -m "Add ParseImportableMatches orchestrator action"
```

---

### Task 6: ComputeImportedCardGameStats action

**Files:**
- Create: `app/Actions/Import/ComputeImportedCardGameStats.php`
- Create: `tests/Feature/Actions/Import/ComputeImportedCardGameStatsTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

use App\Actions\Import\ComputeImportedCardGameStats;
use App\Models\Card;
use App\Models\CardGameStat;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;

it('creates card game stats for an imported game with deck version', function () {
    $cardA = Card::factory()->create(['mtgo_id' => '100', 'oracle_id' => 'oracle-a', 'name' => 'Card A']);
    $cardB = Card::factory()->create(['mtgo_id' => '200', 'oracle_id' => 'oracle-b', 'name' => 'Card B']);

    $deck = Deck::factory()->create();
    $signature = base64_encode('oracle-a:4:false|oracle-b:2:false|oracle-a:1:true');
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $signature,
    ]);

    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'imported' => true,
    ]);

    $game = Game::factory()->create([
        'match_id' => $match->id,
        'won' => true,
    ]);

    // Cards seen in game log for local player
    $seenMtgoIds = [100]; // Only Card A was seen

    ComputeImportedCardGameStats::run($game, $version->id, $seenMtgoIds, isPostboard: false);

    $stats = CardGameStat::where('game_id', $game->id)->get();

    // Should have stats for oracle-a and oracle-b (all cards in deck)
    expect($stats)->toHaveCount(2);

    $statA = $stats->firstWhere('oracle_id', 'oracle-a');
    expect($statA->quantity)->toBe(4); // mainboard quantity
    expect($statA->seen)->toBe(1);     // was seen in game log
    expect($statA->kept)->toBe(0);     // always 0 for imports
    expect($statA->won)->toBeTrue();
    expect($statA->is_postboard)->toBeFalse();
    expect($statA->sided_out)->toBeFalse();

    $statB = $stats->firstWhere('oracle_id', 'oracle-b');
    expect($statB->seen)->toBe(0); // was NOT seen in game log
});

it('skips stats when game result is null', function () {
    $match = MtgoMatch::factory()->create(['imported' => true]);
    $game = Game::factory()->create([
        'match_id' => $match->id,
        'won' => null,
    ]);

    ComputeImportedCardGameStats::run($game, 1, [], isPostboard: false);

    expect(CardGameStat::where('game_id', $game->id)->count())->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=ComputeImportedCardGameStats
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write implementation**

```php
<?php

namespace App\Actions\Import;

use App\Models\Card;
use App\Models\CardGameStat;
use App\Models\DeckVersion;
use App\Models\Game;

class ComputeImportedCardGameStats
{
    /**
     * Create reduced-fidelity card_game_stats for an imported game.
     *
     * @param  array<int>  $seenMtgoIds  CatalogIDs seen in game log for local player
     */
    public static function run(Game $game, int $deckVersionId, array $seenMtgoIds, bool $isPostboard): void
    {
        if ($game->won === null) {
            return;
        }

        $version = DeckVersion::find($deckVersionId);

        if (! $version) {
            return;
        }

        $deckCards = collect($version->cards);

        if ($deckCards->isEmpty()) {
            return;
        }

        // Map seen mtgo_ids to oracle_ids
        $seenOracleIds = [];
        if (! empty($seenMtgoIds)) {
            $seenOracleIds = Card::whereIn('mtgo_id', $seenMtgoIds)
                ->whereNotNull('oracle_id')
                ->pluck('oracle_id')
                ->unique()
                ->toArray();
        }

        // Build mainboard quantities by oracle_id
        $mainboardQuantities = [];
        foreach ($deckCards as $card) {
            if (($card['sideboard'] ?? 'false') === 'false') {
                $oracleId = $card['oracle_id'];
                $mainboardQuantities[$oracleId] = ($mainboardQuantities[$oracleId] ?? 0) + (int) $card['quantity'];
            }
        }

        $rows = [];
        $now = now();

        foreach ($mainboardQuantities as $oracleId => $quantity) {
            $rows[] = [
                'oracle_id' => $oracleId,
                'game_id' => $game->id,
                'deck_version_id' => $deckVersionId,
                'quantity' => $quantity,
                'kept' => 0,
                'seen' => in_array($oracleId, $seenOracleIds) ? 1 : 0,
                'won' => $game->won,
                'is_postboard' => $isPostboard,
                'sided_out' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($rows)) {
            CardGameStat::insertOrIgnore($rows);
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --compact --filter=ComputeImportedCardGameStats
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Import/ComputeImportedCardGameStats.php tests/Feature/Actions/Import/ComputeImportedCardGameStatsTest.php
git commit -m "Add ComputeImportedCardGameStats action"
```

---

### Task 7: ImportMatches action (Step 3)

**Files:**
- Create: `app/Actions/Import/ImportMatches.php`
- Create: `tests/Feature/Actions/Import/ImportMatchesTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

use App\Actions\Import\ImportMatches;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Card;
use App\Models\CardGameStat;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;

it('creates match, games, and player records from import data', function () {
    $importData = [
        [
            'history_id' => 12345678,
            'started_at' => '2025-06-01T12:00:00Z',
            'opponent' => 'testopponent',
            'format_raw' => 'CMODERN',
            'games_won' => 2,
            'games_lost' => 1,
            'outcome' => 'win',
            'round' => 0,
            'has_game_log' => true,
            'game_log_token' => 'abc-123',
            'local_player' => 'anticloser',
            'games' => [
                ['game_index' => 0, 'won' => true, 'on_play' => true, 'starting_hand_size' => 7, 'opponent_hand_size' => 7, 'started_at' => '2025-06-01T12:00:00Z', 'ended_at' => '2025-06-01T12:15:00Z'],
                ['game_index' => 1, 'won' => false, 'on_play' => false, 'starting_hand_size' => 6, 'opponent_hand_size' => 7, 'started_at' => '2025-06-01T12:16:00Z', 'ended_at' => '2025-06-01T12:30:00Z'],
                ['game_index' => 2, 'won' => true, 'on_play' => true, 'starting_hand_size' => 7, 'opponent_hand_size' => 7, 'started_at' => '2025-06-01T12:31:00Z', 'ended_at' => '2025-06-01T12:45:00Z'],
            ],
            'local_cards' => [['mtgo_id' => 100, 'name' => 'Card A']],
            'game_ids' => [111, 222, 333],
            'deck_version_id' => null,
        ],
    ];

    $result = ImportMatches::run($importData);

    expect($result['imported'])->toBe(1);

    $match = MtgoMatch::where('mtgo_id', '12345678')->first();
    expect($match)->not->toBeNull();
    expect($match->imported)->toBeTrue();
    expect($match->state)->toBe(MatchState::Complete);
    expect($match->outcome)->toBe(MatchOutcome::Win);
    expect($match->games_won)->toBe(2);
    expect($match->games_lost)->toBe(1);
    expect($match->format)->toBe('CMODERN');

    // Games created
    expect($match->games)->toHaveCount(3);

    // Players created and attached
    $game1 = $match->games->sortBy('started_at')->first();
    expect($game1->players)->toHaveCount(2);

    $local = $game1->players->first(fn ($p) => $p->pivot->is_local);
    expect($local->username)->toBe('anticloser');
    expect($local->pivot->on_play)->toBeTrue();
});

it('creates match without games when no game log available', function () {
    $importData = [
        [
            'history_id' => 99999999,
            'started_at' => '2025-07-01T12:00:00Z',
            'opponent' => 'unknownplayer',
            'format_raw' => 'CPAUPER',
            'games_won' => 0,
            'games_lost' => 2,
            'outcome' => 'loss',
            'round' => 0,
            'has_game_log' => false,
            'game_log_token' => null,
            'local_player' => null,
            'games' => null,
            'local_cards' => null,
            'game_ids' => [],
            'deck_version_id' => null,
        ],
    ];

    ImportMatches::run($importData);

    $match = MtgoMatch::where('mtgo_id', '99999999')->first();
    expect($match)->not->toBeNull();
    expect($match->imported)->toBeTrue();
    expect($match->games)->toHaveCount(0);
});

it('skips duplicate mtgo_ids', function () {
    MtgoMatch::factory()->create(['mtgo_id' => '55555555']);

    $importData = [
        [
            'history_id' => 55555555,
            'started_at' => '2025-08-01T12:00:00Z',
            'opponent' => 'dup',
            'format_raw' => 'CMODERN',
            'games_won' => 1,
            'games_lost' => 0,
            'outcome' => 'win',
            'round' => 0,
            'has_game_log' => false,
            'game_log_token' => null,
            'local_player' => null,
            'games' => null,
            'local_cards' => null,
            'game_ids' => [],
            'deck_version_id' => null,
        ],
    ];

    $result = ImportMatches::run($importData);
    expect($result['skipped'])->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=ImportMatchesTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write implementation**

```php
<?php

namespace App\Actions\Import;

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Card;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Support\Str;

class ImportMatches
{
    /**
     * Import selected matches into the database.
     *
     * @param  array<int, array>  $matches  Each item includes history data + user's deck_version_id choice
     * @return array{imported: int, skipped: int}
     */
    public static function run(array $matches): array
    {
        $imported = 0;
        $skipped = 0;

        foreach ($matches as $data) {
            // Skip if already exists
            if (MtgoMatch::where('mtgo_id', (string) $data['history_id'])->exists()) {
                $skipped++;

                continue;
            }

            $outcome = match ($data['outcome']) {
                'win' => MatchOutcome::Win,
                'loss' => MatchOutcome::Loss,
                'draw' => MatchOutcome::Draw,
                default => MatchOutcome::Unknown,
            };

            $lastGameEnd = null;
            if (! empty($data['games'])) {
                $lastGameEnd = collect($data['games'])->pluck('ended_at')->filter()->last();
            }

            $match = MtgoMatch::create([
                'token' => Str::uuid()->toString(),
                'mtgo_id' => (string) $data['history_id'],
                'deck_version_id' => $data['deck_version_id'] ?? null,
                'format' => $data['format_raw'],
                'match_type' => $data['round'] > 0 ? 'League' : 'Constructed',
                'games_won' => $data['games_won'],
                'games_lost' => $data['games_lost'],
                'started_at' => $data['started_at'],
                'ended_at' => $lastGameEnd,
                'state' => MatchState::Complete,
                'outcome' => $outcome,
                'imported' => true,
            ]);

            // Create games if we have game log data
            if (! empty($data['games']) && $data['local_player']) {
                self::createGames($match, $data);
            }

            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private static function createGames(MtgoMatch $match, array $data): void
    {
        $localPlayer = Player::firstOrCreate(['username' => $data['local_player']]);
        $opponent = Player::firstOrCreate(['username' => $data['opponent']]);

        $seenMtgoIds = collect($data['local_cards'] ?? [])->pluck('mtgo_id')->toArray();

        foreach ($data['games'] as $index => $gameData) {
            $gameId = $data['game_ids'][$index] ?? null;

            $game = Game::create([
                'match_id' => $match->id,
                'mtgo_id' => $gameId ? (string) $gameId : Str::uuid()->toString(),
                'won' => $gameData['won'] ?? null,
                'started_at' => $gameData['started_at'],
                'ended_at' => $gameData['ended_at'],
            ]);

            $game->players()->attach($localPlayer->id, [
                'is_local' => true,
                'on_play' => $gameData['on_play'] ?? false,
                'starting_hand_size' => $gameData['starting_hand_size'] ?? 7,
                'instance_id' => 0,
                'deck_json' => null,
            ]);

            $game->players()->attach($opponent->id, [
                'is_local' => false,
                'on_play' => ! ($gameData['on_play'] ?? false),
                'starting_hand_size' => $gameData['opponent_hand_size'] ?? 7,
                'instance_id' => 0,
                'deck_json' => null,
            ]);

            // Compute reduced-fidelity card game stats
            if ($match->deck_version_id && $game->won !== null) {
                ComputeImportedCardGameStats::run(
                    $game,
                    $match->deck_version_id,
                    $seenMtgoIds,
                    isPostboard: $index > 0
                );
            }
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --compact --filter=ImportMatchesTest
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Import/ImportMatches.php tests/Feature/Actions/Import/ImportMatchesTest.php
git commit -m "Add ImportMatches action"
```

---

### Task 8: Routes and controllers

**Files:**
- Create: `app/Http/Controllers/Import/IndexController.php`
- Create: `app/Http/Controllers/Import/ScanController.php`
- Create: `app/Http/Controllers/Import/StoreController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Create IndexController**

```php
<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckVersion;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        $deckVersions = DeckVersion::with(['deck' => fn ($q) => $q->withTrashed()])
            ->orderByDesc('modified_at')
            ->get()
            ->map(fn (DeckVersion $v) => [
                'id' => $v->id,
                'deck_name' => $v->deck?->name ?? 'Unknown',
                'deck_deleted' => $v->deck?->trashed() ?? false,
                'modified_at' => $v->modified_at->format('d/m/Y'),
                'format' => $v->deck?->format ?? '',
            ]);

        return Inertia::render('import/Index', [
            'deckVersions' => $deckVersions,
        ]);
    }
}
```

- [ ] **Step 2: Create ScanController**

```php
<?php

namespace App\Http\Controllers\Import;

use App\Actions\Import\ParseImportableMatches;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ScanController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $matches = ParseImportableMatches::run();

        return response()->json([
            'matches' => $matches,
        ]);
    }
}
```

- [ ] **Step 3: Create StoreController**

```php
<?php

namespace App\Http\Controllers\Import;

use App\Actions\Import\ImportMatches;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'matches' => 'required|array|min:1',
            'matches.*.history_id' => 'required|integer',
            'matches.*.started_at' => 'required|string',
            'matches.*.opponent' => 'required|string',
            'matches.*.format_raw' => 'required|string',
            'matches.*.games_won' => 'required|integer',
            'matches.*.games_lost' => 'required|integer',
            'matches.*.outcome' => 'required|string',
            'matches.*.round' => 'integer',
            'matches.*.has_game_log' => 'required|boolean',
            'matches.*.game_log_token' => 'nullable|string',
            'matches.*.local_player' => 'nullable|string',
            'matches.*.games' => 'nullable|array',
            'matches.*.local_cards' => 'nullable|array',
            'matches.*.game_ids' => 'nullable|array',
            'matches.*.deck_version_id' => 'nullable|integer|exists:deck_versions,id',
        ]);

        $result = ImportMatches::run($validated['matches']);

        return response()->json($result);
    }
}
```

- [ ] **Step 4: Add routes to web.php**

Add the import use statements at the top of `routes/web.php`:

```php
use App\Http\Controllers\Import\IndexController as ImportIndexController;
use App\Http\Controllers\Import\ScanController;
use App\Http\Controllers\Import\StoreController;
```

Add the route group inside the main `Route::group([], ...)` closure, before the `updates` route:

```php
    $router->group([
        'prefix' => 'import',
    ], function (Router $group) {
        $group->get('/', ImportIndexController::class)->name('import.index');
        $group->post('scan', ScanController::class)->name('import.scan');
        $group->post('/', StoreController::class)->name('import.store');
    });
```

- [ ] **Step 5: Generate Wayfinder types**

```bash
cd /Volumes/Dev/mymtgo/client && npx vite build 2>&1 | tail -5
```

This generates the TypeScript action files for the new controllers.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Import/ routes/web.php
git commit -m "Add import wizard routes and controllers"
```

---

### Task 9: AppHeader — add Import button and restyle Settings

**Files:**
- Modify: `resources/js/components/AppHeader.vue`

- [ ] **Step 1: Update AppHeader.vue**

Replace the full `<script setup>` and `<template>` in `resources/js/components/AppHeader.vue`:

```vue
<script setup lang="ts">
import DashboardController from '@/actions/App/Http/Controllers/IndexController';
import ImportIndexController from '@/actions/App/Http/Controllers/Import/IndexController';
import SettingsIndexController from '@/actions/App/Http/Controllers/Settings/IndexController';
import SwitchAccountController from '@/actions/App/Http/Controllers/Settings/SwitchAccountController';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ChevronDown, FileUp, Settings } from 'lucide-vue-next';

const page = usePage<{
    activeAccount: string | null;
    accounts: Array<{ id: number; username: string; active: boolean }>;
}>();

function switchAccount(username: string) {
    router.patch(
        SwitchAccountController.url(),
        { username },
        {
            preserveScroll: false,
        },
    );
}
</script>

<template>
    <header class="flex h-12 shrink-0 items-center justify-between border-b border-black/80 bg-black/10 px-4 text-sidebar-foreground">
        <Link :href="DashboardController.url()" class="text-base font-semibold tracking-tight"> mymtgo </Link>

        <div class="flex items-center gap-2">
            <DropdownMenu v-if="page.props.accounts && page.props.accounts.length > 1">
                <DropdownMenuTrigger
                    class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-sm text-sidebar-foreground/70 transition-colors hover:text-sidebar-foreground"
                >
                    {{ page.props.activeAccount ?? 'No account' }}
                    <ChevronDown class="size-3" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        v-for="account in page.props.accounts"
                        :key="account.id"
                        @click="switchAccount(account.username)"
                        :class="{ 'font-semibold': account.active }"
                    >
                        {{ account.username }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <span v-else-if="page.props.activeAccount" class="text-sm text-sidebar-foreground/70">
                {{ page.props.activeAccount }}
            </span>

            <Link
                :href="ImportIndexController.url()"
                class="inline-flex items-center gap-1.5 rounded-md border border-sidebar-border px-2.5 py-1 text-sm text-sidebar-foreground/70 transition-colors hover:text-sidebar-foreground"
            >
                <FileUp class="size-4" />
                Import
            </Link>

            <Link
                :href="SettingsIndexController.url()"
                class="inline-flex items-center gap-1.5 rounded-md border border-sidebar-border px-2.5 py-1 text-sm text-sidebar-foreground/70 transition-colors hover:text-sidebar-foreground"
            >
                <Settings class="size-4" />
                Settings
            </Link>
        </div>
    </header>
</template>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/components/AppHeader.vue
git commit -m "Add Import button and restyle Settings button in AppHeader"
```

---

### Task 10: Import wizard Vue page

**Files:**
- Create: `resources/js/pages/import/Index.vue`

- [ ] **Step 1: Create the wizard page**

```vue
<script setup lang="ts">
import ScanController from '@/actions/App/Http/Controllers/Import/ScanController';
import StoreController from '@/actions/App/Http/Controllers/Import/StoreController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Check, FileUp, Minus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface DeckVersionOption {
    id: number;
    deck_name: string;
    deck_deleted: boolean;
    modified_at: string;
    format: string;
}

interface ImportableMatch {
    history_id: number;
    started_at: string;
    opponent: string;
    format: string;
    format_raw: string;
    games_won: number;
    games_lost: number;
    outcome: string;
    round: number;
    description: string;
    has_game_log: boolean;
    game_log_token: string | null;
    games: Array<{
        game_index: number;
        won: boolean;
        on_play: boolean;
        starting_hand_size: number;
        opponent_hand_size: number;
        started_at: string;
        ended_at: string;
    }> | null;
    local_player: string | null;
    local_cards: Array<{ mtgo_id: number; name: string }> | null;
    opponent_cards: Array<{ mtgo_id: number; name: string }> | null;
    suggested_deck_version_id: number | null;
    suggested_deck_name: string | null;
    deck_match_confidence: number | null;
    deck_deleted: boolean;
    game_ids: number[];
}

const props = defineProps<{
    deckVersions: DeckVersionOption[];
}>();

const scanning = ref(false);
const importing = ref(false);
const scanned = ref(false);
const matches = ref<ImportableMatch[]>([]);
const selectedIds = ref<Set<number>>(new Set());
const deckChoices = ref<Record<number, number | null>>({});
const accepted = ref(false);
const importResult = ref<{ imported: number; skipped: number } | null>(null);

const summary = computed(() => {
    const total = matches.value.length;
    const withGameLog = matches.value.filter((m) => m.has_game_log).length;
    const withDeck = matches.value.filter((m) => m.suggested_deck_version_id !== null).length;
    return { total, withGameLog, withDeck };
});

const selectedCount = computed(() => selectedIds.value.size);

const activeDeckVersions = computed(() => props.deckVersions.filter((d) => !d.deck_deleted));
const deletedDeckVersions = computed(() => props.deckVersions.filter((d) => d.deck_deleted));

async function scan() {
    scanning.value = true;
    scanned.value = false;
    importResult.value = null;

    try {
        const response = await fetch(ScanController.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
            },
        });

        const data = await response.json();
        matches.value = data.matches;
        scanned.value = true;

        // Pre-populate deck choices from suggestions
        for (const match of matches.value) {
            if (match.suggested_deck_version_id && (match.deck_match_confidence ?? 0) >= 0.6) {
                deckChoices.value[match.history_id] = match.suggested_deck_version_id;
            } else {
                deckChoices.value[match.history_id] = null;
            }
        }
    } catch (e) {
        console.error('Scan failed:', e);
    } finally {
        scanning.value = false;
    }
}

function toggleSelect(historyId: number) {
    if (selectedIds.value.has(historyId)) {
        selectedIds.value.delete(historyId);
    } else {
        selectedIds.value.add(historyId);
    }
    // Trigger reactivity
    selectedIds.value = new Set(selectedIds.value);
}

function selectAll() {
    selectedIds.value = new Set(matches.value.map((m) => m.history_id));
}

function selectWithDeck() {
    selectedIds.value = new Set(
        matches.value.filter((m) => deckChoices.value[m.history_id] !== null).map((m) => m.history_id),
    );
}

function deselectAll() {
    selectedIds.value = new Set();
}

async function importSelected() {
    if (!accepted.value || selectedCount.value === 0) return;

    importing.value = true;

    const selected = matches.value
        .filter((m) => selectedIds.value.has(m.history_id))
        .map((m) => ({
            ...m,
            deck_version_id: deckChoices.value[m.history_id] ?? null,
        }));

    try {
        const response = await fetch(StoreController.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ matches: selected }),
        });

        const data = await response.json();
        importResult.value = data;

        // Remove imported matches from the list
        const importedIds = new Set(selected.map((m) => m.history_id));
        matches.value = matches.value.filter((m) => !importedIds.has(m.history_id));
        selectedIds.value = new Set();
    } catch (e) {
        console.error('Import failed:', e);
    } finally {
        importing.value = false;
    }
}

function formatDate(iso: string): string {
    const d = new Date(iso);
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function confidenceColor(confidence: number | null): string {
    if (confidence === null) return 'text-muted-foreground';
    if (confidence >= 0.8) return 'text-green-500';
    if (confidence >= 0.6) return 'text-yellow-500';
    return 'text-red-500';
}
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-6 p-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Import Match History</h1>
            <p class="text-sm text-muted-foreground">
                Backfill matches from MTGO's local history files into your database.
            </p>
        </div>

        <!-- Warning banner -->
        <Card class="border-yellow-500/50 bg-yellow-500/5">
            <CardContent class="flex items-start gap-3 pt-6">
                <AlertTriangle class="mt-0.5 size-5 shrink-0 text-yellow-500" />
                <div class="space-y-2 text-sm">
                    <p>
                        <strong>Imported matches have reduced data fidelity.</strong> Opening hands, sideboard changes,
                        game timelines, and turn estimates will not be available. Card game statistics will be
                        approximate — cards are counted as "seen" based on game log mentions, not zone tracking.
                    </p>
                    <label class="flex items-center gap-2">
                        <Checkbox v-model:checked="accepted" />
                        <span>I understand and accept these limitations</span>
                    </label>
                </div>
            </CardContent>
        </Card>

        <!-- Scan button -->
        <div v-if="!scanned" class="flex justify-center">
            <Button @click="scan" :disabled="scanning" size="lg">
                <Spinner v-if="scanning" class="mr-2 size-4" />
                <FileUp v-else class="mr-2 size-4" />
                {{ scanning ? 'Scanning...' : 'Scan Match History' }}
            </Button>
        </div>

        <!-- Import result banner -->
        <Card v-if="importResult" class="border-green-500/50 bg-green-500/5">
            <CardContent class="flex items-center gap-3 pt-6">
                <Check class="size-5 text-green-500" />
                <p class="text-sm">
                    Successfully imported {{ importResult.imported }} match(es).
                    <span v-if="importResult.skipped"> {{ importResult.skipped }} skipped (already exist).</span>
                </p>
            </CardContent>
        </Card>

        <!-- Results -->
        <template v-if="scanned && matches.length > 0">
            <!-- Summary -->
            <div class="flex items-center justify-between">
                <p class="text-sm text-muted-foreground">
                    Found <strong>{{ summary.total }}</strong> matches.
                    <strong>{{ summary.withGameLog }}</strong> have game logs.
                    <strong>{{ summary.withDeck }}</strong> have suggested deck matches.
                </p>
                <div class="flex items-center gap-2">
                    <Button variant="outline" size="sm" @click="selectAll">Select all</Button>
                    <Button variant="outline" size="sm" @click="selectWithDeck">Select with deck</Button>
                    <Button variant="outline" size="sm" @click="deselectAll">Deselect all</Button>
                </div>
            </div>

            <!-- Table -->
            <div class="rounded-md border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/50 text-left text-xs text-muted-foreground">
                            <th class="w-10 p-3"></th>
                            <th class="p-3">Date</th>
                            <th class="p-3">Opponent</th>
                            <th class="p-3">Format</th>
                            <th class="p-3">Result</th>
                            <th class="p-3">Deck</th>
                            <th class="w-20 p-3 text-center">Confidence</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="match in matches"
                            :key="match.history_id"
                            class="border-b transition-colors hover:bg-muted/30"
                            :class="{ 'bg-muted/10': selectedIds.has(match.history_id) }"
                        >
                            <td class="p-3">
                                <Checkbox
                                    :checked="selectedIds.has(match.history_id)"
                                    @update:checked="toggleSelect(match.history_id)"
                                />
                            </td>
                            <td class="p-3 whitespace-nowrap">{{ formatDate(match.started_at) }}</td>
                            <td class="p-3">{{ match.opponent }}</td>
                            <td class="p-3">{{ match.format }}</td>
                            <td class="p-3">
                                <template v-if="match.has_game_log && match.games">
                                    <span class="flex gap-1">
                                        <Badge
                                            v-for="(game, i) in match.games"
                                            :key="i"
                                            :variant="game.won ? 'default' : 'destructive'"
                                            class="text-xs"
                                        >
                                            {{ game.won ? 'W' : 'L' }}
                                        </Badge>
                                    </span>
                                </template>
                                <template v-else>
                                    <span class="text-muted-foreground">
                                        {{ match.games_won }}-{{ match.games_lost }}
                                    </span>
                                </template>
                            </td>
                            <td class="p-3">
                                <select
                                    class="h-8 w-full max-w-[200px] rounded-md border border-input bg-background px-2 text-xs"
                                    :value="deckChoices[match.history_id] ?? ''"
                                    @change="deckChoices[match.history_id] = ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null"
                                >
                                    <option value="">No deck</option>
                                    <optgroup v-if="activeDeckVersions.length" label="Active Decks">
                                        <option
                                            v-for="dv in activeDeckVersions"
                                            :key="dv.id"
                                            :value="dv.id"
                                        >
                                            {{ dv.deck_name }} ({{ dv.modified_at }})
                                        </option>
                                    </optgroup>
                                    <optgroup v-if="deletedDeckVersions.length" label="Deleted Decks">
                                        <option
                                            v-for="dv in deletedDeckVersions"
                                            :key="dv.id"
                                            :value="dv.id"
                                        >
                                            {{ dv.deck_name }} (deleted) ({{ dv.modified_at }})
                                        </option>
                                    </optgroup>
                                </select>
                            </td>
                            <td class="p-3 text-center">
                                <span
                                    v-if="match.deck_match_confidence !== null"
                                    :class="confidenceColor(match.deck_match_confidence)"
                                    class="text-xs font-medium"
                                >
                                    {{ Math.round(match.deck_match_confidence * 100) }}%
                                </span>
                                <Minus v-else class="mx-auto size-4 text-muted-foreground" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Import button -->
            <div class="flex items-center justify-between">
                <p class="text-sm text-muted-foreground">{{ selectedCount }} match(es) selected</p>
                <Button
                    @click="importSelected"
                    :disabled="!accepted || selectedCount === 0 || importing"
                    size="lg"
                >
                    <Spinner v-if="importing" class="mr-2 size-4" />
                    {{ importing ? 'Importing...' : `Import ${selectedCount} match(es)` }}
                </Button>
            </div>
        </template>

        <!-- No results -->
        <Card v-if="scanned && matches.length === 0 && !importResult">
            <CardContent class="pt-6 text-center text-sm text-muted-foreground">
                No importable matches found. All history records are already in your database.
            </CardContent>
        </Card>
    </div>
</template>
```

- [ ] **Step 2: Build frontend to verify no TypeScript errors**

```bash
cd /Volumes/Dev/mymtgo/client && npx vite build 2>&1 | tail -10
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/import/Index.vue
git commit -m "Add import wizard Vue page"
```

---

### Task 11: Match detail page — handle imported matches

**Files:**
- Modify: `resources/js/pages/matches/Show.vue`

- [ ] **Step 1: Check what Show.vue needs**

Read `resources/js/pages/matches/Show.vue` and find the sections that display:
- Opening hand (`keptHand`, `mulliganedHands`)
- Sideboard changes (`sideboardChanges`)
- Turn estimation (`turns`)

These are already conditionally rendered (check for empty arrays/null values). The main change needed: pass the `imported` flag from the controller and show an info banner on the match detail page when viewing an imported match.

- [ ] **Step 2: Update the match show controller to pass `imported` flag**

Read `app/Http/Controllers/Matches/ShowController.php` and add `'imported' => $match->imported ?? false` to the Inertia props.

- [ ] **Step 3: Add imported match banner to Show.vue**

Add after the match header section in `Show.vue`:

```vue
<Card v-if="imported" class="border-yellow-500/30 bg-yellow-500/5">
    <CardContent class="flex items-center gap-2 py-3 text-sm text-yellow-600 dark:text-yellow-400">
        <AlertTriangle class="size-4 shrink-0" />
        This is an imported match. Opening hands, sideboard changes, and turn estimates are not available.
    </CardContent>
</Card>
```

Add `AlertTriangle` to the lucide imports and `imported` to the `defineProps`.

- [ ] **Step 4: Verify the existing conditional rendering handles null/empty gracefully**

The `BuildMatchGameData` action already returns `null` for turns, empty arrays for hands, and empty arrays for sideboard changes when timeline data is missing. The Vue components already use `v-if` checks on these. No additional changes needed for game card rendering.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/matches/Show.vue app/Http/Controllers/Matches/ShowController.php
git commit -m "Show imported match banner on match detail page"
```

---

### Task 12: Run Pint and final verification

**Files:** All PHP files created/modified

- [ ] **Step 1: Run Pint formatter**

```bash
cd /Volumes/Dev/mymtgo/client && vendor/bin/pint --dirty
```

- [ ] **Step 2: Run all import tests**

```bash
php artisan test --compact --filter=Import
```

Expected: All tests PASS.

- [ ] **Step 3: Build frontend**

```bash
cd /Volumes/Dev/mymtgo/client && npx vite build 2>&1 | tail -10
```

Expected: Build succeeds with no errors.

- [ ] **Step 4: Commit any Pint fixes**

```bash
git add -A && git commit -m "Apply Pint formatting to import wizard code"
```

- [ ] **Step 5: Final integration test — verify scan endpoint works with real fixtures**

```bash
php artisan tinker --execute '
    \Illuminate\Support\Facades\Cache::flush();
    $result = \App\Actions\Import\ParseImportableMatches::run();
    echo "Importable matches: " . count($result) . "\n";
    $withDeck = collect($result)->filter(fn($r) => $r["suggested_deck_version_id"] !== null)->count();
    echo "With deck suggestion: " . $withDeck . "\n";
    $withLog = collect($result)->filter(fn($r) => $r["has_game_log"])->count();
    echo "With game log: " . $withLog . "\n";
'
```

Expected: ~649 importable matches, most with game logs, some with deck suggestions.

---

## Deferred: Archetype Estimation

The spec mentions calling `POST /api/archetypes/estimate` after import to tag opponent archetypes. This is deferred to a follow-up task because:
- It requires API calls to an external service (per-match)
- The core import functionality works without it
- It can be added as a post-import background job later

When implemented, it would iterate imported matches with opponent cards and call `DetermineDeckArchetype::run()` for each.
