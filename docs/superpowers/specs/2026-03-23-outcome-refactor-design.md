# Outcome Refactor: Encapsulate Match Results in Model Layer

**Date:** 2026-03-23
**Branch:** `next`
**Status:** Draft

## Problem

The `games_won` and `games_lost` columns were removed from the `matches` table in favour of the `outcome` column. But ~50 references to these columns remain scattered across controllers, actions, DTOs, and raw SQL queries. The codebase uses `games_won > games_lost` as a proxy for "did this match win" and `SUM(games_won)` for aggregate stats — both are raw SQL that should be model-level concerns.

## Design Principle

**Encapsulate match result logic in the model layer.** Controllers and actions should never check win/loss directly — they use scopes, accessors, and relationships. Game-level counts come from the `games` table via the relationship, not from denormalized columns.

## MtgoMatch Model API

### Scopes (for query builder)

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

### Accessors (for single match instances)

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
    return $this->gamesWon() . '-' . $this->gamesLost();
}
```

Note: `gamesWon()` and `gamesLost()` check for `games_won_count`/`games_lost_count` first (set by `withGameCounts` scope when eager-loaded), falling back to a query if not loaded. This avoids N+1 when iterating collections while still working for single-instance access.

### Deck Model

```php
public function wonMatches(): HasManyThrough
{
    return $this->matches()->won();
}

public function lostMatches(): HasManyThrough
{
    return $this->matches()->lost();
}
```

## Replacement Patterns

### Category 1: "Did this match win?" → use `outcome` / scopes

| Before | After |
|--------|-------|
| `whereRaw('games_won > games_lost')` | `won()` scope |
| `whereRaw('games_won < games_lost')` | `lost()` scope |
| `whereColumn('games_won', '>', 'games_lost')` | `where('outcome', 'win')` |
| `$match->games_won > $match->games_lost` | `$match->isWin()` |
| `$m->games_won > $m->games_lost ? 'W' : 'L'` | `$m->isWin() ? 'W' : 'L'` |
| `SUM(CASE WHEN games_won > games_lost THEN 1 ELSE 0 END) as wins` | `SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) as wins` |
| `filter(fn ($m) => $m->games_won > $m->games_lost)` | `filter(fn ($m) => $m->isWin())` |

### Category 2: "Show game score" → use accessors

| Before | After |
|--------|-------|
| `"{$match->games_won}-{$match->games_lost}"` | `$match->gameRecord()` |
| `$match->games_won` | `$match->gamesWon()` |
| `$match->games_lost` | `$match->gamesLost()` |

When iterating collections, use `withGameCounts()` scope to eager-load counts:
```php
MtgoMatch::complete()->withGameCounts()->get();
```

### Category 3: "Aggregate game stats across matches" → subqueries

For actions that compute aggregate game-level stats (total games won across many matches), replace `SUM(m.games_won)` with a join or subquery against the `games` table:

```sql
-- Before
SUM(m.games_won) as games_won

-- After
(SELECT COUNT(*) FROM games g WHERE g.match_id = m.id AND g.won = 1) as games_won
```

Or in Eloquent, use `withGameCounts()` and sum the counts in PHP when the dataset is small enough.

## Files to Update

### Delete
- `app/Console/Commands/RepairCorruptMatches.php` — operates entirely on removed columns, obsolete

### Model layer
- `app/Models/MtgoMatch.php` — remove `games_won`/`games_lost` casts, add scopes + accessors
- `app/Models/Deck.php` — already done (uses `outcome` column), no changes needed
- `database/factories/MtgoMatchFactory.php` — remove `games_won`/`games_lost` from definition and states

### DTOs
- `app/Data/Front/MatchData.php` — use `$match->gamesWon()`, `$match->gamesLost()`, `$match->isWin()`

### Pipeline actions (our new code)
- `app/Actions/Matches/ResolveGameResults.php` — remove `games_won`/`games_lost` from update call
- `app/Actions/Matches/ResolvePendingResults.php` — remove `games_won`/`games_lost` from update call

### Observer
- `app/Observers/MtgoMatchObserver.php` — use `$match->gameRecord()` for notification message

### Dashboard/stats actions
- `app/Actions/Dashboard/GetFormatChart.php` — use `outcome = 'win'` instead of `games_won > games_lost`
- `app/Actions/Decks/GetDeckStats.php` — use `won()`/`lost()` scopes, game counts from `withGameCounts()`
- `app/Actions/Decks/GetDeckVersionStats.php` — replace `withSum` of `games_won`/`games_lost` with `withGameCounts()` or subqueries
- `app/Actions/Decks/GetArchetypeMatchupSpread.php` — replace raw SQL game sums with subqueries against `games` table
- `app/Actions/Archetypes/GetArchetypeWinrates.php` — use `outcome` in raw SQL
- `app/Actions/Leagues/GetActiveLeague.php` — use `$m->isWin()`
- `app/Actions/Leagues/FormatLeagueRuns.php` — use `outcome` column, `gameRecord()` for display
- `app/Actions/Leagues/GetLeagueResultDistribution.php` — use `outcome` in raw SQL
- `app/Actions/Matches/SubmitMatchToApi.php` — use `$match->outcome->value` (note: draws/unknowns should not be submitted — guard against it)

### Controllers
- `app/Http/Controllers/IndexController.php` — already done (uses `outcome` + games table), no changes needed
- `app/Http/Controllers/Decks/ShowController.php` — use `won()`/`lost()` scopes for filter + `outcome` in raw SQL for winrate chart
- `app/Http/Controllers/Opponents/IndexController.php` — use `outcome` column
- `app/Http/Controllers/Leagues/OverlayController.php` — use `won()`/`lost()` in `withCount`
- `app/Http/Controllers/Leagues/OpponentScoutWindowController.php` — use `won()`/`lost()` scopes
- `app/Http/Controllers/Settings/IndexController.php` — stop selecting `games_won`/`games_lost`
- `app/Http/Controllers/Debug/Matches/UpdateController.php` — remove `games_won`/`games_lost` from editable fields

### Frontend
- `resources/js/pages/debug/Matches.vue` — remove `games_won`/`games_lost` column references
- `resources/js/pages/settings/Index.vue` — update TypeScript type to remove `games_won`/`games_lost`

### Test files (update to remove `games_won`/`games_lost` references)
- `tests/Feature/Observers/MtgoMatchObserverTest.php`
- `tests/Feature/Leagues/OverlayControllerTest.php`
- `tests/Feature/Actions/Matches/ResolveGameResultsTest.php`
- `tests/Feature/Actions/Matches/AdvanceMatchStateTest.php`
- `tests/Feature/Actions/Matches/ExtractGameHandDataTest.php`
- `tests/Feature/Actions/Matches/MatchStatePipelineTest.php`
- `tests/Feature/Actions/Matches/AdvanceMatchStateTransactionTest.php`
- `tests/Feature/Actions/GetArchetypeWinratesTest.php`

### Notes
- Migration files are historical records and are intentionally left unchanged
- `GetActiveLeague.php` line 41 currently counts draws as losses (`games_won <= games_lost`). Replacing with `isLoss()` fixes this bug intentionally.

## Testing

- New model tests: `scopeWon`, `scopeLost`, `withGameCounts`, `isWin`, `isLoss`, `gamesWon`, `gamesLost`, `gameRecord`
- Existing dashboard tests already use `outcome` (fixed in prior task)
- All 8 test files listed above need updating to remove `games_won`/`games_lost` factory usage
- Run full suite after each batch of changes
