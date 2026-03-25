# Outcome Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove all references to the deleted `games_won`/`games_lost` columns, replacing them with the `outcome` column and game counts from the `games` table via model scopes and accessors.

**Architecture:** Add `scopeWon()`, `scopeLost()`, `scopeWithGameCounts()`, `isWin()`, `isLoss()`, `gamesWon()`, `gamesLost()`, `gameRecord()` to `MtgoMatch`. All consumers use these instead of raw column references. Game-level aggregate stats use subqueries against the `games` table.

**Tech Stack:** PHP 8.4, Laravel 12, Pest v4, SQLite

**Spec:** `docs/superpowers/specs/2026-03-23-outcome-refactor-design.md`

### Guiding Principles

- **Tests must be meaningful.** If a test fails, investigate WHY. Do not brute-force it to pass.
- **Models use `$guarded = []`**, not `$fillable`.
- **No `LogEvent::factory()` exists.** Use `LogEvent::create([...])` directly.
- **Run `vendor/bin/pint --dirty --format agent` before committing.**
- **The `games_won`/`games_lost` columns do NOT exist on the `matches` table.** Any code referencing them will error at runtime.

---

## Task 1: Add scopes and accessors to MtgoMatch model

**Files:**
- Modify: `app/Models/MtgoMatch.php`
- Create: `tests/Feature/Models/MtgoMatchTest.php`

- [ ] **Step 1: Write tests for the new model API**

```php
<?php

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('scopes won matches', function () {
    MtgoMatch::factory()->create(['outcome' => MatchOutcome::Win]);
    MtgoMatch::factory()->create(['outcome' => MatchOutcome::Loss]);

    expect(MtgoMatch::won()->count())->toBe(1);
});

it('scopes lost matches', function () {
    MtgoMatch::factory()->create(['outcome' => MatchOutcome::Win]);
    MtgoMatch::factory()->create(['outcome' => MatchOutcome::Loss]);

    expect(MtgoMatch::lost()->count())->toBe(1);
});

it('eager loads game counts with withGameCounts scope', function () {
    $match = MtgoMatch::factory()->create();
    Game::factory()->create(['match_id' => $match->id, 'won' => true]);
    Game::factory()->create(['match_id' => $match->id, 'won' => true]);
    Game::factory()->create(['match_id' => $match->id, 'won' => false]);

    $loaded = MtgoMatch::withGameCounts()->find($match->id);

    expect($loaded->games_won_count)->toBe(2)
        ->and($loaded->games_lost_count)->toBe(1);
});

it('returns isWin correctly', function () {
    $win = MtgoMatch::factory()->create(['outcome' => MatchOutcome::Win]);
    $loss = MtgoMatch::factory()->create(['outcome' => MatchOutcome::Loss]);

    expect($win->isWin())->toBeTrue()
        ->and($loss->isWin())->toBeFalse();
});

it('returns isLoss correctly', function () {
    $loss = MtgoMatch::factory()->create(['outcome' => MatchOutcome::Loss]);
    $win = MtgoMatch::factory()->create(['outcome' => MatchOutcome::Win]);

    expect($loss->isLoss())->toBeTrue()
        ->and($win->isLoss())->toBeFalse();
});

it('counts gamesWon from relationship', function () {
    $match = MtgoMatch::factory()->create();
    Game::factory()->create(['match_id' => $match->id, 'won' => true]);
    Game::factory()->create(['match_id' => $match->id, 'won' => true]);
    Game::factory()->create(['match_id' => $match->id, 'won' => false]);

    expect($match->gamesWon())->toBe(2);
});

it('counts gamesLost from relationship', function () {
    $match = MtgoMatch::factory()->create();
    Game::factory()->create(['match_id' => $match->id, 'won' => true]);
    Game::factory()->create(['match_id' => $match->id, 'won' => false]);

    expect($match->gamesLost())->toBe(1);
});

it('uses eager-loaded counts when available', function () {
    $match = MtgoMatch::factory()->create();
    Game::factory()->create(['match_id' => $match->id, 'won' => true]);
    Game::factory()->create(['match_id' => $match->id, 'won' => false]);

    $loaded = MtgoMatch::withGameCounts()->find($match->id);

    // Should use the eager-loaded count, not query again
    expect($loaded->gamesWon())->toBe(1)
        ->and($loaded->gamesLost())->toBe(1);
});

it('returns gameRecord string', function () {
    $match = MtgoMatch::factory()->create();
    Game::factory()->create(['match_id' => $match->id, 'won' => true]);
    Game::factory()->create(['match_id' => $match->id, 'won' => true]);
    Game::factory()->create(['match_id' => $match->id, 'won' => false]);

    expect($match->gameRecord())->toBe('2-1');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=MtgoMatchTest`

- [ ] **Step 3: Update MtgoMatch model**

In `app/Models/MtgoMatch.php`:

Remove from casts:
```php
'games_won' => 'integer',
'games_lost' => 'integer',
```

Add scopes:
```php
public function scopeWon(Builder $query): Builder
{
    return $query->where('outcome', MatchOutcome::Win);
}

public function scopeLost(Builder $query): Builder
{
    return $query->where('outcome', MatchOutcome::Loss);
}

public function scopeWithGameCounts(Builder $query): Builder
{
    return $query->withCount([
        'games as games_won_count' => fn ($q) => $q->where('won', true),
        'games as games_lost_count' => fn ($q) => $q->where('won', false),
    ]);
}
```

Add accessors:
```php
public function isWin(): bool
{
    return $this->outcome === MatchOutcome::Win;
}

public function isLoss(): bool
{
    return $this->outcome === MatchOutcome::Loss;
}

public function gamesWon(): int
{
    return $this->games_won_count ?? $this->games()->where('won', true)->count();
}

public function gamesLost(): int
{
    return $this->games_lost_count ?? $this->games()->where('won', false)->count();
}

public function gameRecord(): string
{
    return $this->gamesWon().'-'.$this->gamesLost();
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter=MtgoMatchTest`

- [ ] **Step 5: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/MtgoMatch.php tests/Feature/Models/MtgoMatchTest.php
git commit -m "feat: add outcome scopes and game count accessors to MtgoMatch"
```

---

## Task 2: Update factory and pipeline actions

**Files:**
- Modify: `database/factories/MtgoMatchFactory.php`
- Modify: `app/Actions/Matches/ResolveGameResults.php`
- Modify: `app/Actions/Matches/ResolvePendingResults.php`
- Modify: `app/Observers/MtgoMatchObserver.php`
- Modify: `app/Data/Front/MatchData.php`

- [ ] **Step 1: Update MtgoMatchFactory — remove games_won/games_lost**

```php
public function definition(): array
{
    return [
        'mtgo_id' => fake()->unique()->randomNumber(8),
        'token' => fake()->uuid(),
        'format' => 'CStandard',
        'match_type' => 'Constructed',
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'started_at' => now(),
        'ended_at' => now()->addMinutes(30),
    ];
}

public function won(): static
{
    return $this->state(fn () => ['outcome' => MatchOutcome::Win]);
}

public function lost(): static
{
    return $this->state(fn () => ['outcome' => MatchOutcome::Loss]);
}
```

- [ ] **Step 2: Update ResolveGameResults — remove games_won/games_lost from update**

In `app/Actions/Matches/ResolveGameResults.php`, change the `$match->update([...])` call (around line 63) to:
```php
$match->update([
    'outcome' => $outcome,
    'state' => MatchState::Complete,
]);
```

- [ ] **Step 3: Update ResolvePendingResults — same change**

In `app/Actions/Matches/ResolvePendingResults.php`, change the `$match->update([...])` call (around line 24) to:
```php
$match->update([
    'outcome' => $outcome,
    'state' => MatchState::Complete,
]);
```

- [ ] **Step 4: Update MtgoMatchObserver — use gameRecord()**

In `app/Observers/MtgoMatchObserver.php`, replace:
```php
message: $match->games_won.'-'.$match->games_lost,
```
with:
```php
message: $match->gameRecord(),
```

- [ ] **Step 5: Update MatchData DTO**

In `app/Data/Front/MatchData.php`, change the `fromModel` method:
```php
gamesWon: $match->gamesWon(),
gamesLost: $match->gamesLost(),
result: $match->isWin() ? 'won' : 'lost',
```

- [ ] **Step 6: Run tests**

Run: `php artisan test --compact`

- [ ] **Step 7: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/factories/ app/Actions/Matches/ResolveGameResults.php app/Actions/Matches/ResolvePendingResults.php app/Observers/MtgoMatchObserver.php app/Data/Front/MatchData.php
git commit -m "refactor: remove games_won/games_lost from factory, pipeline, observer, DTO"
```

---

## Task 3: Update dashboard and stats actions

**Files:**
- Modify: `app/Actions/Dashboard/GetFormatChart.php`
- Modify: `app/Actions/Decks/GetDeckStats.php`
- Modify: `app/Actions/Decks/GetDeckVersionStats.php`
- Modify: `app/Actions/Decks/GetArchetypeMatchupSpread.php`
- Modify: `app/Actions/Archetypes/GetArchetypeWinrates.php`
- Modify: `app/Actions/Leagues/GetActiveLeague.php`
- Modify: `app/Actions/Leagues/FormatLeagueRuns.php`
- Modify: `app/Actions/Leagues/GetLeagueResultDistribution.php`
- Modify: `app/Actions/Matches/SubmitMatchToApi.php`

All of these follow the same patterns from the spec. The replacement rules:

**Match win/loss in raw SQL:**
- `SUM(CASE WHEN games_won > games_lost THEN 1 ELSE 0 END) as wins` → `SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) as wins`
- `SUM(CASE WHEN games_won < games_lost THEN 1 ELSE 0 END) as losses` → `SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) as losses`

**Game counts in raw SQL:**
- `SUM(m.games_won) as games_won` → `SUM((SELECT COUNT(*) FROM games g WHERE g.match_id = m.id AND g.won = 1)) as games_won`
- `SUM(m.games_lost) as games_lost` → `SUM((SELECT COUNT(*) FROM games g WHERE g.match_id = m.id AND g.won = 0)) as games_lost`
- `SUM(m.games_won + m.games_lost) as total_games` → `SUM((SELECT COUNT(*) FROM games g WHERE g.match_id = m.id AND g.won IS NOT NULL)) as total_games`

**Match win/loss in Eloquent:**
- `whereRaw('games_won > games_lost')` → `won()` scope
- `whereRaw('games_won < games_lost')` → `lost()` scope
- `whereColumn('games_won', '>', 'games_lost')` → `where('outcome', 'win')`
- `$m->games_won > $m->games_lost` → `$m->isWin()`

**Game counts in Eloquent:**
- `withSum(['matches' => $dateScope], 'games_won')` → use `withGameCounts()` or compute in PHP from games relationship
- `$match->games_won` → `$match->gamesWon()`

- [ ] **Step 1: Update each action file systematically**

Apply the replacement patterns above to each file. Read each file first to understand the exact changes needed. Key files:

- `GetFormatChart.php:21` — raw SQL `CASE WHEN games_won > games_lost` → `CASE WHEN outcome = 'win'`
- `GetDeckStats.php:21-24,45` — `whereRaw` → scopes, `sum('games_won')` → subquery or games count
- `GetDeckVersionStats.php:23-27,49-50,89-90` — `withSum` → `withGameCounts()`, sum in PHP
- `GetArchetypeMatchupSpread.php:40-56,72,77-78` — raw SQL game sums → subqueries
- `GetArchetypeWinrates.php:38-39` — raw SQL `CASE WHEN` → use `outcome`
- `GetActiveLeague.php:40-41,60` — filter → `isWin()`/`isLoss()`
- `FormatLeagueRuns.php:49,116,123` — select/filter → `outcome`, `gameRecord()`
- `GetLeagueResultDistribution.php:36` — raw SQL → use `outcome`
- `SubmitMatchToApi.php:68` — `$match->games_won > $match->games_lost ? 'win' : 'loss'` → `$match->outcome->value`

- [ ] **Step 2: Run tests after each file**

Run: `php artisan test --compact`

- [ ] **Step 3: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/
git commit -m "refactor: replace games_won/games_lost with outcome and game counts in actions"
```

---

## Task 4: Update controllers

**Files:**
- Modify: `app/Http/Controllers/Decks/ShowController.php`
- Modify: `app/Http/Controllers/Opponents/IndexController.php`
- Modify: `app/Http/Controllers/Leagues/OverlayController.php`
- Modify: `app/Http/Controllers/Leagues/OpponentScoutWindowController.php`
- Modify: `app/Http/Controllers/Settings/IndexController.php`
- Modify: `app/Http/Controllers/Debug/Matches/UpdateController.php`

Note: `IndexController.php` (dashboard) is already done — no changes needed.

- [ ] **Step 1: Update each controller**

- `Decks/ShowController.php:85,87` — `whereRaw('games_won > games_lost')` → `won()` scope, `whereRaw('games_won < games_lost')` → `lost()` scope
- `Decks/ShowController.php:208` — raw SQL `CASE WHEN games_won > games_lost` → `CASE WHEN outcome = 'win'`
- `Opponents/IndexController.php:28` — remove `m.games_won, m.games_lost` from select, use `m.outcome` instead
- `Opponents/IndexController.php:44,48` — filter by `$r->outcome === 'win'` / `'loss'` instead of games comparison
- `Leagues/OverlayController.php:19-20` — `whereColumn('games_won', '>', 'games_lost')` → `where('outcome', 'win')` (and loss)
- `Leagues/OpponentScoutWindowController.php:32-33` — `whereRaw` → `won()`/`lost()` scopes
- `Settings/IndexController.php:30` — remove `games_won, games_lost` from `get()` columns
- `Debug/Matches/UpdateController.php:21,38-39` — remove `games_won`/`games_lost` from editable fields and validation

- [ ] **Step 2: Run tests**

Run: `php artisan test --compact`

- [ ] **Step 3: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/
git commit -m "refactor: replace games_won/games_lost with outcome and scopes in controllers"
```

---

## Task 5: Update test files and frontend

**Files:**
- Modify: `tests/Feature/Observers/MtgoMatchObserverTest.php`
- Modify: `tests/Feature/Leagues/OverlayControllerTest.php`
- Modify: `tests/Feature/Actions/Matches/ResolveGameResultsTest.php`
- Modify: `tests/Feature/Actions/Matches/AdvanceMatchStateTest.php`
- Modify: `tests/Feature/Actions/Matches/ExtractGameHandDataTest.php`
- Modify: `tests/Feature/Actions/Matches/MatchStatePipelineTest.php`
- Modify: `tests/Feature/Actions/Matches/AdvanceMatchStateTransactionTest.php`
- Modify: `tests/Feature/Actions/GetArchetypeWinratesTest.php`
- Modify: `resources/js/pages/debug/Matches.vue`
- Modify: `resources/js/pages/settings/Index.vue`
- Delete: `app/Console/Commands/RepairCorruptMatches.php`

- [ ] **Step 1: Delete RepairCorruptMatches command**

This command operates entirely on the removed columns and is obsolete.

```bash
git rm app/Console/Commands/RepairCorruptMatches.php
```

- [ ] **Step 2: Update test files**

For each test file, search for `games_won` and `games_lost`:
- Remove from factory `create()` calls (factory no longer has these)
- Remove assertions on `games_won`/`games_lost` values
- Replace with assertions on `outcome` where appropriate
- If a test asserts `$match->games_won === 2`, change to `$match->gamesWon()` or check `outcome`

Read each test file, understand what it tests, and make minimal changes to remove the column references while keeping the test meaningful.

- [ ] **Step 3: Update Vue frontend files**

In `resources/js/pages/debug/Matches.vue`:
- Remove `games_won`/`games_lost` from table column definitions
- Use `outcome` column instead if win/loss display is needed

In `resources/js/pages/settings/Index.vue`:
- Remove `games_won`/`games_lost` from TypeScript type definition
- The backend no longer sends these fields

- [ ] **Step 4: Run full test suite**

Run: `php artisan test --compact`

- [ ] **Step 5: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "refactor: remove all remaining games_won/games_lost references from tests and frontend"
```

---

## Task 6: Final verification

- [ ] **Step 1: Grep for any remaining references**

```bash
grep -rn "games_won\|games_lost" app/ tests/ resources/ database/factories/ --include="*.php" --include="*.vue" --include="*.ts" | grep -v "migrations/"
```

This should return zero results.

- [ ] **Step 2: Run full test suite**

Run: `php artisan test --compact`

All tests must pass.

- [ ] **Step 3: Run Pint on full codebase**

Run: `vendor/bin/pint --format agent`

- [ ] **Step 4: Commit if any stragglers**

```bash
git add -A
git commit -m "chore: final cleanup of games_won/games_lost references"
```
