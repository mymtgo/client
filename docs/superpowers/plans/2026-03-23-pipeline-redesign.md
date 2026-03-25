# Pipeline Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the failed event-driven pipeline with independent polling subsystems for reliable match detection, progressive game result resolution, and clean enrichment via model observer.

**Architecture:** Two ingest loops (text log → `log_events`, binary game log → `game_logs.decoded_entries`) feed independent consumer subsystems: match construction, game result resolution, pending result fallback, and observer-based enrichment. Each subsystem polls on its own cadence with its own error handling.

**Tech Stack:** PHP 8.4, Laravel 12, Pest v4, SQLite

**Spec:** `docs/superpowers/specs/2026-03-23-pipeline-redesign-design.md`

### Guiding Principles

- **Tests must be meaningful.** If a test fails, investigate WHY it fails. Do not brute-force tests to pass — this creates evergreen tests that pass no matter what. Understand the failure, then fix the test OR the implementation, whichever is actually wrong.
- **No `LogEvent::factory()` exists.** Use `LogEvent::create([...])` directly in tests with explicit attributes.
- **Models use `$guarded = []`**, not `$fillable`. Do not add `$fillable` arrays.
- **`canRun()` guard.** All scheduler entries that touch MTGO files must check `$this->canRun()` (validates MTGO paths exist).
- **Task 10 (dashboard cherry-pick) is independent** of Tasks 1-9 and can be done in parallel or deferred.

---

## Phase 1: Foundation

### Task 1: Add MatchOutcome enum and outcome migration

**Files:**
- Create: `app/Enums/MatchOutcome.php`
- Modify: `app/Enums/MatchState.php`
- Create: `database/migrations/XXXX_add_outcome_to_matches_table.php`
- Test: `tests/Feature/Enums/MatchOutcomeTest.php`

- [ ] **Step 1: Create MatchOutcome enum**

```php
<?php

namespace App\Enums;

enum MatchOutcome: string
{
    case Win = 'win';
    case Loss = 'loss';
    case Draw = 'draw';
    case Unknown = 'unknown';
}
```

- [ ] **Step 2: Add PendingResult to MatchState enum**

In `app/Enums/MatchState.php`, add `case PendingResult = 'pending_result';` between `Ended` and `Complete`.

- [ ] **Step 3: Create the outcome migration**

Run: `php artisan make:migration add_outcome_to_matches_table --no-interaction`

Migration content:
```php
Schema::table('matches', function (Blueprint $table) {
    $table->string('outcome')->nullable()->after('state');
});
```

- [ ] **Step 4: Update MtgoMatch model casts**

In `app/Models/MtgoMatch.php`, add `'outcome' => MatchOutcome::class` to the casts method/array. (Model uses `$guarded = []` so no `$fillable` change needed.)

- [ ] **Step 5: Add determineOutcome helper to MtgoMatch**

```php
public static function determineOutcome(int $wins, int $losses): MatchOutcome
{
    if ($wins > $losses) return MatchOutcome::Win;
    if ($losses > $wins) return MatchOutcome::Loss;
    if ($wins > 0 && $wins === $losses) return MatchOutcome::Draw;
    return MatchOutcome::Unknown;
}
```

- [ ] **Step 6: Write tests**

```php
it('determines win outcome', function () {
    expect(MtgoMatch::determineOutcome(2, 1))->toBe(MatchOutcome::Win);
});

it('determines loss outcome', function () {
    expect(MtgoMatch::determineOutcome(0, 2))->toBe(MatchOutcome::Loss);
});

it('determines draw outcome', function () {
    expect(MtgoMatch::determineOutcome(1, 1))->toBe(MatchOutcome::Draw);
});

it('determines unknown outcome when no games', function () {
    expect(MtgoMatch::determineOutcome(0, 0))->toBe(MatchOutcome::Unknown);
});

it('handles 1-0 concession correctly', function () {
    expect(MtgoMatch::determineOutcome(1, 0))->toBe(MatchOutcome::Win);
});
```

- [ ] **Step 7: Run tests**

Run: `php artisan test --compact --filter=MatchOutcome`

- [ ] **Step 8: Run migration**

Run: `php artisan migrate`

- [ ] **Step 9: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 10: Commit**

```bash
git add app/Enums/MatchOutcome.php app/Enums/MatchState.php app/Models/MtgoMatch.php database/migrations/*outcome* tests/Feature/Enums/
git commit -m "feat: add MatchOutcome enum, PendingResult state, outcome migration"
```

---

### Task 2: Rework DetermineMatchResult — stop inflating results

**Files:**
- Modify: `app/Actions/Matches/DetermineMatchResult.php`
- Test: `tests/Feature/Actions/Matches/DetermineMatchResultTest.php`

The current `DetermineMatchResult::run()` takes `$logResults` and `$stateChanges` and inflates results to the win threshold on concession/disconnect. We need to:
1. Stop inflating — report actual game counts
2. Add a `decided` flag to the return value
3. Add concession, disconnect, and match score detection as "decided" signals

- [ ] **Step 1: Write failing tests for new behaviour**

```php
uses(RefreshDatabase::class);

it('does not inflate results on concession', function () {
    $logResults = [true]; // 1-0
    $stateChanges = collect([
        LogEvent::create([
            'file_path' => '/tmp/test.log',
            'byte_offset_start' => 0,
            'byte_offset_end' => 100,
            'timestamp' => now(),
            'level' => 'INFO',
            'category' => 'MatchPlugin',
            'context' => 'LeagueMatchConcedeReqState to LeagueMatchNotJoinedCatchAllState',
            'raw_text' => 'test',
            'ingested_at' => now(),
            'logged_at' => now(),
            'event_type' => 'match_state_changed',
        ]),
    ]);

    $result = DetermineMatchResult::run($logResults, $stateChanges);

    expect($result['wins'])->toBe(1)
        ->and($result['losses'])->toBe(0)
        ->and($result['decided'])->toBeTrue();
});

it('marks decided when win threshold reached', function () {
    $result = DetermineMatchResult::run([true, false, true], collect());

    expect($result['wins'])->toBe(2)
        ->and($result['losses'])->toBe(1)
        ->and($result['decided'])->toBeTrue();
});

it('marks decided when match score present', function () {
    $result = DetermineMatchResult::run([true], collect(), matchScoreExists: true);

    expect($result['decided'])->toBeTrue();
});

it('marks not decided when no signal exists', function () {
    $result = DetermineMatchResult::run([true], collect());

    expect($result['wins'])->toBe(1)
        ->and($result['losses'])->toBe(0)
        ->and($result['decided'])->toBeFalse();
});

it('marks decided on disconnect', function () {
    $result = DetermineMatchResult::run([true], collect(), disconnectDetected: true);

    expect($result['decided'])->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=DetermineMatchResult`

- [ ] **Step 3: Rework DetermineMatchResult**

```php
public static function run(
    array $logResults,
    Collection $stateChanges,
    string $gameStructure = '',
    bool $matchScoreExists = false,
    bool $disconnectDetected = false,
): array {
    $wins = count(array_filter($logResults, fn ($r) => $r === true));
    $losses = count(array_filter($logResults, fn ($r) => $r === false));

    $winThreshold = ($wins >= 3 || $losses >= 3) ? 3 : 2;
    $thresholdMet = $wins >= $winThreshold || $losses >= $winThreshold;
    $conceded = static::localPlayerConceded($stateChanges);

    $decided = $thresholdMet || $conceded || $matchScoreExists || $disconnectDetected;

    return [
        'wins' => $wins,
        'losses' => $losses,
        'decided' => $decided,
    ];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=DetermineMatchResult`

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Matches/DetermineMatchResult.php tests/Feature/Actions/Matches/DetermineMatchResultTest.php
git commit -m "refactor: DetermineMatchResult stops inflating results, adds decided flag"
```

---

## Phase 2: Refactor Match Construction

### Task 3: Strip completion logic from AdvanceMatchState

**Files:**
- Modify: `app/Actions/Matches/AdvanceMatchState.php`
- Modify: `app/Actions/Matches/BuildMatches.php` (verify it still works without completion)
- Test: `tests/Feature/Actions/Matches/AdvanceMatchStateTest.php`

`AdvanceMatchState` currently handles Started → InProgress → Ended → Complete. We need to remove the `tryAdvanceToComplete` method entirely — that responsibility moves to `ResolveGameResults` in Task 5.

- [ ] **Step 1: Write test confirming Ended is the terminal state**

```php
it('stops at Ended state and does not advance to Complete', function () {
    // Create a match with all events needed for full progression
    $match = MtgoMatch::factory()->create(['state' => MatchState::Ended]);

    // Create log events that would have triggered completion
    // ... (use existing test patterns from BuildMatchesTest.php)

    // Re-run AdvanceMatchState — should NOT change state from Ended
    $result = AdvanceMatchState::run($match->token, $match->mtgo_id);

    expect($result->state)->toBe(MatchState::Ended);
});
```

- [ ] **Step 2: Remove `tryAdvanceToComplete` method from AdvanceMatchState**

In `app/Actions/Matches/AdvanceMatchState.php`:
- Delete the entire `tryAdvanceToComplete` private method (lines 245-312)
- Remove the call to it in `run()` (lines 126-128: the `if ($match->state === MatchState::Ended)` block)
- Remove unused imports: `GetGameLog`, `SyncGameResults`, `DetermineMatchResult`, `DetermineMatchArchetypes`, `AppNotification`, `SubmitMatch`, `ComputeCardGameStats`, `LeagueState`

- [ ] **Step 3: Run existing tests**

Run: `php artisan test --compact --filter=AdvanceMatchState`
Then: `php artisan test --compact --filter=BuildMatches`

Fix any failures caused by the removal (tests that expected Complete state should now expect Ended).

- [ ] **Step 4: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Matches/AdvanceMatchState.php tests/
git commit -m "refactor: strip completion logic from AdvanceMatchState, stops at Ended"
```

---

## Phase 3: Game Log Polling

### Task 4: Implement PollGameLogs job

**Files:**
- Create: `app/Jobs/PollGameLogs.php`
- Test: `tests/Feature/Jobs/PollGameLogsTest.php`

This job replaces `StoreGameLogs` + `SyncLiveGameResults`. It discovers `.dat` files, creates `GameLog` records, and re-parses files into `decoded_entries` on each cycle.

Reference existing code:
- `app/Actions/Logs/StoreGameLogFiles.php` — for file discovery pattern (Finder, match token extraction)
- `app/Actions/Matches/GetGameLog.php` — for parsing flow (ParseGameLogBinary, file reading with fallback)
- `app/Actions/Matches/SyncLiveGameResults.php` — for the live result sync pattern being replaced

- [ ] **Step 1: Write failing tests**

```php
uses(RefreshDatabase::class);

it('creates GameLog record when dat file is discovered', function () {
    // Create a match in InProgress state
    $match = MtgoMatch::factory()->create(['state' => MatchState::InProgress]);

    // Create a fixture .dat file in a temp directory
    // (use a real small .dat fixture or mock the filesystem)

    // Run PollGameLogs
    PollGameLogs::dispatchSync();

    expect(GameLog::where('match_token', $match->token)->exists())->toBeTrue();
});

it('updates decoded_entries when dat file has grown', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::InProgress]);
    $gameLog = GameLog::create([
        'match_token' => $match->token,
        'file_path' => $fixturePath,
        'byte_offset' => 0,
    ]);

    PollGameLogs::dispatchSync();

    $gameLog->refresh();
    expect($gameLog->decoded_entries)->not->toBeNull()
        ->and($gameLog->byte_offset)->toBeGreaterThan(0);
});

it('skips parsing when file size unchanged', function () {
    // Create GameLog with byte_offset matching file size
    // Assert decoded_entries is NOT re-parsed (stays the same)
});

it('handles missing dat files gracefully', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::InProgress]);
    // No .dat file exists — should not throw
    PollGameLogs::dispatchSync();
});

it('only polls matches in Started, InProgress, or Ended state', function () {
    MtgoMatch::factory()->create(['state' => MatchState::Complete]);
    // Assert no GameLog discovery attempted for Complete matches
});

it('is idempotent — running twice produces same result', function () {
    // Create match + fixture .dat file
    // Run PollGameLogs twice
    // Assert decoded_entries is identical, byte_offset unchanged on second run
});
```

- [ ] **Step 2: Implement PollGameLogs**

```php
<?php

namespace App\Jobs;

use App\Actions\Matches\ParseGameLogBinary;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\Finder;

class PollGameLogs implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 2;

    public function handle(): void
    {
        $this->discoverNewGameLogs();
        $this->parseActiveGameLogs();
    }

    private function discoverNewGameLogs(): void
    {
        $basePath = Mtgo::getLogDataPath();
        if (empty($basePath) || ! is_dir($basePath)) {
            return;
        }

        $activeTokens = MtgoMatch::whereIn('state', [
            MatchState::Started,
            MatchState::InProgress,
            MatchState::Ended,
        ])->pluck('token');

        $existingTokens = GameLog::whereIn('match_token', $activeTokens)
            ->pluck('match_token');

        $missingTokens = $activeTokens->diff($existingTokens);

        if ($missingTokens->isEmpty()) {
            return;
        }

        // Discover .dat files
        $finder = Finder::create()
            ->files()
            ->in($basePath)
            ->name('*Match_GameLog*')
            ->ignoreUnreadableDirs();

        foreach ($finder as $file) {
            $nameParts = explode('_', $file->getFilename());
            $token = pathinfo(last($nameParts), PATHINFO_FILENAME);

            if ($missingTokens->contains($token)) {
                GameLog::firstOrCreate([
                    'match_token' => $token,
                ], [
                    'file_path' => $file->getRealPath(),
                ]);
            }
        }
    }

    private function parseActiveGameLogs(): void
    {
        $activeLogs = GameLog::whereHas('match', function ($q) {
            $q->whereIn('state', [
                MatchState::Started,
                MatchState::InProgress,
                MatchState::Ended,
            ]);
        })->get();

        foreach ($activeLogs as $log) {
            $this->parseGameLog($log);
        }
    }

    private function parseGameLog(GameLog $log): void
    {
        $fileSize = @filesize($log->file_path);

        if ($fileSize === false) {
            return; // File doesn't exist yet
        }

        // Skip if file hasn't changed
        if ($log->byte_offset >= $fileSize) {
            return;
        }

        $raw = @file_get_contents($log->file_path);
        if ($raw === false) {
            return;
        }

        $parsed = ParseGameLogBinary::run($raw);
        if ($parsed === null || empty($parsed['entries'])) {
            return;
        }

        $log->update([
            'decoded_entries' => $parsed['entries'],
            'decoded_at' => now(),
            'byte_offset' => $fileSize,
            'decoded_version' => ParseGameLogBinary::VERSION,
        ]);

        Log::channel('pipeline')->info("PollGameLogs: parsed {$log->match_token}", [
            'entries' => count($parsed['entries']),
            'file_size' => $fileSize,
        ]);
    }
}
```

- [ ] **Step 2b: Add `match()` relationship to GameLog model**

In `app/Models/GameLog.php`, add:
```php
use App\Models\MtgoMatch;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

public function match(): BelongsTo
{
    return $this->belongsTo(MtgoMatch::class, 'match_token', 'token');
}
```

- [ ] **Step 3: Run tests**

Run: `php artisan test --compact --filter=PollGameLogs`

- [ ] **Step 4: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/PollGameLogs.php app/Models/GameLog.php tests/Feature/Jobs/PollGameLogsTest.php
git commit -m "feat: add PollGameLogs job — discovers and parses game log dat files"
```

---

## Phase 4: Game Result Resolution

### Task 5: Implement ResolveGameResults action

**Files:**
- Create: `app/Actions/Matches/ResolveGameResults.php`
- Test: `tests/Feature/Actions/Matches/ResolveGameResultsTest.php`

This is the core new subsystem. It reads `GameLog.decoded_entries`, runs `ExtractGameResults`, updates `Game.won` progressively, and transitions `Ended` matches to `Complete` or `PendingResult`.

Reference existing code:
- `app/Actions/Matches/ExtractGameResults.php` — reuse as-is
- `app/Actions/Matches/DetermineMatchResult.php` — reworked in Task 2
- `app/Actions/Matches/SyncLiveGameResults.php` — pattern being replaced

- [ ] **Step 1: Write failing tests**

```php
uses(RefreshDatabase::class);

it('updates Game.won progressively from decoded_entries', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::InProgress]);
    $game1 = Game::factory()->create(['match_id' => $match->id, 'won' => null]);
    $game2 = Game::factory()->create(['match_id' => $match->id, 'won' => null]);

    // Create GameLog with decoded_entries showing game 1 won
    GameLog::create([
        'match_token' => $match->token,
        'file_path' => '/tmp/fake.dat',
        'decoded_entries' => $entriesFixture, // entries where local player wins game 1
        'decoded_at' => now(),
        'byte_offset' => 100,
    ]);

    ResolveGameResults::run();

    expect($game1->fresh()->won)->toBeTrue();
});

it('transitions Ended match to Complete when result decided', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Ended,
        'ended_at' => now()->subMinutes(1),
    ]);
    // ... create games and GameLog with full results (2-1)

    ResolveGameResults::run();

    $match->refresh();
    expect($match->state)->toBe(MatchState::Complete)
        ->and($match->outcome)->toBe(MatchOutcome::Win)
        ->and($match->games_won)->toBe(2)
        ->and($match->games_lost)->toBe(1);
});

it('transitions Ended match to PendingResult after grace period', function () {
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Ended,
        'ended_at' => now()->subMinutes(3), // past 2-min grace
    ]);
    // Create GameLog with incomplete results (1-0, no concede/disconnect/score)

    ResolveGameResults::run();

    expect($match->fresh()->state)->toBe(MatchState::PendingResult);
});

it('does not transition InProgress match to Complete', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::InProgress]);
    // ... create GameLog with full results

    ResolveGameResults::run();

    // Game.won updated, but state stays InProgress
    expect($match->fresh()->state)->toBe(MatchState::InProgress);
});

it('skips matches without GameLog', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::InProgress]);
    // No GameLog exists — should not throw
    ResolveGameResults::run();
});

it('is idempotent — running twice does not duplicate updates', function () {
    // Create match + GameLog with results
    // Run ResolveGameResults twice
    // Assert Game.won values unchanged, match state unchanged, no duplicate records
});

it('sets outcome and state atomically', function () {
    // Verified by checking observer fires with both values present
    // See Task 6 for observer tests
});
```

- [ ] **Step 2: Implement ResolveGameResults**

```php
<?php

namespace App\Actions\Matches;

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class ResolveGameResults
{
    public static function run(): void
    {
        $matches = MtgoMatch::whereIn('state', [
            MatchState::InProgress,
            MatchState::Ended,
        ])->get();

        foreach ($matches as $match) {
            static::resolveForMatch($match);
        }
    }

    private static function resolveForMatch(MtgoMatch $match): void
    {
        $gameLog = GameLog::where('match_token', $match->token)->first();

        if (! $gameLog || empty($gameLog->decoded_entries)) {
            return;
        }

        $username = Mtgo::getUsername();
        if (! $username) {
            return;
        }

        $extracted = ExtractGameResults::run($gameLog->decoded_entries, $username);

        // Progressive: update Game.won for each game
        static::syncGameResults($match, $extracted['results'] ?? []);

        // Only determine final outcome for Ended matches
        if ($match->state !== MatchState::Ended) {
            return;
        }

        $stateChanges = \App\Models\LogEvent::where('match_token', $match->token)
            ->where('event_type', 'match_state_changed')
            ->get();

        $disconnectDetected = collect($extracted['games'] ?? [])
            ->contains(fn ($g) => ($g['end_reason'] ?? '') === 'disconnect');

        $result = DetermineMatchResult::run(
            logResults: $extracted['results'] ?? [],
            stateChanges: $stateChanges,
            matchScoreExists: ! empty($extracted['match_score']),
            disconnectDetected: $disconnectDetected,
        );

        if ($result['decided']) {
            $outcome = MtgoMatch::determineOutcome($result['wins'], $result['losses']);

            $match->update([
                'games_won' => $result['wins'],
                'games_lost' => $result['losses'],
                'outcome' => $outcome,
                'state' => MatchState::Complete,
            ]);

            Log::channel('pipeline')->info("Match {$match->mtgo_id}: Ended → Complete", [
                'result' => "{$result['wins']}-{$result['losses']}",
                'outcome' => $outcome->value,
            ]);

            return;
        }

        // Grace period: 2 minutes past ended_at
        if ($match->ended_at && $match->ended_at->lt(now()->subMinutes(2))) {
            $match->update(['state' => MatchState::PendingResult]);

            Log::channel('pipeline')->info("Match {$match->mtgo_id}: Ended → PendingResult", [
                'ended_at' => $match->ended_at,
                'wins' => $result['wins'],
                'losses' => $result['losses'],
            ]);
        }
    }

    private static function syncGameResults(MtgoMatch $match, array $results): void
    {
        $games = $match->games()->orderBy('started_at')->get();

        foreach ($games as $index => $game) {
            if (! isset($results[$index])) {
                continue;
            }

            if ($game->won === null || (bool) $game->won !== $results[$index]) {
                $game->update(['won' => $results[$index]]);
            }
        }
    }
}
```

- [ ] **Step 3: Run tests**

Run: `php artisan test --compact --filter=ResolveGameResults`

- [ ] **Step 4: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Matches/ResolveGameResults.php tests/Feature/Actions/Matches/ResolveGameResultsTest.php
git commit -m "feat: add ResolveGameResults — progressive game result resolution"
```

---

## Phase 5: Enrichment Observer

### Task 6: Update MtgoMatchObserver with enrichment logic

**Files:**
- Modify: `app/Observers/MtgoMatchObserver.php`
- Test: `tests/Feature/Observers/MtgoMatchObserverTest.php`

The existing observer only handles `deleting`. Add an `updated` method that triggers enrichment when state changes to `Complete`.

- [ ] **Step 1: Write failing tests**

```php
uses(RefreshDatabase::class);

it('dispatches enrichment when match state changes to Complete', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->create(['state' => MatchState::Ended]);

    $match->update([
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'games_won' => 2,
        'games_lost' => 1,
    ]);

    Queue::assertPushed(SubmitMatch::class);
    Queue::assertPushed(ComputeCardGameStats::class);
});

it('does not trigger enrichment for other state changes', function () {
    Queue::fake();

    $match = MtgoMatch::factory()->create(['state' => MatchState::Started]);
    $match->update(['state' => MatchState::InProgress]);

    Queue::assertNotPushed(SubmitMatch::class);
});

it('handles enrichment failures gracefully', function () {
    // Mock DetermineMatchArchetypes to throw
    // Assert match stays Complete (observer doesn't blow up)
});
```

- [ ] **Step 2: Add `updated` method to MtgoMatchObserver**

In `app/Observers/MtgoMatchObserver.php`:

```php
public function updated(MtgoMatch $match): void
{
    if (! $match->isDirty('state') || $match->state !== MatchState::Complete) {
        return;
    }

    // Each enrichment is independent — failure in one doesn't block others
    try {
        \App\Actions\DetermineMatchArchetypes::run($match);
    } catch (\Throwable $e) {
        Log::warning("Enrichment failed: archetypes for match {$match->id}: {$e->getMessage()}");
    }

    try {
        SubmitMatch::dispatch($match->id);
    } catch (\Throwable $e) {
        Log::warning("Enrichment failed: submit for match {$match->id}: {$e->getMessage()}");
    }

    try {
        ComputeCardGameStats::dispatch($match->id);
    } catch (\Throwable $e) {
        Log::warning("Enrichment failed: card stats for match {$match->id}: {$e->getMessage()}");
    }

    // Notification
    $won = $match->outcome === MatchOutcome::Win;
    $opponentArchetype = $match->opponentArchetypes()
        ->with('archetype')
        ->first()?->archetype?->name ?? 'Unknown';

    AppNotification::dispatch(
        type: $won ? 'match_win' : 'match_loss',
        title: ($won ? 'Win' : 'Loss') . ' vs ' . $opponentArchetype,
        message: $match->games_won . '-' . $match->games_lost,
        route: '/matches/' . $match->id,
    );

    // League completion check
    if (($league = $match->league) && $league->state === LeagueState::Active
        && $league->matches()->where('state', MatchState::Complete)->count() >= 5) {
        $league->update(['state' => LeagueState::Complete]);
    }
}
```

- [ ] **Step 3: Run tests**

Run: `php artisan test --compact --filter=MtgoMatchObserver`

- [ ] **Step 4: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 5: Commit**

```bash
git add app/Observers/MtgoMatchObserver.php tests/Feature/Observers/MtgoMatchObserverTest.php
git commit -m "feat: MtgoMatchObserver triggers enrichment on Complete state"
```

---

## Phase 6: Scheduler Rewiring

### Task 7: Rewire scheduler to independent subsystems

**Files:**
- Modify: `app/Managers/MtgoManager.php` (the `schedule()` method at line 217)
- Modify: `app/Actions/Logs/IngestLog.php` (remove LogEventsIngested dispatch at line 175)

- [ ] **Step 1: Remove LogEventsIngested dispatch from IngestLog**

In `app/Actions/Logs/IngestLog.php`, remove line 175: `LogEventsIngested::dispatch();`
Also remove the import for `LogEventsIngested`.

- [ ] **Step 2: Rewire MtgoManager::schedule()**

Replace the existing schedule method body in `app/Managers/MtgoManager.php`:

```php
public function schedule(Schedule $schedule): void
{
    // Core pipeline — independent subsystems, each guarded by canRun()
    $schedule->call(fn () => $this->ingestLogs())
        ->everyTwoSeconds()
        ->name('ingest_logs');

    $schedule->call(function () {
        if (! $this->canRun()) return;
        PollGameLogs::dispatch();
    })->everyTwoSeconds()->name('poll_game_logs')->withoutOverlapping(2);

    $schedule->call(function () {
        if (! $this->canRun()) return;
        BuildMatches::run();
    })->everyTwoSeconds()->name('build_matches')->withoutOverlapping(2);

    $schedule->call(function () {
        if (! $this->canRun()) return;
        ResolveGameResults::run();
    })->everyTwoSeconds()->name('resolve_game_results')->withoutOverlapping(2);

    // Periodic maintenance
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

Note: This calls `BuildMatches::run()` directly — the old `processLogEvents()` chain through `ProcessLogEvents` job is bypassed and will be removed in Task 8.

- [ ] **Step 3: Remove obsolete scheduler entries**

The following scheduled tasks are replaced:
- `ingestGameLogs()` (every 10s) → replaced by `PollGameLogs` (every 2s)
- `syncLiveGameResults()` (every 5s) → replaced by `ResolveGameResults` (every 2s)

- [ ] **Step 4: Run the full test suite**

Run: `php artisan test --compact`

Ensure nothing breaks with the scheduler changes.

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 6: Commit**

```bash
git add app/Managers/MtgoManager.php app/Actions/Logs/IngestLog.php
git commit -m "refactor: rewire scheduler to independent subsystems"
```

---

## Phase 7: Dead Code Removal

### Task 8: Remove obsolete pipeline code

**Files to delete:**
- `app/Jobs/ProcessLogEvents.php`
- `app/Jobs/StoreGameLogs.php`
- `app/Events/LogEventsIngested.php`
- `app/Listeners/DispatchProcessLogEvents.php`
- `app/Actions/Matches/GetGameLog.php`
- `app/Actions/Matches/GetGameLogEntries.php`
- `app/Actions/Matches/SyncGameResults.php`
- `app/Actions/Matches/SyncLiveGameResults.php`

**Files to modify (remove references):**
- `app/Managers/MtgoManager.php` — remove `processLogEvents()`, `ingestGameLogs()`, `syncLiveGameResults()` methods
- Any imports referencing deleted files

- [ ] **Step 1: Search for all references to files being deleted**

Run: `grep -r "ProcessLogEvents\|StoreGameLogs\|LogEventsIngested\|DispatchProcessLogEvents\|GetGameLog\|GetGameLogEntries\|SyncGameResults\|SyncLiveGameResults" app/ --include="*.php" -l`

- [ ] **Step 2: Update MtgoManager — remove obsolete methods**

Remove `processLogEvents()`, `ingestGameLogs()`, `syncLiveGameResults()` methods. Update the scheduler if any references to these remain. Update the `schedule()` method's `build_matches` entry to call `BuildMatches::run()` directly instead of going through `processLogEvents()`.

- [ ] **Step 3: Update schedule to call BuildMatches directly**

```php
$schedule->call(function () {
    if (! $this->canRun()) return;
    BuildMatches::run();
})->everyTwoSeconds()->name('build_matches')->withoutOverlapping(2);
```

- [ ] **Step 4: Delete the files**

```bash
git rm app/Jobs/ProcessLogEvents.php
git rm app/Jobs/StoreGameLogs.php
git rm app/Events/LogEventsIngested.php
git rm app/Listeners/DispatchProcessLogEvents.php
git rm app/Actions/Matches/GetGameLog.php
git rm app/Actions/Matches/GetGameLogEntries.php
git rm app/Actions/Matches/SyncGameResults.php
git rm app/Actions/Matches/SyncLiveGameResults.php
```

- [ ] **Step 5: Fix any remaining references**

Check compilation: `php artisan route:list` or similar smoke test. Remove any remaining imports to deleted classes.

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test --compact`

Delete or update any tests that reference deleted code.

- [ ] **Step 7: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "chore: remove obsolete pipeline code (events, listeners, jobs, actions)"
```

---

## Phase 8: Pending Result Fallback (deferred)

### Task 9: Implement ResolvePendingResults

**Files:**
- Create: `app/Actions/Matches/ResolvePendingResults.php`
- Create: `app/Actions/Matches/ParseMatchHistory.php`
- Test: `tests/Feature/Actions/Matches/ResolvePendingResultsTest.php`

**Note:** The `match_history` file format is undocumented and no parser exists. This task requires investigation of the file format first. If the format is too unreliable, this subsystem degrades gracefully — matches stay in `PendingResult` for manual resolution.

- [ ] **Step 1: Investigate the match_history file**

Locate the file on disk (MTGO data directories). Document its format. Determine if it contains match tokens/IDs and results.

- [ ] **Step 2: Implement ParseMatchHistory**

Create `app/Actions/Matches/ParseMatchHistory.php` based on the discovered format.

- [ ] **Step 3: Implement ResolvePendingResults**

```php
<?php

namespace App\Actions\Matches;

use App\Enums\MatchState;
use App\Models\MtgoMatch;

class ResolvePendingResults
{
    public static function run(): void
    {
        $pending = MtgoMatch::where('state', MatchState::PendingResult)->get();

        foreach ($pending as $match) {
            $result = ParseMatchHistory::findResult($match->token);

            if ($result === null) {
                continue;
            }

            $outcome = MtgoMatch::determineOutcome($result['wins'], $result['losses']);

            $match->update([
                'games_won' => $result['wins'],
                'games_lost' => $result['losses'],
                'outcome' => $outcome,
                'state' => MatchState::Complete,
            ]);
        }
    }
}
```

- [ ] **Step 4: Add to scheduler**

In `MtgoManager::schedule()`:
```php
$schedule->call(fn () => ResolvePendingResults::run())
    ->everyThirtySeconds()
    ->name('resolve_pending_results')
    ->withoutOverlapping(30);
```

- [ ] **Step 5: Write tests and run**

Run: `php artisan test --compact --filter=ResolvePendingResults`

- [ ] **Step 6: Run Pint and commit**

```bash
git add app/Actions/Matches/ResolvePendingResults.php app/Actions/Matches/ParseMatchHistory.php tests/
git commit -m "feat: add ResolvePendingResults — match_history fallback for incomplete results"
```

---

## Phase 9: Dashboard Cherry-Pick

### Task 10: Cherry-pick dashboard from 0.9.0

**Source branch:** `0.9.0`
**Key commits:** Dashboard command center rewrite + partials + actions

- [ ] **Step 1: Identify dashboard commits**

Run: `git log --oneline 0.9.0 --grep="dashboard\|Dashboard\|KPI\|command center"` to find the relevant commits.

- [ ] **Step 2: Cherry-pick or manually port**

The dashboard commits likely have dependencies on `MatchOutcome` (already added in Task 1). Cherry-pick if clean, otherwise manually port the files:

**Actions** (8 files in `app/Http/Controllers/` or `app/Actions/Dashboard/`):
- GetDashboardLeagueDistribution, GetLastSession, GetPlayDrawSplit, GetRollingForm, GetStreak, GetWinrateDelta, GetDashboardMatchupSpread, etc.

**Vue partials** (6 files in `resources/js/pages/` partials dir):
- DashboardSessionRecap, DashboardMatchupSpread, DashboardRollingForm, DashboardLeagueResults, DashboardKpiStrip, etc.

**Index.vue rewrite** — the command center layout.

**Controller updates** — IndexController to use new dashboard actions.

- [ ] **Step 3: Verify build**

Run: `npm run build` to ensure frontend compiles.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact`

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: port dashboard command center from 0.9.0"
```

---

## Phase 10: Deep Clean & Codebase Audit

### Task 11: Audit and remove technical debt

This is a thorough sweep of the entire codebase to remove dead code, unused imports, stale references, and anything left behind from the abandoned event-driven approach.

- [ ] **Step 1: Scan for references to deleted/obsolete code**

Search for:
- References to deleted files (GetGameLog, SyncGameResults, ProcessLogEvents, etc.)
- Orphaned event classes not used by any listener
- Orphaned listener classes not triggered by any event
- Unused imports across all PHP files
- Dead methods in MtgoManager
- Stale comments referencing old pipeline architecture

Run: `grep -r "GetGameLog\|SyncGameResults\|ProcessLogEvents\|LogEventsIngested\|DispatchProcessLogEvents\|StoreGameLogs\|SyncLiveGameResults" app/ resources/ tests/ --include="*.php" --include="*.ts" --include="*.vue" -l`

- [ ] **Step 2: Audit Actions directory**

Review all files in `app/Actions/Matches/` and `app/Actions/Logs/`:
- Is every action still referenced?
- Are there actions that duplicate logic now handled by new subsystems?
- Are there actions with `@deprecated` annotations that can be removed?

- [ ] **Step 3: Audit Events and Listeners directories**

- `app/Events/` — keep only events that are actively dispatched
- `app/Listeners/` — keep only listeners that handle kept events
- Verify auto-discovery still works (no orphaned files causing confusion)

- [ ] **Step 4: Audit Jobs directory**

- Remove any jobs that are no longer dispatched
- Verify all remaining jobs are referenced in scheduler or dispatched from code

- [ ] **Step 5: Audit Models for stale relationships and scopes**

- Check `MtgoMatch`, `Game`, `GameLog` for unused scopes, relationships, or methods
- Verify model casts are complete and correct (especially with new `outcome` field)

- [ ] **Step 6: Audit tests for stale test files**

- Remove tests for deleted actions/jobs/events
- Ensure remaining tests still pass

- [ ] **Step 7: Run full test suite**

Run: `php artisan test --compact`

- [ ] **Step 8: Run Pint on full codebase**

Run: `vendor/bin/pint --format agent`

- [ ] **Step 9: Update architecture docs**

Update `docs/system.md` and `docs/pipelines.md` to reflect the new independent subsystems architecture. Remove references to:
- Event-driven dispatch
- Listener cascades
- GetGameLog
- The old ProcessLogEvents → BuildMatches chain

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "chore: deep clean — remove tech debt, update docs, audit codebase"
```
