# Import Performance Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the match history import to handle 50k+ matches via background jobs, pre-decoded game logs, and a deck-first UX with pagination.

**Architecture:** User selects a deck, a background job matches history records against pre-decoded game logs and scores confidence, results are paginated. Game logs are decoded at discovery time. The heavy scan work moves off the HTTP request.

**Tech Stack:** Laravel 12, Vue 3, Inertia.js v2, Tailwind CSS v4, Pest v4, SQLite

**Spec:** `docs/superpowers/specs/2026-03-27-import-performance-redesign.md`

---

## File Structure

### New Files
| File | Responsibility |
|------|---------------|
| `database/migrations/XXXX_create_import_scans_table.php` | Schema for import_scans |
| `database/migrations/XXXX_create_import_scan_matches_table.php` | Schema for import_scan_matches |
| `app/Models/ImportScan.php` | Eloquent model for scans |
| `app/Models/ImportScanMatch.php` | Eloquent model for scan results |
| `app/Jobs/ProcessImportScan.php` | Background job orchestrating the scan |
| `app/Actions/Import/PopulateCardsInChunks.php` | Extracted from ParseImportableMatches |
| `app/Actions/Import/ScoreMatchConfidence.php` | Computes deck match confidence for a set of cards |
| `app/Http/Controllers/Import/ScanStatusController.php` | GET /import/scan/{id} — poll status |
| `app/Http/Controllers/Import/ScanMatchesController.php` | GET /import/scan/{id}/matches — paginated results |
| `app/Http/Controllers/Import/ImportAllController.php` | POST /import/scan/{id}/import-all |
| `app/Http/Controllers/Import/CancelScanController.php` | DELETE /import/scan/{id} |
| `tests/Feature/Jobs/ProcessImportScanTest.php` | Tests for the scan job |
| `tests/Feature/Actions/Import/ScoreMatchConfidenceTest.php` | Tests for confidence scoring |
| `tests/Feature/Actions/Import/PopulateCardsInChunksTest.php` | Tests for card population |
| `tests/Feature/Http/Import/ScanFlowTest.php` | Integration tests for scan endpoints |

### Modified Files
| File | Changes |
|------|---------|
| `app/Actions/Pipeline/DiscoverGameLogs.php` | Add `discoverAll()` method; decode entries at discovery time |
| `app/Actions/Import/MatchGameLogToHistory.php` | Remove file parsing fallback, pure DB reads |
| `app/Actions/Import/ImportMatches.php` | Read from import_scan_matches instead of frontend payload |
| `app/Http/Controllers/Import/ScanController.php` | Dispatch job instead of running inline |
| `app/Http/Controllers/Import/StoreController.php` | Read from scan, not frontend payload |
| `app/Http/Controllers/Import/IndexController.php` | Pass existing scan data to frontend |
| `routes/web.php` | Add new scan endpoints |
| `resources/js/pages/import/Index.vue` | Complete rewrite for deck-first UX |

### Removed Files
| File | Reason |
|------|--------|
| `app/Actions/Import/SuggestDeckForMatch.php` | User picks deck upfront |
| `app/Actions/Import/ParseImportableMatches.php` | Replaced by ProcessImportScan job |

---

## Task 1: Migrations — import_scans and import_scan_matches

**Files:**
- Create: `database/migrations/XXXX_create_import_scans_table.php`
- Create: `database/migrations/XXXX_create_import_scan_matches_table.php`

- [ ] **Step 1: Create import_scans migration**

```bash
cd E:/mymtgo/client && php artisan make:migration create_import_scans_table --no-interaction
```

Edit the generated file:

```php
Schema::create('import_scans', function (Blueprint $table) {
    $table->id();
    $table->foreignId('deck_version_id')->constrained();
    $table->string('status')->default('processing'); // processing, complete, failed, cancelled
    $table->unsignedInteger('progress')->default(0);
    $table->unsignedInteger('total')->default(0);
    $table->text('error')->nullable();
    $table->timestamps();
});
```

- [ ] **Step 2: Create import_scan_matches migration**

```bash
cd E:/mymtgo/client && php artisan make:migration create_import_scan_matches_table --no-interaction
```

Edit the generated file:

```php
Schema::create('import_scan_matches', function (Blueprint $table) {
    $table->id();
    $table->foreignId('import_scan_id')->constrained()->cascadeOnDelete();
    $table->unsignedInteger('history_id');
    $table->dateTime('started_at');
    $table->string('opponent');
    $table->string('format');
    $table->string('format_display');
    $table->unsignedInteger('games_won');
    $table->unsignedInteger('games_lost');
    $table->string('outcome');
    $table->string('game_log_token')->nullable();
    $table->float('confidence')->nullable();
    $table->unsignedInteger('round')->default(0);
    $table->string('description')->nullable();
    $table->json('game_ids')->nullable();
    $table->string('local_player')->nullable();
    $table->timestamps();

    $table->index('import_scan_id');
});
```

- [ ] **Step 3: Run migrations**

```bash
cd E:/mymtgo/client && php artisan migrate --no-interaction
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*import_scan*
git commit -m "feat: add import_scans and import_scan_matches migrations"
```

---

## Task 2: Models — ImportScan and ImportScanMatch

**Files:**
- Create: `app/Models/ImportScan.php`
- Create: `app/Models/ImportScanMatch.php`

- [ ] **Step 1: Create ImportScan model**

```bash
cd E:/mymtgo/client && php artisan make:model ImportScan --no-interaction
```

Edit `app/Models/ImportScan.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportScan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'total' => 'integer',
        ];
    }

    public function deckVersion(): BelongsTo
    {
        return $this->belongsTo(DeckVersion::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ImportScanMatch::class);
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
```

- [ ] **Step 2: Create ImportScanMatch model**

```bash
cd E:/mymtgo/client && php artisan make:model ImportScanMatch --no-interaction
```

Edit `app/Models/ImportScanMatch.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportScanMatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'game_ids' => 'array',
            'confidence' => 'float',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(ImportScan::class, 'import_scan_id');
    }
}
```

- [ ] **Step 3: Run Pint**

```bash
cd E:/mymtgo/client && php vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Commit**

```bash
git add app/Models/ImportScan.php app/Models/ImportScanMatch.php
git commit -m "feat: add ImportScan and ImportScanMatch models"
```

---

## Task 3: Decode game logs at discovery time

**Files:**
- Modify: `app/Actions/Pipeline/DiscoverGameLogs.php`
- Modify: `app/Models/GameLog.php`
- Test: `tests/Feature/Actions/Pipeline/DiscoverGameLogsTest.php`

- [ ] **Step 1: Read existing DiscoverGameLogs test**

Read `tests/Feature/Actions/Pipeline/DiscoverGameLogsTest.php` to understand the existing test patterns.

- [ ] **Step 2: Write test for decoding at discovery time**

Add a test to `tests/Feature/Actions/Pipeline/DiscoverGameLogsTest.php` that verifies when a GameLog is created via `run()`, `decoded_entries` is populated if the file is a valid binary game log. Use a fixture file if available, otherwise mock `ParseGameLogBinary::run()`.

- [ ] **Step 3: Run test to verify it fails**

```bash
cd E:/mymtgo/client && php artisan test --compact --filter=DiscoverGameLogs
```

- [ ] **Step 4: Modify DiscoverGameLogs::run() to decode at creation**

In `app/Actions/Pipeline/DiscoverGameLogs.php`, after `GameLog::firstOrCreate(...)`, if the record was just created (check `wasRecentlyCreated`), read the file and populate decoded columns:

```php
$gameLog = GameLog::firstOrCreate(
    ['match_token' => $token],
    ['file_path' => $file->getRealPath()],
);

if ($gameLog->wasRecentlyCreated) {
    self::decodeGameLog($gameLog);
}
```

Add private helper:

```php
private static function decodeGameLog(GameLog $gameLog): void
{
    if (! $gameLog->file_path || ! file_exists($gameLog->file_path)) {
        return;
    }

    try {
        $raw = file_get_contents($gameLog->file_path);
        $parsed = ParseGameLogBinary::run($raw);

        if ($parsed && ! empty($parsed['entries'])) {
            $gameLog->update([
                'decoded_entries' => $parsed['entries'],
                'decoded_at' => now(),
                'byte_offset' => $parsed['byte_offset'],
                'decoded_version' => ParseGameLogBinary::VERSION,
            ]);
        }
    } catch (\Throwable $e) {
        Log::channel('pipeline')->warning("DiscoverGameLogs: failed to decode {$gameLog->file_path}", [
            'error' => $e->getMessage(),
        ]);
    }
}
```

Import `ParseGameLogBinary` and `Log` at the top of the file.

- [ ] **Step 5: Add discoverAll() method**

Add to `DiscoverGameLogs.php`:

```php
/**
 * Discover ALL game log files in the directory, regardless of active match state.
 * Used by import to ensure historical game logs are in the DB.
 */
public static function discoverAll(?string $directory = null): int
{
    $directory = $directory ?? app('mtgo')->getLogDataPath();

    if (! $directory || ! is_dir($directory)) {
        return 0;
    }

    $finder = (new Finder)
        ->files()
        ->in($directory)
        ->name('*Match_GameLog*')
        ->ignoreUnreadableDirs();

    $discovered = 0;

    foreach ($finder as $file) {
        $parts = explode('_', $file->getFilenameWithoutExtension());
        $token = end($parts);

        $gameLog = GameLog::firstOrCreate(
            ['match_token' => $token],
            ['file_path' => $file->getRealPath()],
        );

        if ($gameLog->wasRecentlyCreated) {
            self::decodeGameLog($gameLog);
            $discovered++;
        }
    }

    return $discovered;
}
```

- [ ] **Step 6: Also apply decoding in discoverForToken()**

In the existing `discoverForToken()` method, add decoding after `firstOrCreate`:

```php
$gameLog = GameLog::firstOrCreate(
    ['match_token' => $token],
    ['file_path' => $file->getRealPath()],
);

if ($gameLog->wasRecentlyCreated) {
    self::decodeGameLog($gameLog);
}

return $gameLog;
```

- [ ] **Step 7: Write test for discoverAll()**

Add to the test file: a test that creates a temp dir with multiple `Match_GameLog_*.dat` files and verifies `discoverAll()` creates GameLog records for all of them regardless of match state.

- [ ] **Step 8: Run all DiscoverGameLogs tests**

```bash
cd E:/mymtgo/client && php artisan test --compact --filter=DiscoverGameLogs
```

- [ ] **Step 9: Run Pint**

```bash
cd E:/mymtgo/client && php vendor/bin/pint --dirty --format agent
```

- [ ] **Step 10: Commit**

```bash
git add app/Actions/Pipeline/DiscoverGameLogs.php app/Models/GameLog.php tests/Feature/Actions/Pipeline/DiscoverGameLogsTest.php
git commit -m "feat: decode game logs at discovery time, add discoverAll()"
```

---

## Task 4: Extract PopulateCardsInChunks as shared action

**Files:**
- Create: `app/Actions/Import/PopulateCardsInChunks.php`
- Modify: `app/Actions/Import/ImportMatches.php` (update reference)
- Test: `tests/Feature/Actions/Import/PopulateCardsInChunksTest.php`

- [ ] **Step 1: Create the action**

Extract `ParseImportableMatches::populateCardsInChunks()` into `app/Actions/Import/PopulateCardsInChunks.php` as a static `run()` method. The logic is identical — copy from `ParseImportableMatches.php` lines 169-233.

```php
<?php

namespace App\Actions\Import;

use App\Actions\RegisterDevice;
use App\Models\Card;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Facades\Settings;

class PopulateCardsInChunks
{
    public static function run(): void
    {
        // ... exact same logic as ParseImportableMatches::populateCardsInChunks()
    }
}
```

- [ ] **Step 2: Update ImportMatches to use the new action**

In `app/Actions/Import/ImportMatches.php`, replace the call to `ParseImportableMatches::populateCardsInChunks()` (line 213) with `PopulateCardsInChunks::run()`. Remove the `ParseImportableMatches` import if no longer needed.

- [ ] **Step 3: Write a basic test**

Test in `tests/Feature/Actions/Import/PopulateCardsInChunksTest.php`: create a Card stub without a name, mock HTTP to return card data, verify the card gets populated. Follow existing test patterns.

- [ ] **Step 4: Run tests**

```bash
cd E:/mymtgo/client && php artisan test --compact --filter=PopulateCardsInChunks
```

- [ ] **Step 5: Run Pint and commit**

```bash
cd E:/mymtgo/client && php vendor/bin/pint --dirty --format agent
git add app/Actions/Import/PopulateCardsInChunks.php app/Actions/Import/ImportMatches.php tests/Feature/Actions/Import/PopulateCardsInChunksTest.php
git commit -m "refactor: extract PopulateCardsInChunks into shared action"
```

---

## Task 5: ScoreMatchConfidence action

**Files:**
- Create: `app/Actions/Import/ScoreMatchConfidence.php`
- Test: `tests/Feature/Actions/Import/ScoreMatchConfidenceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\Import\ScoreMatchConfidence;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('computes confidence as ratio of matching oracle_ids', function () {
    // Create cards with oracle_ids
    $card1 = Card::create(['mtgo_id' => 100, 'oracle_id' => 'oracle-a', 'name' => 'Lightning Bolt']);
    $card2 = Card::create(['mtgo_id' => 200, 'oracle_id' => 'oracle-b', 'name' => 'Mountain']);
    $card3 = Card::create(['mtgo_id' => 300, 'oracle_id' => 'oracle-c', 'name' => 'Goblin Guide']);

    // Create a deck version with oracle-a and oracle-b
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->for($deck)->create([
        'cards' => [
            ['oracle_id' => 'oracle-a', 'quantity' => 4],
            ['oracle_id' => 'oracle-b', 'quantity' => 4],
        ],
    ]);

    // Game log found mtgo_ids 100, 200, 300 — oracle-a, oracle-b, oracle-c
    $mtgoIds = [100, 200, 300];

    $confidence = ScoreMatchConfidence::run($mtgoIds, $deckVersion);

    // 2 out of 3 oracle_ids match the deck
    expect($confidence)->toBe(round(2 / 3, 2));
});

it('returns null when no oracle_ids can be resolved', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->for($deck)->create([
        'cards' => [['oracle_id' => 'oracle-a', 'quantity' => 4]],
    ]);

    // mtgo_id 999 has no card record
    $confidence = ScoreMatchConfidence::run([999], $deckVersion);

    expect($confidence)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd E:/mymtgo/client && php artisan test --compact --filter=ScoreMatchConfidence
```

- [ ] **Step 3: Implement ScoreMatchConfidence**

```php
<?php

namespace App\Actions\Import;

use App\Models\Card;
use App\Models\DeckVersion;

class ScoreMatchConfidence
{
    /**
     * Compute how well a set of mtgo_ids matches a deck version's card list.
     *
     * @param  array<int>  $mtgoIds  Card mtgo_ids extracted from game log
     * @return float|null  Confidence 0.0-1.0, or null if no oracle_ids resolved
     */
    public static function run(array $mtgoIds, DeckVersion $deckVersion): ?float
    {
        if (empty($mtgoIds)) {
            return null;
        }

        $oracleIds = Card::whereIn('mtgo_id', $mtgoIds)
            ->whereNotNull('oracle_id')
            ->pluck('oracle_id')
            ->unique()
            ->values();

        if ($oracleIds->isEmpty()) {
            return null;
        }

        $deckOracleIds = collect($deckVersion->cards)->pluck('oracle_id')->unique()->values();

        if ($deckOracleIds->isEmpty()) {
            return null;
        }

        $overlap = $oracleIds->intersect($deckOracleIds)->count();

        return round($overlap / $oracleIds->count(), 2);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd E:/mymtgo/client && php artisan test --compact --filter=ScoreMatchConfidence
```

- [ ] **Step 5: Run Pint and commit**

```bash
cd E:/mymtgo/client && php vendor/bin/pint --dirty --format agent
git add app/Actions/Import/ScoreMatchConfidence.php tests/Feature/Actions/Import/ScoreMatchConfidenceTest.php
git commit -m "feat: add ScoreMatchConfidence action"
```

---

## Task 6: ProcessImportScan background job

**Files:**
- Create: `app/Jobs/ProcessImportScan.php`
- Test: `tests/Feature/Jobs/ProcessImportScanTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Jobs/ProcessImportScanTest.php`. Test the core flow: create an ImportScan, mock `ParseGameHistory::parse()` to return known history records, create GameLog records with decoded_entries in the DB, run the job, assert ImportScanMatch rows are created with correct confidence scores and that the scan status is `complete`.

Key test scenarios:
- Matches are found and scored correctly
- Scan progress is updated
- Scan status transitions to `complete`
- Already-imported matches (existing mtgo_id in matches table) are excluded
- Cancelled scan stops early (set status to cancelled before running, verify it returns without processing)
- Backfill: undecoded GameLogs get decoded_entries populated during backfill phase
- Backfill: missing/corrupt files are skipped gracefully (create GameLog with non-existent file_path)
- Empty history: scan completes with total=0 when no new records found

- [ ] **Step 2: Run test to verify it fails**

```bash
cd E:/mymtgo/client && php artisan test --compact --filter=ProcessImportScan
```

- [ ] **Step 3: Implement the job**

Create `app/Jobs/ProcessImportScan.php`:

```php
<?php

namespace App\Jobs;

use App\Actions\Import\ExtractCardsFromGameLog;
use App\Actions\Import\PopulateCardsInChunks;
use App\Actions\Import\ScoreMatchConfidence;
use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameHistory;
use App\Actions\Pipeline\DiscoverGameLogs;
use App\Models\DeckVersion;
use App\Models\GameLog;
use App\Models\ImportScan;
use App\Models\ImportScanMatch;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessImportScan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600; // 10 minutes max

    public function __construct(
        public int $scanId,
    ) {}

    public function handle(): void
    {
        $scan = ImportScan::find($this->scanId);

        if (! $scan || $scan->isCancelled()) {
            return;
        }

        try {
            $this->process($scan);
        } catch (\Throwable $e) {
            $scan->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            Log::channel('pipeline')->error('ProcessImportScan failed', [
                'scan_id' => $this->scanId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function process(ImportScan $scan): void
    {
        // Step 1: Discover all game logs on disk (historical ones too)
        DiscoverGameLogs::discoverAll();

        // Step 2: Backfill any undecoded game logs
        $this->backfillGameLogs($scan);

        if ($scan->fresh()->isCancelled()) {
            return;
        }

        // Step 3: Parse history file
        $historyRecords = ParseGameHistory::parse();

        if (empty($historyRecords)) {
            $scan->update(['status' => 'complete', 'total' => 0]);
            return;
        }

        // Step 4: Filter out matches already in DB
        $existingMtgoIds = MtgoMatch::pluck('mtgo_id')->filter()->toArray();
        $newRecords = array_values(array_filter(
            $historyRecords,
            fn ($r) => ! in_array($r['Id'], $existingMtgoIds)
        ));

        if (empty($newRecords)) {
            $scan->update(['status' => 'complete', 'total' => 0]);
            return;
        }

        $scan->update(['total' => count($newRecords)]);

        // Step 5: Build game log index from DB
        $logIndex = $this->buildGameLogIndex();

        // Step 6: Populate cards (needed for confidence scoring)
        PopulateCardsInChunks::run();

        // Step 7: Load deck version for scoring
        $deckVersion = DeckVersion::find($scan->deck_version_id);

        // Step 8: Match + score in batches
        $batches = array_chunk($newRecords, 500);
        $processed = 0;

        foreach ($batches as $batch) {
            if ($scan->fresh()->isCancelled()) {
                return;
            }

            $rows = [];

            foreach ($batch as $record) {
                $matchedLog = $this->findMatchingLog($record, $logIndex);
                $confidence = null;
                $localPlayer = null;

                if ($matchedLog && $deckVersion) {
                    $cardData = ExtractCardsFromGameLog::run($matchedLog['entries']);
                    $opponent = $record['Opponents'][0] ?? null;
                    $localPlayer = collect($cardData['players'])->first(fn ($p) => $p !== $opponent) ?? $cardData['players'][0] ?? null;
                    $localMtgoIds = collect($cardData['cards_by_player'][$localPlayer] ?? [])->pluck('mtgo_id')->toArray();

                    if (! empty($localMtgoIds)) {
                        $confidence = ScoreMatchConfidence::run($localMtgoIds, $deckVersion);
                    }
                }

                $wins = $record['GameWins'];
                $losses = $record['GameLosses'];

                $rows[] = [
                    'import_scan_id' => $scan->id,
                    'history_id' => $record['Id'],
                    'started_at' => $record['StartTime'],
                    'opponent' => $record['Opponents'][0] ?? 'Unknown',
                    'format' => $record['Format'] ?? '',
                    'format_display' => MtgoMatch::displayFormat($record['Format'] ?? ''),
                    'games_won' => $wins,
                    'games_lost' => $losses,
                    'outcome' => $wins > $losses ? 'win' : ($wins < $losses ? 'loss' : 'draw'),
                    'game_log_token' => $matchedLog['token'] ?? null,
                    'confidence' => $confidence,
                    'round' => $record['Round'] ?? 0,
                    'description' => $record['Description'] ?? '',
                    'game_ids' => json_encode($record['GameIds'] ?? []),
                    'local_player' => $localPlayer,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            ImportScanMatch::insert($rows);
            $processed += count($batch);
            $scan->update(['progress' => $processed]);
        }

        $scan->update(['status' => 'complete']);
    }

    /**
     * Backfill decoded_entries for any GameLog records missing them.
     * Spec calls for a separate BackfillGameLogEntries class, but inlining here
     * is simpler since this is the only caller. Progress is reflected in the scan's
     * progress bar during this phase.
     */
    private function backfillGameLogs(ImportScan $scan): void
    {
        $undecoded = GameLog::whereNull('decoded_entries')->get();

        if ($undecoded->isEmpty()) {
            return;
        }

        $scan->update(['total' => $undecoded->count()]);
        $decoded = 0;

        foreach ($undecoded as $gameLog) {
            if (! $gameLog->file_path || ! file_exists($gameLog->file_path)) {
                $decoded++;
                continue;
            }

            try {
                $raw = file_get_contents($gameLog->file_path);
                $parsed = \App\Actions\Matches\ParseGameLogBinary::run($raw);

                if ($parsed && ! empty($parsed['entries'])) {
                    $gameLog->update([
                        'decoded_entries' => $parsed['entries'],
                        'decoded_at' => now(),
                        'byte_offset' => $parsed['byte_offset'],
                        'decoded_version' => \App\Actions\Matches\ParseGameLogBinary::VERSION,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::channel('pipeline')->warning("ProcessImportScan: failed to decode {$gameLog->file_path}", [
                    'error' => $e->getMessage(),
                ]);
            }

            $decoded++;

            if ($decoded % 100 === 0) {
                $scan->update(['progress' => $decoded]);
            }
        }

        // Reset progress for the matching phase
        $scan->update(['progress' => 0, 'total' => 0]);
    }

    /**
     * Build index from decoded game logs not linked to any match.
     *
     * @return array<int, array{token: string, first_timestamp: string, players: string[], entries: array}>
     */
    private function buildGameLogIndex(): array
    {
        $gameLogs = GameLog::whereDoesntHave('match')
            ->whereNotNull('decoded_entries')
            ->get();

        $index = [];

        foreach ($gameLogs as $gameLog) {
            $entries = $gameLog->decoded_entries;

            if (empty($entries)) {
                continue;
            }

            $players = ExtractGameResults::detectPlayers($entries);

            $index[] = [
                'token' => $gameLog->match_token,
                'first_timestamp' => $entries[0]['timestamp'],
                'players' => $players,
                'entries' => $entries,
            ];
        }

        return $index;
    }

    /**
     * Find a game log matching a history record by timestamp ± 5 min and opponent.
     */
    private function findMatchingLog(array $record, array $logIndex): ?array
    {
        $historyStart = Carbon::parse($record['StartTime']);
        $opponent = $record['Opponents'][0] ?? null;

        if (! $opponent) {
            return null;
        }

        foreach ($logIndex as $log) {
            $logStart = Carbon::parse($log['first_timestamp']);
            $timeDiff = abs($historyStart->diffInSeconds($logStart));

            if ($timeDiff < 300 && in_array($opponent, $log['players'])) {
                return $log;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd E:/mymtgo/client && php artisan test --compact --filter=ProcessImportScan
```

- [ ] **Step 5: Run Pint and commit**

```bash
cd E:/mymtgo/client && php vendor/bin/pint --dirty --format agent
git add app/Jobs/ProcessImportScan.php tests/Feature/Jobs/ProcessImportScanTest.php
git commit -m "feat: add ProcessImportScan background job"
```

---

## Task 7: API endpoints — scan, status, matches, import, cancel

**Files:**
- Modify: `app/Http/Controllers/Import/ScanController.php`
- Create: `app/Http/Controllers/Import/ScanStatusController.php`
- Create: `app/Http/Controllers/Import/ScanMatchesController.php`
- Create: `app/Http/Controllers/Import/ImportAllController.php`
- Create: `app/Http/Controllers/Import/CancelScanController.php`
- Modify: `app/Http/Controllers/Import/StoreController.php`
- Modify: `app/Http/Controllers/Import/IndexController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Http/Import/ScanFlowTest.php`

- [ ] **Step 1: Rewrite ScanController**

Replace `app/Http/Controllers/Import/ScanController.php`:

```php
<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessImportScan;
use App\Models\ImportScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'deck_version_id' => 'required|integer|exists:deck_versions,id',
        ]);

        // Cancel any processing scan
        ImportScan::where('status', 'processing')->update(['status' => 'cancelled']);

        // Delete previous scans and their matches (cascade)
        ImportScan::query()->delete();

        $scan = ImportScan::create([
            'deck_version_id' => $validated['deck_version_id'],
            'status' => 'processing',
        ]);

        ProcessImportScan::dispatch($scan->id);

        return response()->json(['scan_id' => $scan->id]);
    }
}
```

- [ ] **Step 2: Create ScanStatusController**

```bash
cd E:/mymtgo/client && php artisan make:controller Import/ScanStatusController --invokable --no-interaction
```

```php
<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Models\ImportScan;
use Illuminate\Http\JsonResponse;

class ScanStatusController extends Controller
{
    public function __invoke(ImportScan $scan): JsonResponse
    {
        $data = [
            'status' => $scan->status,
            'progress' => $scan->progress,
            'total' => $scan->total,
            'error' => $scan->error,
        ];

        if ($scan->isComplete()) {
            $data['matches'] = $scan->matches()
                ->orderByDesc('started_at')
                ->paginate(50)
                ->through(fn ($m) => [
                    'id' => $m->id,
                    'history_id' => $m->history_id,
                    'started_at' => $m->started_at->toIso8601String(),
                    'opponent' => $m->opponent,
                    'format' => $m->format_display,
                    'games_won' => $m->games_won,
                    'games_lost' => $m->games_lost,
                    'outcome' => $m->outcome,
                    'confidence' => $m->confidence,
                    'game_log_token' => $m->game_log_token,
                    'round' => $m->round,
                    'description' => $m->description,
                ]);
        }

        return response()->json($data);
    }
}
```

- [ ] **Step 3: Create ScanMatchesController**

```bash
cd E:/mymtgo/client && php artisan make:controller Import/ScanMatchesController --invokable --no-interaction
```

```php
<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Models\ImportScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScanMatchesController extends Controller
{
    public function __invoke(Request $request, ImportScan $scan): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 50), 100);

        $matches = $scan->matches()
            ->orderByDesc('started_at')
            ->paginate($perPage)
            ->through(fn ($m) => [
                'id' => $m->id,
                'history_id' => $m->history_id,
                'started_at' => $m->started_at->toIso8601String(),
                'opponent' => $m->opponent,
                'format' => $m->format_display,
                'games_won' => $m->games_won,
                'games_lost' => $m->games_lost,
                'outcome' => $m->outcome,
                'confidence' => $m->confidence,
                'game_log_token' => $m->game_log_token,
                'round' => $m->round,
                'description' => $m->description,
            ]);

        return response()->json($matches);
    }
}
```

- [ ] **Step 4: Create ImportAllController**

```bash
cd E:/mymtgo/client && php artisan make:controller Import/ImportAllController --invokable --no-interaction
```

```php
<?php

namespace App\Http\Controllers\Import;

use App\Actions\Import\ImportMatches;
use App\Http\Controllers\Controller;
use App\Models\ImportScan;
use Illuminate\Http\JsonResponse;

class ImportAllController extends Controller
{
    public function __invoke(ImportScan $scan): JsonResponse
    {
        $result = ImportMatches::runFromScan($scan);

        return response()->json($result);
    }
}
```

- [ ] **Step 5: Create CancelScanController**

```bash
cd E:/mymtgo/client && php artisan make:controller Import/CancelScanController --invokable --no-interaction
```

```php
<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Models\ImportScan;
use Illuminate\Http\Response;

class CancelScanController extends Controller
{
    public function __invoke(ImportScan $scan): Response
    {
        if ($scan->isProcessing()) {
            // Job checks status before each batch and stops if cancelled
            $scan->update(['status' => 'cancelled']);
        } else {
            // Completed/failed/already cancelled — delete immediately
            $scan->delete(); // cascadeOnDelete removes import_scan_matches
        }

        return response()->noContent();
    }
}
```

- [ ] **Step 6: Update StoreController**

Rewrite to accept `history_ids` and read from scan:

```php
<?php

namespace App\Http\Controllers\Import;

use App\Actions\Import\ImportMatches;
use App\Http\Controllers\Controller;
use App\Models\ImportScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public function __invoke(Request $request, ImportScan $scan): JsonResponse
    {
        $validated = $request->validate([
            'history_ids' => 'required|array|min:1',
            'history_ids.*' => 'required|integer',
        ]);

        $result = ImportMatches::runFromScan($scan, $validated['history_ids']);

        return response()->json($result);
    }
}
```

- [ ] **Step 7: Update IndexController**

Pass existing scan data if available:

```php
<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Models\DeckVersion;
use App\Models\ImportScan;
use App\Models\MtgoMatch;
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

        $importedCount = MtgoMatch::where('imported', true)->count();

        $existingScan = ImportScan::latest()->first();

        return Inertia::render('import/Index', [
            'deckVersions' => $deckVersions,
            'importedCount' => $importedCount,
            'existingScan' => $existingScan ? [
                'id' => $existingScan->id,
                'deck_version_id' => $existingScan->deck_version_id,
                'status' => $existingScan->status,
                'progress' => $existingScan->progress,
                'total' => $existingScan->total,
                'match_count' => $existingScan->isComplete() ? $existingScan->matches()->count() : 0,
            ] : null,
        ]);
    }
}
```

- [ ] **Step 8: Update routes**

In `routes/web.php`, replace the import route group (lines 134-141):

```php
$router->group([
    'prefix' => 'import',
], function (Router $group) {
    $group->get('/', ImportIndexController::class)->name('import.index');
    $group->post('scan', ScanController::class)->name('import.scan');
    $group->get('scan/{scan}', ScanStatusController::class)->name('import.scan.status');
    $group->get('scan/{scan}/matches', ScanMatchesController::class)->name('import.scan.matches');
    $group->post('scan/{scan}/import', StoreController::class)->name('import.store');
    $group->post('scan/{scan}/import-all', ImportAllController::class)->name('import.import-all');
    $group->delete('scan/{scan}', CancelScanController::class)->name('import.scan.cancel');
    $group->delete('/', ImportDestroyController::class)->name('import.destroy');
});
```

Add the new controller imports at the top of `routes/web.php`.

- [ ] **Step 9: Write integration tests**

Create `tests/Feature/Http/Import/ScanFlowTest.php` with tests for:
- POST /import/scan creates scan and dispatches job
- POST /import/scan requires valid deck_version_id
- GET /import/scan/{id} returns status with progress/total
- GET /import/scan/{id} includes paginated matches when complete
- GET /import/scan/{id}/matches returns paginated results with correct fields
- POST /import/scan/{id}/import imports selected matches and returns count
- POST /import/scan/{id}/import-all imports all scan matches
- DELETE /import/scan/{id} on processing scan sets status to cancelled
- DELETE /import/scan/{id} on completed scan deletes the scan and its matches
- Starting a new scan cancels processing scans and deletes old scans

**Note:** The old `POST /import/` route (`import.store`) is removed. Search for any existing tests referencing the old route and update or remove them.

- [ ] **Step 10: Run tests**

```bash
cd E:/mymtgo/client && php artisan test --compact --filter=ScanFlow
```

- [ ] **Step 11: Run Pint and commit**

```bash
cd E:/mymtgo/client && php vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Import/ routes/web.php tests/Feature/Http/Import/
git commit -m "feat: add scan API endpoints with status polling and pagination"
```

---

## Task 8: Adapt ImportMatches for scan-based flow

**Files:**
- Modify: `app/Actions/Import/ImportMatches.php`
- Test: update existing tests or add new ones

- [ ] **Step 1: Add runFromScan() method**

Add a new static method `runFromScan(ImportScan $scan, ?array $historyIds = null)` to `ImportMatches`. This:
- Queries `ImportScanMatch` rows (filtered by `$historyIds` if provided, otherwise all)
- Gets `deck_version_id` from the scan
- For each match, reads `game_log_token` → loads `GameLog.decoded_entries` → builds games using `ExtractGameResults::run()` and `ExtractCardsFromGameLog::run()`
- Creates `MtgoMatch`, `Game`, player pivots, card stats, dispatches archetype job
- Returns `['imported' => $count, 'skipped' => $skipped]`

The existing `run()` method stays for backwards compatibility until the old frontend is replaced, then can be removed.

- [ ] **Step 2: Write test for runFromScan()**

Test that given an ImportScan with ImportScanMatch rows and GameLog records with decoded_entries, `runFromScan()` creates the correct MtgoMatch, Game, and player records.

- [ ] **Step 3: Run tests**

```bash
cd E:/mymtgo/client && php artisan test --compact --filter=ImportMatches
```

- [ ] **Step 4: Run Pint and commit**

```bash
cd E:/mymtgo/client && php vendor/bin/pint --dirty --format agent
git add app/Actions/Import/ImportMatches.php tests/
git commit -m "feat: add runFromScan() to ImportMatches for scan-based import"
```

---

## Task 9: Frontend — rewrite import page

**Files:**
- Modify: `resources/js/pages/import/Index.vue`

Reference the @inertia-vue-development, @tailwindcss-development, and @wayfinder-development skills.

- [ ] **Step 1: Rewrite Index.vue**

Replace the entire file with the new deck-first UX. The page has three states:

**State 1: Setup**
- Props: `deckVersions`, `importedCount`, `existingScan`
- Deck dropdown (optgroups: active/deleted)
- "Load Matches" button (disabled until deck selected)
- If `existingScan` exists and its `deck_version_id` matches selected deck and status is `complete`, show "View previous results (X matches)" link
- Warning banner (keep existing)
- Imported count + delete all (keep existing)

**State 2: Processing**
- After POST to ScanController, store scan_id
- Poll GET ScanStatusController every 2 seconds
- Show progress bar: `progress / total`
- Cancel button → DELETE CancelScanController

**State 3: Results**
- Summary bar with total, high confidence (>=0.6), low confidence counts
- Format filter dropdown
- Paginated table (server-side pagination via ScanMatchesController):
  - Checkbox, Date (DD/MM/YYYY), Opponent, Format, Result (W/L badges or score), Confidence %
- Per-page "Select All" checkbox
- Page navigation (prev/next)
- Sticky bottom bar when selections exist:
  - Selected count
  - "Import Selected" → POST StoreController with history_ids
  - "Import All X Matches" → confirmation dialog → POST ImportAllController

Use Wayfinder imports for all controller URLs. Use existing UI components (`Button`, `Card`, `Badge`, `Dialog`, `Spinner`).

- [ ] **Step 2: Run npm build to verify no errors**

```bash
cd E:/mymtgo/client && npm run build
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/import/Index.vue
git commit -m "feat: rewrite import page with deck-first UX and pagination"
```

---

## Task 10: Clean up removed code

**Files:**
- Delete: `app/Actions/Import/SuggestDeckForMatch.php`
- Delete: `app/Actions/Import/ParseImportableMatches.php`
- Modify: `app/Actions/Import/MatchGameLogToHistory.php` (simplify — remove file fallback if any remains)
- Update/remove: `tests/Feature/Actions/Import/MatchGameLogToHistoryTest.php`

- [ ] **Step 1: Delete SuggestDeckForMatch**

```bash
rm app/Actions/Import/SuggestDeckForMatch.php
```

- [ ] **Step 2: Delete ParseImportableMatches**

```bash
rm app/Actions/Import/ParseImportableMatches.php
```

- [ ] **Step 3: Search for remaining references**

```bash
cd E:/mymtgo/client && grep -r "SuggestDeckForMatch\|ParseImportableMatches" app/ tests/ --include="*.php" -l
```

Fix any remaining references (update imports, remove dead calls).

- [ ] **Step 4: Simplify MatchGameLogToHistory**

Verify the class only uses DB queries (no file system access). If it's now only used by `ProcessImportScan`, consider whether the matching logic should be inlined into the job or kept separate. Keep it separate if the tests are valuable.

- [ ] **Step 5: Run full test suite**

```bash
cd E:/mymtgo/client && php artisan test --compact
```

Fix any failures from removed code.

- [ ] **Step 6: Run Pint and commit**

```bash
cd E:/mymtgo/client && php vendor/bin/pint --dirty --format agent
git add -A
git commit -m "chore: remove SuggestDeckForMatch and ParseImportableMatches"
```

---

## Task 11: Wayfinder regeneration and final verification

- [ ] **Step 1: Rebuild frontend to regenerate Wayfinder types**

```bash
cd E:/mymtgo/client && npm run build
```

Verify no TypeScript errors. Wayfinder should auto-generate types for the new controllers.

- [ ] **Step 2: Run full test suite**

```bash
cd E:/mymtgo/client && php artisan test --compact
```

- [ ] **Step 3: Run Pint on all PHP files**

```bash
cd E:/mymtgo/client && php vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Final commit if needed**

```bash
git add -A
git commit -m "chore: final cleanup and verification"
```
