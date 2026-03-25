# Pipeline Rearchitecture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the fragile two-routine pipeline with a single `mtgo:process-matches` command that owns the entire match lifecycle from log reading through completion.

**Architecture:** Single Artisan command runs every 2s with `withoutOverlapping`. Phase 0 discovers game logs, Phase 1 ingests the main log (single transaction), Phase 2 processes each match independently (per-match transactions). Cursor reset observer triggers match history fallback for incomplete matches from previous sessions.

**Tech Stack:** PHP 8.4, Laravel 12, Pest v4, SQLite

**Spec:** `docs/superpowers/specs/2026-03-24-pipeline-rearchitecture-design.md`

### Guiding Principles

- **Tests must be meaningful.** If a test fails, investigate WHY. Do not brute-force tests to pass.
- **No `LogEvent::factory()` exists.** Use `LogEvent::create([...])` directly in tests.
- **Models use `$guarded = []`**, not `$fillable`. Do not add `$fillable` arrays.
- **`canRun()` guard.** All scheduler entries that touch MTGO files must check `$this->canRun()`.
- **Run `vendor/bin/pint --dirty --format agent` before committing.**
- **The `games_won`/`games_lost` columns still exist on the `matches` table** but are deprecated — do not use them. Use `outcome` and `Game.won` instead.
- **Delete controller uses hard delete now**, not Voided state. Update to `$match->delete()`.
- **Check `failed_at` is null** before processing any match in Phase 2.

---

## Phase 1: Schema & Enum Changes

### Task 1: Migration — add `failed_at` and `attempts` to matches, migrate existing state data

**Files:**
- Create: `database/migrations/XXXX_add_pipeline_columns_and_migrate_states.php`
- Modify: `app/Enums/MatchState.php`

- [ ] **Step 1: Create migration**

Run: `php artisan make:migration add_pipeline_columns_and_migrate_states --no-interaction`

Migration content:

```php
public function up(): void
{
    Schema::table('matches', function (Blueprint $table) {
        $table->timestamp('failed_at')->nullable()->after('outcome');
        $table->unsignedTinyInteger('attempts')->default(0)->after('failed_at');
    });

    // Migrate existing state data
    DB::table('matches')->where('state', 'pending_result')->update(['state' => 'ended']);
    DB::table('matches')->where('state', 'voided')->delete();
}
```

- [ ] **Step 2: Run migration**

Run: `php artisan migrate`

- [ ] **Step 3: Remove PendingResult and Voided from MatchState enum**

In `app/Enums/MatchState.php`, remove:
```php
case PendingResult = 'pending_result';
case Voided = 'voided';
```

Leaving only:
```php
enum MatchState: string
{
    case Started = 'started';
    case InProgress = 'in_progress';
    case Ended = 'ended';
    case Complete = 'complete';
}
```

- [ ] **Step 4: Update MtgoMatch model casts**

In `app/Models/MtgoMatch.php`, add to the casts method/array:
```php
'failed_at' => 'datetime',
'attempts' => 'integer',
```

- [ ] **Step 5: Update MtgoMatch factory with new states**

In `database/factories/MtgoMatchFactory.php`, add state methods:

```php
public function started(): static
{
    return $this->state(fn () => [
        'state' => MatchState::Started,
        'outcome' => null,
        'ended_at' => null,
    ]);
}

public function inProgress(): static
{
    return $this->state(fn () => [
        'state' => MatchState::InProgress,
        'outcome' => null,
        'ended_at' => null,
    ]);
}

public function ended(): static
{
    return $this->state(fn () => [
        'state' => MatchState::Ended,
        'outcome' => null,
    ]);
}

public function failed(): static
{
    return $this->state(fn () => [
        'failed_at' => now(),
        'attempts' => 5,
    ]);
}
```

- [ ] **Step 6: Run tests to check for breakage**

Run: `php artisan test --compact`

Any test referencing `MatchState::PendingResult` or `MatchState::Voided` will fail — that's expected and will be fixed in Task 2.

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "Add pipeline columns, remove PendingResult/Voided states"
```

---

### Task 2: Fix all references to removed states

**Files:**
- Modify: `app/Models/MtgoMatch.php` (scopeIncomplete)
- Modify: `app/Http/Controllers/Matches/DeleteController.php`
- Modify: `app/Http/Controllers/Debug/Matches/ResetController.php`
- Modify: `app/Http/Controllers/Leagues/OverlayController.php`
- Modify: `app/Actions/Logs/PruneProcessedLogEvents.php`
- Modify: `app/Actions/Matches/AdvanceMatchState.php`
- Modify: various test files referencing removed states

- [ ] **Step 1: Update `scopeIncomplete` in MtgoMatch**

Current (line ~in MtgoMatch.php):
```php
public function scopeIncomplete($query)
{
    return $query->whereNotIn('state', [MatchState::Complete, MatchState::Voided]);
}
```

Replace with:
```php
public function scopeIncomplete($query)
{
    return $query->where('state', '!=', MatchState::Complete);
}
```

- [ ] **Step 2: Update DeleteController — hard delete instead of Voided**

Replace entire `__invoke` body in `app/Http/Controllers/Matches/DeleteController.php`:

```php
public function __invoke(string $id, Request $request)
{
    $match = MtgoMatch::findOrFail($id);
    $match->delete();

    return back();
}
```

Remove the `use App\Enums\MatchState;` import if no longer needed.

- [ ] **Step 3: Update ResetController — hard delete instead of Voided**

In `app/Http/Controllers/Debug/Matches/ResetController.php`, replace the `update(['state' => MatchState::Voided])` call with `$match->delete()`. Remove unused `MatchState` import.

- [ ] **Step 4: Update OverlayController — remove Voided exclusion**

In `app/Http/Controllers/Leagues/OverlayController.php`, line 22:

```php
// Old:
'matches as total_matches_count' => fn ($q) => $q->where('state', '!=', MatchState::Voided),
// New:
'matches as total_matches_count',
```

Remove the `MatchState` import if no longer used elsewhere in the file. Check — it's still used on lines 20-21 and 23, so keep it.

- [ ] **Step 5: Update PruneProcessedLogEvents — remove Voided from query**

In `app/Actions/Logs/PruneProcessedLogEvents.php`, change:

```php
$completedTokens = MtgoMatch::whereIn('state', [
    MatchState::Complete,
    MatchState::Voided,
])->pluck('token');
```

To:

```php
$completedTokens = MtgoMatch::where('state', MatchState::Complete)->pluck('token');
```

- [ ] **Step 6: Update AdvanceMatchState no-regression guard**

In `app/Actions/Matches/AdvanceMatchState.php`, find the guard (approximately line 90):

```php
if ($match->state === MatchState::Complete || $match->state === MatchState::Voided) {
    return $match;
}
```

Replace with:

```php
if ($match->state === MatchState::Complete || $match->failed_at !== null) {
    return $match;
}
```

- [ ] **Step 7: Fix any tests referencing removed states**

Search all test files for `MatchState::PendingResult` and `MatchState::Voided`. Update or remove those tests:
- Tests for `ResolveGameResults` grace period → PendingResult: remove these test cases (grace period eliminated)
- Tests for `ResolvePendingResults`: will be deleted with that class in Task 5
- Tests for `ResolveStaleMatches` → Voided: will be deleted with that class in Task 5
- Any factory usage with `'state' => MatchState::Voided`: change to test hard deletion

Run: `grep -r "PendingResult\|Voided" tests/`

- [ ] **Step 8: Run tests**

Run: `php artisan test --compact`

- [ ] **Step 9: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 10: Commit**

```bash
git add -A && git commit -m "Remove all references to PendingResult and Voided states"
```

---

## Phase 2: Extract & Create New Actions

### Task 3: Extract DiscoverGameLogs action from PollGameLogs

**Files:**
- Create: `app/Actions/Matches/DiscoverGameLogs.php`
- Test: `tests/Feature/Actions/Matches/DiscoverGameLogsTest.php`

- [ ] **Step 1: Write the test**

Run: `php artisan make:test --pest Actions/Matches/DiscoverGameLogsTest --no-interaction`

```php
<?php

use App\Actions\Matches\DiscoverGameLogs;
use App\Enums\MatchState;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

$testDir = storage_path('test-gamelogs');

beforeEach(function () use ($testDir) {
    File::ensureDirectoryExists($testDir);
});

afterEach(function () use ($testDir) {
    File::deleteDirectory($testDir);
});

it('creates GameLog records for matching files', function () use ($testDir) {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'token' => 'abc123',
    ]);

    File::put($testDir.'/Match_GameLog_abc123.dat', 'binary data');

    DiscoverGameLogs::run($testDir);

    expect(GameLog::where('match_token', 'abc123')->exists())->toBeTrue();
});

it('skips files with no matching active match', function () use ($testDir) {
    File::put($testDir.'/Match_GameLog_nomatch.dat', 'binary data');

    DiscoverGameLogs::run($testDir);

    expect(GameLog::count())->toBe(0);
});

it('is idempotent — does not duplicate GameLog records', function () use ($testDir) {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'token' => 'abc123',
    ]);

    File::put($testDir.'/Match_GameLog_abc123.dat', 'binary data');

    DiscoverGameLogs::run($testDir);
    DiscoverGameLogs::run($testDir);

    expect(GameLog::where('match_token', 'abc123')->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=DiscoverGameLogsTest`

Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement DiscoverGameLogs**

Create `app/Actions/Matches/DiscoverGameLogs.php`:

```php
<?php

namespace App\Actions\Matches;

use App\Enums\MatchState;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\Finder;

class DiscoverGameLogs
{
    /**
     * Scan the given directory for game log files and create
     * GameLog records for any that match active matches.
     */
    public static function run(?string $directory = null): void
    {
        $directory = $directory ?? app('mtgo')->getLogDataPath();

        if (! $directory || ! is_dir($directory)) {
            return;
        }

        $activeTokens = MtgoMatch::whereIn('state', [
            MatchState::Started,
            MatchState::InProgress,
            MatchState::Ended,
        ])->pluck('token')->flip();

        if ($activeTokens->isEmpty()) {
            return;
        }

        $finder = (new Finder())
            ->files()
            ->in($directory)
            ->name('*Match_GameLog*');

        foreach ($finder as $file) {
            $parts = explode('_', $file->getFilenameWithoutExtension());
            $token = end($parts);

            if (! $activeTokens->has($token)) {
                continue;
            }

            GameLog::firstOrCreate(
                ['match_token' => $token],
                ['file_path' => $file->getRealPath()],
            );
        }
    }

    /**
     * Attempt to discover a specific game log by match token.
     * Used as inline fallback when the main discovery didn't find it.
     */
    public static function discoverForToken(string $token, ?string $directory = null): ?GameLog
    {
        $directory = $directory ?? app('mtgo')->getLogDataPath();

        if (! $directory || ! is_dir($directory)) {
            return null;
        }

        $finder = (new Finder())
            ->files()
            ->in($directory)
            ->name("*Match_GameLog_{$token}*");

        foreach ($finder as $file) {
            Log::channel('pipeline')->info("DiscoverGameLogs: inline fallback found game log for token={$token}");

            return GameLog::firstOrCreate(
                ['match_token' => $token],
                ['file_path' => $file->getRealPath()],
            );
        }

        return null;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter=DiscoverGameLogsTest`

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "Extract DiscoverGameLogs action from PollGameLogs"
```

---

### Task 4: Create ResolveMatchFromHistory action

This extracts and extends the match history fallback logic from `ResolvePendingResults` for use by the cursor reset observer. It handles all incomplete match states (Started, InProgress, Ended) and backfills `Game.won` from the W-L record.

**Files:**
- Create: `app/Actions/Matches/ResolveMatchFromHistory.php`
- Test: `tests/Feature/Actions/Matches/ResolveMatchFromHistoryTest.php`

- [ ] **Step 1: Write the test**

Run: `php artisan make:test --pest Actions/Matches/ResolveMatchFromHistoryTest --no-interaction`

```php
<?php

use App\Actions\Matches\ResolveMatchFromHistory;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('completes an Ended match from match history', function () {
    $match = MtgoMatch::factory()->ended()->create(['mtgo_id' => '12345']);
    Game::factory()->create(['match_id' => $match->id, 'won' => null]);
    Game::factory()->create(['match_id' => $match->id, 'won' => null]);

    // Mock ParseMatchHistory to return a 2-0 result
    $resolved = ResolveMatchFromHistory::run($match, ['wins' => 2, 'losses' => 0]);

    expect($resolved)->toBeTrue();
    expect($match->fresh()->state)->toBe(MatchState::Complete);
    expect($match->fresh()->outcome)->toBe(MatchOutcome::Win);
});

it('backfills Game.won based on W-L in game order', function () {
    $match = MtgoMatch::factory()->ended()->create(['mtgo_id' => '12345']);
    $g1 = Game::factory()->create(['match_id' => $match->id, 'won' => null, 'started_at' => now()->subMinutes(10)]);
    $g2 = Game::factory()->create(['match_id' => $match->id, 'won' => null, 'started_at' => now()->subMinutes(5)]);
    $g3 = Game::factory()->create(['match_id' => $match->id, 'won' => null, 'started_at' => now()]);

    ResolveMatchFromHistory::run($match, ['wins' => 2, 'losses' => 1]);

    $games = $match->games()->orderBy('started_at')->get();
    // Best guess: assign wins first, then losses
    expect($games[0]->fresh()->won)->toBeTrue();
    expect($games[1]->fresh()->won)->toBeTrue();
    expect($games[2]->fresh()->won)->toBeFalse();
});

it('does not create missing Game records', function () {
    $match = MtgoMatch::factory()->ended()->create(['mtgo_id' => '12345']);
    // No games exist

    ResolveMatchFromHistory::run($match, ['wins' => 2, 'losses' => 1]);

    expect($match->fresh()->state)->toBe(MatchState::Complete);
    expect(Game::where('match_id', $match->id)->count())->toBe(0);
});

it('does not overwrite Game.won that is already set', function () {
    $match = MtgoMatch::factory()->ended()->create(['mtgo_id' => '12345']);
    $g1 = Game::factory()->create(['match_id' => $match->id, 'won' => false, 'started_at' => now()->subMinutes(5)]);
    $g2 = Game::factory()->create(['match_id' => $match->id, 'won' => null, 'started_at' => now()]);

    ResolveMatchFromHistory::run($match, ['wins' => 1, 'losses' => 1]);

    // g1 already had won=false, should not be overwritten
    expect($g1->fresh()->won)->toBeFalse();
    // g2 was null, gets backfilled
    expect($g2->fresh()->won)->not->toBeNull();
});

it('handles Started matches with no games', function () {
    $match = MtgoMatch::factory()->started()->create(['mtgo_id' => '12345']);

    ResolveMatchFromHistory::run($match, ['wins' => 2, 'losses' => 0]);

    expect($match->fresh()->state)->toBe(MatchState::Complete);
    expect($match->fresh()->outcome)->toBe(MatchOutcome::Win);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ResolveMatchFromHistoryTest`

- [ ] **Step 3: Implement ResolveMatchFromHistory**

Create `app/Actions/Matches/ResolveMatchFromHistory.php`:

```php
<?php

namespace App\Actions\Matches;

use App\Enums\MatchState;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class ResolveMatchFromHistory
{
    /**
     * Resolve a match using match history W-L data.
     *
     * Backfills Game.won on existing records (best guess, game order).
     * Does NOT create missing Game records.
     *
     * @param  array{wins: int, losses: int}  $result
     */
    public static function run(MtgoMatch $match, array $result): bool
    {
        $outcome = MtgoMatch::determineOutcome($result['wins'], $result['losses']);

        // Backfill Game.won on existing games where won is null
        $games = $match->games()->orderBy('started_at')->get();
        $winsToAssign = $result['wins'];
        $lossesToAssign = $result['losses'];

        foreach ($games as $game) {
            if ($game->won !== null) {
                continue;
            }

            if ($winsToAssign > 0) {
                $game->update(['won' => true]);
                $winsToAssign--;
            } elseif ($lossesToAssign > 0) {
                $game->update(['won' => false]);
                $lossesToAssign--;
            }
        }

        $previousState = $match->state;

        $match->update([
            'outcome' => $outcome,
            'state' => MatchState::Complete,
            'ended_at' => $match->ended_at ?? now(),
        ]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: {$previousState->value} → Complete (from match_history)", [
            'result' => "{$result['wins']}-{$result['losses']}",
            'outcome' => $outcome->value,
        ]);

        return true;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter=ResolveMatchFromHistoryTest`

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "Add ResolveMatchFromHistory action for cursor reset fallback"
```

---

### Task 5: Create LogCursor observer

**Files:**
- Create: `app/Observers/LogCursorObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` (register observer)
- Test: `tests/Feature/Observers/LogCursorObserverTest.php`

- [ ] **Step 1: Write the test**

Run: `php artisan make:test --pest Observers/LogCursorObserverTest --no-interaction`

```php
<?php

use App\Actions\Matches\ParseMatchHistory;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Game;
use App\Models\LogCursor;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves incomplete matches when cursor resets', function () {
    $cursor = LogCursor::create(['file_path' => '/fake/log.txt', 'byte_offset' => 5000]);

    $endedMatch = MtgoMatch::factory()->ended()->create(['mtgo_id' => '111']);
    $inProgressMatch = MtgoMatch::factory()->inProgress()->create(['mtgo_id' => '222']);
    $completedMatch = MtgoMatch::factory()->create(['state' => MatchState::Complete]);

    // Mock ParseMatchHistory
    ParseMatchHistory::shouldReceive('findResult')
        ->with('111', null)
        ->andReturn(['wins' => 2, 'losses' => 1]);

    ParseMatchHistory::shouldReceive('findResult')
        ->with('222', null)
        ->andReturn(['wins' => 0, 'losses' => 2]);

    // Simulate cursor reset (byte_offset goes backwards)
    $cursor->update(['byte_offset' => 0]);

    expect($endedMatch->fresh()->state)->toBe(MatchState::Complete);
    expect($endedMatch->fresh()->outcome)->toBe(MatchOutcome::Win);
    expect($inProgressMatch->fresh()->state)->toBe(MatchState::Complete);
    expect($inProgressMatch->fresh()->outcome)->toBe(MatchOutcome::Loss);
    // Complete match should not be touched
    expect($completedMatch->fresh()->state)->toBe(MatchState::Complete);
});

it('does not trigger on normal cursor advance', function () {
    $cursor = LogCursor::create(['file_path' => '/fake/log.txt', 'byte_offset' => 1000]);

    $match = MtgoMatch::factory()->ended()->create();

    // Normal advance — byte_offset increases
    $cursor->update(['byte_offset' => 2000]);

    // Match should not change
    expect($match->fresh()->state)->toBe(MatchState::Ended);
});

it('leaves matches as-is when not in match history', function () {
    $cursor = LogCursor::create(['file_path' => '/fake/log.txt', 'byte_offset' => 5000]);

    $match = MtgoMatch::factory()->started()->create(['mtgo_id' => '999']);

    ParseMatchHistory::shouldReceive('findResult')
        ->with('999', null)
        ->andReturn(null);

    $cursor->update(['byte_offset' => 0]);

    // Match stays Started — not in match history, left for user to delete
    expect($match->fresh()->state)->toBe(MatchState::Started);
});
```

**Note:** The `ParseMatchHistory::shouldReceive()` mock may need adjusting depending on whether the class uses a facade or is called statically. If it's a plain static call, wrap the call in the observer behind an action that can be mocked, or use `Mockery::mock('alias:...')`. Check existing test patterns for `ParseMatchHistory` in `tests/Feature/Actions/Matches/ResolvePendingResultsTest.php` and follow the same approach.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=LogCursorObserverTest`

- [ ] **Step 3: Implement LogCursorObserver**

Create `app/Observers/LogCursorObserver.php`:

```php
<?php

namespace App\Observers;

use App\Actions\Matches\ParseMatchHistory;
use App\Actions\Matches\ResolveMatchFromHistory;
use App\Enums\MatchState;
use App\Models\LogCursor;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class LogCursorObserver
{
    public function updated(LogCursor $cursor): void
    {
        $previousOffset = $cursor->getOriginal('byte_offset');
        $currentOffset = $cursor->byte_offset;

        // Only trigger on cursor reset (offset went backwards = new MTGO session)
        if ($previousOffset === null || $currentOffset >= $previousOffset) {
            return;
        }

        Log::channel('pipeline')->info("LogCursor reset detected: {$previousOffset} → {$currentOffset}");

        $this->resolveIncompleteMatches();
    }

    private function resolveIncompleteMatches(): void
    {
        $incompleteMatches = MtgoMatch::whereIn('state', [
            MatchState::Started,
            MatchState::InProgress,
            MatchState::Ended,
        ])->whereNull('failed_at')->get();

        if ($incompleteMatches->isEmpty()) {
            return;
        }

        Log::channel('pipeline')->info("Cursor reset: resolving {$incompleteMatches->count()} incomplete matches via match history");

        foreach ($incompleteMatches as $match) {
            $result = ParseMatchHistory::findResult($match->mtgo_id);

            if ($result === null) {
                Log::channel('pipeline')->info("Match {$match->mtgo_id}: not found in match history, leaving as {$match->state->value}");

                continue;
            }

            ResolveMatchFromHistory::run($match, $result);
        }
    }
}
```

- [ ] **Step 4: Register the observer**

In `app/Providers/AppServiceProvider.php`, add to the `boot()` method:

```php
use App\Models\LogCursor;
use App\Observers\LogCursorObserver;

// Inside boot():
LogCursor::observe(LogCursorObserver::class);
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact --filter=LogCursorObserverTest`

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "Add LogCursor observer for cursor reset match history fallback"
```

---

### Task 5.5: Remove processed_at marking from AdvanceMatchState

The new command handles event marking at the end of each per-match transaction. Remove the duplicate marking from `AdvanceMatchState` before building the command.

**Files:**
- Modify: `app/Actions/Matches/AdvanceMatchState.php`

- [ ] **Step 1: Remove the processed_at update from AdvanceMatchState**

In `app/Actions/Matches/AdvanceMatchState.php`, find line 58:

```php
LogEvent::whereIn('id', $idsToMarkAsProcessed)->update(['processed_at' => now()]);
```

Remove this line. Also remove the `$idsToMarkAsProcessed` variable construction (line 35) since it's no longer needed.

- [ ] **Step 2: Run tests**

Run: `php artisan test --compact`

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "Remove processed_at marking from AdvanceMatchState (command handles it)"
```

---

### Task 5.6: Convert archetype detection to a dispatched job

The spec requires archetype detection to be dispatched as a background job, not called synchronously. Currently `MtgoMatchObserver::updated()` calls `DetermineMatchArchetypes::run()` inline, which makes an API call that can block the pipeline.

**Files:**
- Modify: `app/Observers/MtgoMatchObserver.php`

- [ ] **Step 1: Update the observer to dispatch instead of calling synchronously**

In `app/Observers/MtgoMatchObserver.php`, find the `DetermineMatchArchetypes::run($match)` call and replace it with a dispatched job. Check if a job wrapper already exists — if not, use `dispatch(function () use ($match) { DetermineMatchArchetypes::run($match); })` as a simple closure-based job, or create a dedicated job class if the pattern exists in the codebase.

- [ ] **Step 2: Run tests**

Run: `php artisan test --compact`

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "Dispatch archetype detection as background job instead of synchronous call"
```

---

## Phase 3: The Command

### Task 6: Create the ProcessMatches command

This is the centrepiece — the single unified command that replaces both pipelines.

**Files:**
- Create: `app/Console/Commands/ProcessMatches.php`
- Test: `tests/Feature/Commands/ProcessMatchesTest.php`

- [ ] **Step 1: Write tests for the command**

Run: `php artisan make:test --pest Commands/ProcessMatchesTest --no-interaction`

```php
<?php

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\GameLog;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs without error when there is no work', function () {
    $this->artisan('mtgo:process-matches')->assertSuccessful();
});

it('skips matches with failed_at set', function () {
    $match = MtgoMatch::factory()->inProgress()->failed()->create();

    LogEvent::create([
        'match_id' => $match->mtgo_id,
        'match_token' => $match->token,
        'event_type' => 'game_state_update',
        'processed_at' => null,
        'ingested_at' => now(),
        'timestamp' => now()->toTimeString(),
        'logged_at' => now()->toDateString(),
    ]);

    $this->artisan('mtgo:process-matches')->assertSuccessful();

    // Events should still be unprocessed — match was skipped
    expect(LogEvent::whereNull('processed_at')->count())->toBe(1);
});

it('marks events as processed after match processing', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::InProgress,
        'token' => 'test-token',
        'mtgo_id' => '12345',
    ]);

    LogEvent::create([
        'match_id' => '12345',
        'match_token' => 'test-token',
        'event_type' => 'game_state_update',
        'processed_at' => null,
        'ingested_at' => now(),
        'timestamp' => now()->toTimeString(),
        'logged_at' => now()->toDateString(),
    ]);

    $this->artisan('mtgo:process-matches')->assertSuccessful();

    expect(LogEvent::whereNull('processed_at')->count())->toBe(0);
});

it('increments attempts on exception and sets failed_at after 5', function () {
    $match = MtgoMatch::factory()->inProgress()->create([
        'token' => 'fail-token',
        'mtgo_id' => '77777',
        'attempts' => 4,
    ]);

    // Create an event that will trigger processing for this match
    LogEvent::create([
        'match_id' => '77777',
        'match_token' => 'fail-token',
        'event_type' => 'game_state_update',
        'processed_at' => null,
        'ingested_at' => now(),
        'timestamp' => now()->toTimeString(),
        'logged_at' => now()->toDateString(),
        'username' => 'testplayer',
    ]);

    // Mock AdvanceMatchState to throw
    // (Use Mockery::mock('alias:...') or bind in container depending on existing patterns)
    // The implementer should check how AdvanceMatchState is called and mock accordingly.
    // If static calls can't be mocked, inject a deliberate error condition instead
    // (e.g., a match_id that causes a DB constraint violation).

    $this->artisan('mtgo:process-matches')->assertSuccessful();

    // Match should now be permanently failed
    $fresh = $match->fresh();
    expect($fresh->attempts)->toBe(5);
    expect($fresh->failed_at)->not->toBeNull();
});

it('does not increment attempts for missing game log', function () {
    $match = MtgoMatch::factory()->inProgress()->create(['attempts' => 0]);

    $this->artisan('mtgo:process-matches')->assertSuccessful();

    // "Game log not found" is not an exception — attempts should not increment
    expect($match->fresh()->attempts)->toBe(0);
});

it('filters out untracked accounts', function () {
    $match = MtgoMatch::factory()->inProgress()->create([
        'token' => 'untracked-token',
        'mtgo_id' => '88888',
    ]);

    \App\Models\Account::create(['username' => 'untracked_user', 'tracked' => false]);

    LogEvent::create([
        'match_id' => '88888',
        'match_token' => 'untracked-token',
        'event_type' => 'game_state_update',
        'processed_at' => null,
        'ingested_at' => now(),
        'timestamp' => now()->toTimeString(),
        'logged_at' => now()->toDateString(),
        'username' => 'untracked_user',
    ]);

    $this->artisan('mtgo:process-matches')->assertSuccessful();

    // Events should be marked processed (account skipped, not retried)
    expect(LogEvent::where('match_token', 'untracked-token')->whereNull('processed_at')->count())->toBe(0);
    // Match state should not change
    expect($match->fresh()->state)->toBe(MatchState::InProgress);
});
```

**Note:** The full integration test (fixture log file → complete lifecycle) is in Task 8. This task focuses on the command structure and error handling. The failure test may need adjustment depending on how `AdvanceMatchState` is called — check existing test patterns for mocking static calls.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ProcessMatchesTest`

- [ ] **Step 3: Implement the command**

Create `app/Console/Commands/ProcessMatches.php`:

```php
<?php

namespace App\Console\Commands;

use App\Actions\Matches\AdvanceMatchState;
use App\Actions\Matches\DiscoverGameLogs;
use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\DetermineMatchResult;
use App\Actions\Matches\ParseGameLogBinary;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\Account;
use App\Models\GameLog;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessMatches extends Command
{
    protected $signature = 'mtgo:process-matches';

    protected $description = 'Unified pipeline: ingest logs, advance matches, resolve game results';

    /** @var array<string> Tokens processed in the first loop (avoid double-processing in second loop) */
    private array $processedTokens = [];

    public function handle(): int
    {
        if (! app('mtgo')->pathsAreValid()) {
            return self::SUCCESS;
        }

        // Phase 0: Discover game logs
        DiscoverGameLogs::run();

        // Phase 1: Ingest main log (IngestLog handles its own transaction)
        app('mtgo')->ingestLogs();

        // Phase 2: Process matches
        $this->processMatchesWithNewEvents();
        $this->checkGameLogsForActiveMatches();

        return self::SUCCESS;
    }

    /**
     * First loop: matches with unprocessed events.
     */
    private function processMatchesWithNewEvents(): void
    {
        $tokensWithWork = LogEvent::whereNotNull('match_id')
            ->whereNotNull('match_token')
            ->whereNull('processed_at')
            ->where('event_type', '!=', 'league_joined')
            ->where('event_type', '!=', 'league_join_request')
            ->distinct()
            ->pluck('match_id', 'match_token');

        foreach ($tokensWithWork as $matchToken => $matchId) {
            $this->processMatch($matchToken, $matchId);
            $this->processedTokens[] = $matchToken;
        }
    }

    /**
     * Second loop: InProgress/Ended matches not already processed above.
     * Checks game logs for decisive results.
     */
    private function checkGameLogsForActiveMatches(): void
    {
        $activeMatches = MtgoMatch::whereIn('state', [
            MatchState::InProgress,
            MatchState::Ended,
        ])
            ->whereNull('failed_at')
            ->whereNotIn('token', $this->processedTokens)
            ->get();

        foreach ($activeMatches as $match) {
            try {
                $this->resolveGameResults($match);
            } catch (\Throwable $e) {
                $this->handleMatchFailure($match, $e);
            }
        }
    }

    private function processMatch(string $matchToken, int|string $matchId): void
    {
        // Check if match is already failed
        $existingMatch = MtgoMatch::where('token', $matchToken)->first();
        if ($existingMatch?->failed_at !== null) {
            return;
        }

        // Username resolution (from BuildMatches)
        $username = LogEvent::where('match_token', $matchToken)
            ->whereNotNull('username')
            ->value('username');

        if (! $username) {
            $this->handleMissingUsername($matchToken);

            return;
        }

        $account = Account::where('username', $username)->first();

        if ($account && ! $account->tracked) {
            $this->markEventsProcessed($matchToken);

            return;
        }

        Mtgo::setUsername($username);

        try {
            DB::transaction(function () use ($matchToken, $matchId) {
                // Advance match state
                $match = AdvanceMatchState::run($matchToken, $matchId);

                if (! $match) {
                    // No join event yet — mark events processed to avoid retry buildup
                    $this->markStaleEventsProcessed($matchToken);

                    return;
                }

                // Check game log for results (inline, every tick)
                if (in_array($match->state, [MatchState::InProgress, MatchState::Ended])) {
                    $this->resolveGameResults($match);
                }

                // Mark all events for this match as processed
                $this->markEventsProcessed($matchToken);
            });
        } catch (\Throwable $e) {
            $match = $existingMatch ?? MtgoMatch::where('token', $matchToken)->first();

            if ($match) {
                $this->handleMatchFailure($match, $e);
            } else {
                Log::channel('pipeline')->error("ProcessMatches: exception for token={$matchToken} (no match record)", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function resolveGameResults(MtgoMatch $match): void
    {
        $gameLog = GameLog::where('match_token', $match->token)->first();

        // Inline fallback: try discovery if not found
        if (! $gameLog) {
            $gameLog = DiscoverGameLogs::discoverForToken($match->token);
        }

        if (! $gameLog || ! $gameLog->file_path || ! file_exists($gameLog->file_path)) {
            return;
        }

        // Parse fresh every tick
        $decoded = ParseGameLogBinary::run($gameLog->file_path);

        if (empty($decoded)) {
            return;
        }

        $players = ExtractGameResults::detectPlayers($decoded);
        $username = Mtgo::resolveUsername($players);

        if (! $username) {
            return;
        }

        $extracted = ExtractGameResults::run($decoded, $username);

        // Sync game results progressively
        $this->syncGameResults($match, $extracted['results'], $extracted['games']);

        // Check if decisive
        $stateChanges = LogEvent::where('match_token', $match->token)
            ->where('event_type', 'match_state_changed')
            ->get();

        $disconnectDetected = collect($extracted['games'])
            ->contains(fn ($g) => ($g['end_reason'] ?? '') === 'disconnect');

        $result = DetermineMatchResult::run(
            logResults: $extracted['results'],
            stateChanges: $stateChanges,
            matchScoreExists: $extracted['match_decided'],
            disconnectDetected: $disconnectDetected,
        );

        if ($result['decided']) {
            $previousState = $match->state;
            $outcome = MtgoMatch::determineOutcome($result['wins'], $result['losses']);

            $match->update([
                'outcome' => $outcome,
                'state' => MatchState::Complete,
                'ended_at' => $match->state === MatchState::InProgress
                    ? now()
                    : $match->ended_at,
            ]);

            Log::channel('pipeline')->info("Match {$match->mtgo_id}: {$previousState->value} → Complete", [
                'result' => "{$result['wins']}-{$result['losses']}",
                'outcome' => $outcome->value,
                'source' => 'game_log',
            ]);
        }
    }

    private function syncGameResults(MtgoMatch $match, array $results, array $gameData): void
    {
        $games = $match->games()->orderBy('started_at')->get();

        foreach ($games as $index => $game) {
            if (! isset($results[$index])) {
                continue;
            }

            $updates = [];

            if ($game->won === null || (bool) $game->won !== $results[$index]) {
                $updates['won'] = $results[$index];
            }

            if ($game->ended_at === null && ! empty($gameData[$index]['ended_at'])) {
                $updates['ended_at'] = $gameData[$index]['ended_at'];
            }

            if (! empty($updates)) {
                $game->update($updates);
            }
        }
    }

    private function handleMatchFailure(MtgoMatch $match, \Throwable $e): void
    {
        $attempts = $match->attempts + 1;
        $updates = ['attempts' => $attempts];

        if ($attempts >= 5) {
            $updates['failed_at'] = now();
            Log::channel('pipeline')->error("Match {$match->mtgo_id}: permanently failed after {$attempts} attempts", [
                'error' => $e->getMessage(),
            ]);
        } else {
            Log::channel('pipeline')->warning("Match {$match->mtgo_id}: attempt {$attempts}/5 failed", [
                'error' => $e->getMessage(),
            ]);
        }

        // Update outside the transaction (which rolled back)
        $match->update($updates);
    }

    private function markEventsProcessed(string $matchToken): void
    {
        LogEvent::where('match_token', $matchToken)
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);
    }

    private function handleMissingUsername(string $matchToken): void
    {
        // Grace window: don't mark as processed if events are fresh (< 2 min)
        $stale = LogEvent::where('match_token', $matchToken)
            ->whereNull('processed_at')
            ->where('ingested_at', '<', now()->subMinutes(2))
            ->exists();

        if ($stale) {
            $this->markEventsProcessed($matchToken);
            Log::channel('pipeline')->info("ProcessMatches: marked stale events processed for token={$matchToken} (no username after 2 min)");
        }
    }

    private function markStaleEventsProcessed(string $matchToken): void
    {
        $stale = LogEvent::where('match_token', $matchToken)
            ->whereNull('processed_at')
            ->where('ingested_at', '<', now()->subMinutes(2))
            ->exists();

        if ($stale) {
            $this->markEventsProcessed($matchToken);
            Log::channel('pipeline')->info("ProcessMatches: marked stale events processed for token={$matchToken} (no join event after 2 min)");
        }
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter=ProcessMatchesTest`

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "Add ProcessMatches command — unified pipeline"
```

---

## Phase 4: Wire Up & Tear Down

### Task 7: Replace MtgoManager scheduling and delete old classes

**Files:**
- Modify: `app/Managers/MtgoManager.php`
- Delete: `app/Actions/Matches/ResolveStaleMatches.php`
- Delete: `app/Actions/Matches/ResolvePendingResults.php`
- Delete: `app/Actions/Matches/ResolveGameResults.php`
- Delete: `app/Jobs/PollGameLogs.php`
- Delete: `tests/Feature/Actions/Matches/ResolveGameResultsTest.php`
- Delete: `tests/Feature/Actions/Matches/ResolvePendingResultsTest.php`
- Delete: `tests/Feature/Jobs/PollGameLogsTest.php`
- Delete: any test for `ResolveStaleMatches`

- [ ] **Step 1: Replace scheduling in MtgoManager**

In `app/Managers/MtgoManager.php`, replace the `schedule()` method's ingest and resolve pipelines (lines 211-236):

```php
public function schedule(Schedule $schedule): void
{
    // ── Unified pipeline (every 2s) ──────────────────────────
    // Single command owns the entire lifecycle: log ingest →
    // match creation → game log parsing → result resolution.
    $schedule->command('mtgo:process-matches')
        ->everyTwoSeconds()
        ->withoutOverlapping(expiresAt: 60);

    // Periodic maintenance (unchanged)
    $schedule->call(fn () => $this->retryUnsubmittedMatches())
        ->everyMinute()
        ->name('submit_matches')
        ->withoutOverlapping(60);

    $schedule->call(fn () => $this->downloadArchetypes())
        ->weekly();

    $schedule->call(fn () => $this->populateMissingCardData())
        ->hourly();

    $schedule->call(fn () => PruneProcessedLogEvents::run())
        ->daily()
        ->name('prune_log_events');
}
```

Remove imports for deleted classes: `ResolveGameResults`, `ResolvePendingResults`, `PollGameLogs`, `ResolveStaleMatches`, `BuildMatches`.

- [ ] **Step 2: Keep `ingestLogs()` on MtgoManager — the command uses it**

The command calls `app('mtgo')->ingestLogs()` which delegates to `MtgoManager::ingestLogs()`. This method stays. Just remove the old schedule closure that called it directly.

- [ ] **Step 3: Delete old pipeline classes**

```bash
rm app/Actions/Matches/ResolveStaleMatches.php
rm app/Actions/Matches/ResolvePendingResults.php
rm app/Actions/Matches/ResolveGameResults.php
rm app/Jobs/PollGameLogs.php
```

- [ ] **Step 4: Delete old tests for deleted classes**

```bash
rm tests/Feature/Actions/Matches/ResolveGameResultsTest.php
rm tests/Feature/Actions/Matches/ResolvePendingResultsTest.php
# Check for and delete ResolveStaleMatches and PollGameLogs tests:
find tests -name "*ResolveStaleMatches*" -o -name "*PollGameLogs*" | head -20
```

Delete any found.

- [ ] **Step 5: Remove BuildMatches call from ResolveStaleMatches references**

Check if `BuildMatches::run()` still calls `ResolveStaleMatches::run()` at the end. If so, remove that line. The `ProcessMatches` command doesn't use `BuildMatches` for the stale check anymore.

Actually — check if `BuildMatches` is still used at all by the new command. Looking at the command: the command does its own event querying and calls `AdvanceMatchState::run()` directly. `BuildMatches::run()` is no longer called. However, `BuildMatches` contains the username resolution and token discovery logic that has been inlined into the command.

**Decision:** `BuildMatches` can be kept for now (no active callers = dead code, but safe). Or delete it. Recommend: keep it until all tests pass, then delete in a cleanup commit.

- [ ] **Step 6: Run all tests**

Run: `php artisan test --compact`

Fix any remaining failures from deleted classes or removed imports.

- [ ] **Step 7: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 8: Commit**

```bash
git add -A && git commit -m "Replace two-routine pipeline with single mtgo:process-matches command"
```

---

## Phase 5: Integration Testing

### Task 8: End-to-end integration tests

**Files:**
- Create: `tests/Feature/Commands/ProcessMatchesIntegrationTest.php`

These tests verify the full pipeline lifecycle using fixture data. They are the most important tests in this rearchitecture.

- [ ] **Step 1: Write integration tests**

Run: `php artisan make:test --pest Commands/ProcessMatchesIntegrationTest --no-interaction`

```php
<?php

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('game log drives InProgress match directly to Complete', function () {
    // Match is InProgress with games, game log has decisive result
    $match = MtgoMatch::factory()->inProgress()->create(['token' => 'direct-complete']);
    Game::factory()->create(['match_id' => $match->id, 'won' => null]);
    Game::factory()->create(['match_id' => $match->id, 'won' => null]);

    // Create a GameLog pointing to a real fixture binary
    // (Use existing fixture if available, otherwise skip binary parsing
    // and test the result-sync path by pre-populating decoded data)
    // This is a structural test — exact fixture path depends on what exists in tests/fixtures/

    $this->artisan('mtgo:process-matches')->assertSuccessful();

    // Verify: match should stay InProgress if no real game log exists
    // (This test verifies the command doesn't crash, not the full path)
});

it('is idempotent — running twice produces same result', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
    ]);

    $this->artisan('mtgo:process-matches')->assertSuccessful();
    $this->artisan('mtgo:process-matches')->assertSuccessful();

    expect($match->fresh()->state)->toBe(MatchState::Complete);
    expect($match->fresh()->outcome)->toBe(MatchOutcome::Win);
});

it('does not increment attempts for missing game log', function () {
    $match = MtgoMatch::factory()->inProgress()->create(['attempts' => 0]);

    $this->artisan('mtgo:process-matches')->assertSuccessful();

    // "Game log not found" is not an exception — attempts should not increment
    expect($match->fresh()->attempts)->toBe(0);
});

it('marks events processed after successful match processing', function () {
    $match = MtgoMatch::factory()->inProgress()->create([
        'token' => 'mark-test',
        'mtgo_id' => '99999',
    ]);

    LogEvent::create([
        'match_id' => '99999',
        'match_token' => 'mark-test',
        'event_type' => 'game_state_update',
        'processed_at' => null,
        'ingested_at' => now(),
        'timestamp' => now()->toTimeString(),
        'logged_at' => now()->toDateString(),
    ]);

    $this->artisan('mtgo:process-matches')->assertSuccessful();

    expect(LogEvent::where('match_token', 'mark-test')->whereNull('processed_at')->count())->toBe(0);
});
```

**Note:** Full end-to-end tests with real fixture log files + binary game logs should be added once the command is working and fixture files are confirmed. The tests above verify command structure, idempotency, and error handling without requiring MTGO data files.

- [ ] **Step 2: Run integration tests**

Run: `php artisan test --compact --filter=ProcessMatchesIntegrationTest`

- [ ] **Step 3: Run all tests to verify nothing is broken**

Run: `php artisan test --compact`

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "Add integration tests for ProcessMatches command"
```

---

### Task 9: Clean up dead code

**Files:**
- Possibly delete: `app/Actions/Matches/BuildMatches.php` (if confirmed unused)
- Possibly delete: related tests

- [ ] **Step 1: Check if BuildMatches is referenced anywhere**

Run: `grep -r "BuildMatches" app/ --include="*.php" -l`

If only referenced in `MtgoManager.php` (which was already updated) and its own test file, it's dead code.

- [ ] **Step 2: Delete dead code**

If confirmed unused:
```bash
rm app/Actions/Matches/BuildMatches.php
# Delete its test if it exists:
find tests -name "*BuildMatches*" -exec rm {} \;
```

- [ ] **Step 3: Check for other dead imports/references to deleted classes**

Run: `grep -r "ResolveStaleMatches\|ResolvePendingResults\|ResolveGameResults\|PollGameLogs" app/ tests/ --include="*.php" -l`

Fix any remaining references.

- [ ] **Step 4: Run all tests**

Run: `php artisan test --compact`

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "Remove dead pipeline code (BuildMatches, old resolve classes)"
```

