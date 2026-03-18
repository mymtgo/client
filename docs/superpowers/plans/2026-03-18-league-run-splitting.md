# League Run Splitting via Deck Version — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix league detection so the same MTGO League Token with different decks creates separate league runs, and display deck version info alongside league data.

**Architecture:** Add `deck_version_id` FK to the `leagues` table as a run discriminator. Change real league assignment from `firstOrCreate([token, format])` to `firstOrCreate([token, format, deck_version_id])`. Same token + different deck version = new league row. Controllers that derive deck info from matches will use the league's direct `deckVersion` relationship instead.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, SQLite, Inertia.js v2, Vue 3

---

## File Structure

| File | Action | Purpose |
|------|--------|---------|
| `database/migrations/2026_03_18_000000_add_deck_version_id_to_leagues_table.php` | Create | Migration + backfill |
| `database/factories/LeagueFactory.php` | Create | Factory for test setup |
| `database/factories/DeckVersionFactory.php` | Create | Factory for test setup |
| `database/factories/MtgoMatchFactory.php` | Create | Factory for test setup |
| `app/Models/League.php` | Modify | Add `deckVersion` relationship |
| `app/Actions/Matches/AdvanceMatchState.php:355-393` | Modify | Use `deck_version_id` in league assignment |
| `app/Http/Controllers/IndexController.php:73-113` | Modify | Use league's deck version instead of deriving from matches |
| `app/Http/Controllers/Leagues/OverlayController.php:45-49` | Modify | Use league's deck version instead of querying matches |
| `app/Http/Controllers/Leagues/IndexController.php:66-76` | Modify | Use league's deck version, add version label |
| `app/Http/Controllers/Decks/ShowController.php:205-258` | Modify | Use league's deck version |
| `resources/js/pages/partials/DashboardLeague.vue` | Modify | Show version label |
| `resources/js/components/leagues/LeagueTable.vue` | Modify | Show version label |
| `resources/js/components/leagues/LeagueScreenshot.vue` | Modify | Add `versionLabel` to type |
| `tests/Feature/Actions/Matches/AssignLeagueTest.php` | Create | Tests for league run splitting logic |
| `tests/Feature/Controllers/LeagueDashboardTest.php` | Create | Tests for dashboard active league display |

---

### Task 1: Create Factories

We need League, DeckVersion, and MtgoMatch factories that don't exist yet. These are prerequisites for all tests.

**Files:**
- Create: `database/factories/LeagueFactory.php`
- Create: `database/factories/DeckVersionFactory.php`
- Create: `database/factories/MtgoMatchFactory.php`
- Modify: `app/Models/League.php` (add `HasFactory` trait)
- Modify: `app/Models/DeckVersion.php` (add `HasFactory` trait)
- Modify: `app/Models/MtgoMatch.php` (add `HasFactory` trait)

- [ ] **Step 1: Create LeagueFactory**

Run: `php artisan make:factory LeagueFactory --no-interaction`

Then set the definition:

```php
public function definition(): array
{
    return [
        'token' => fake()->uuid(),
        'name' => 'League ' . fake()->word(),
        'format' => 'CStandard',
        'phantom' => false,
        'deck_change_detected' => false,
        'state' => \App\Enums\LeagueState::Active,
        'started_at' => now(),
    ];
}

public function phantom(): static
{
    return $this->state(fn () => [
        'phantom' => true,
        'name' => 'Phantom League ' . fake()->word(),
    ]);
}

public function complete(): static
{
    return $this->state(fn () => [
        'state' => \App\Enums\LeagueState::Complete,
    ]);
}

public function partial(): static
{
    return $this->state(fn () => [
        'state' => \App\Enums\LeagueState::Partial,
    ]);
}
```

- [ ] **Step 2: Create DeckVersionFactory**

Run: `php artisan make:factory DeckVersionFactory --no-interaction`

Then set the definition:

```php
public function definition(): array
{
    return [
        'deck_id' => \App\Models\Deck::factory(),
        'signature' => base64_encode(fake()->uuid() . ':4:false|' . fake()->uuid() . ':4:false'),
        'modified_at' => now(),
    ];
}
```

- [ ] **Step 3: Create MtgoMatchFactory**

Run: `php artisan make:factory MtgoMatchFactory --no-interaction`

Then set the definition:

```php
protected $model = \App\Models\MtgoMatch::class;

public function definition(): array
{
    return [
        'mtgo_id' => fake()->unique()->randomNumber(8),
        'token' => fake()->uuid(),
        'format' => 'CStandard',
        'match_type' => 'Constructed',
        'state' => \App\Enums\MatchState::Complete,
        'games_won' => 2,
        'games_lost' => 1,
        'started_at' => now(),
        'ended_at' => now()->addMinutes(30),
    ];
}

public function won(): static
{
    return $this->state(fn () => ['games_won' => 2, 'games_lost' => 1]);
}

public function lost(): static
{
    return $this->state(fn () => ['games_won' => 1, 'games_lost' => 2]);
}
```

- [ ] **Step 4: Add HasFactory trait to League, DeckVersion, MtgoMatch models**

`app/Models/League.php` — add `use HasFactory;` (import `Illuminate\Database\Eloquent\Factories\HasFactory`)

`app/Models/DeckVersion.php` — add `use HasFactory;` (import `Illuminate\Database\Eloquent\Factories\HasFactory`)

`app/Models/MtgoMatch.php` — add `use HasFactory;` (import `Illuminate\Database\Eloquent\Factories\HasFactory`)

- [ ] **Step 5: Verify factories work**

Run: `php artisan tinker --execute="App\Models\League::factory()->make(); echo 'OK';"`

- [ ] **Step 6: Commit**

```bash
git add database/factories/LeagueFactory.php database/factories/DeckVersionFactory.php database/factories/MtgoMatchFactory.php app/Models/League.php app/Models/DeckVersion.php app/Models/MtgoMatch.php
git commit -m "feat: add League, DeckVersion, and MtgoMatch factories"
```

---

### Task 2: Migration — Add `deck_version_id` to Leagues

**Files:**
- Create: `database/migrations/2026_03_18_000000_add_deck_version_id_to_leagues_table.php`

- [ ] **Step 1: Create migration**

Run: `php artisan make:migration add_deck_version_id_to_leagues_table --table=leagues --no-interaction`

- [ ] **Step 2: Write migration with backfill**

> **Known limitation:** The backfill tags each existing league with its first match's deck version. Existing leagues that already contain matches from multiple deck versions (the bug scenario) are NOT retroactively split — they are tagged with the first run's deck version. Only future matches will create properly split leagues.

```php
public function up(): void
{
    Schema::table('leagues', function (Blueprint $table) {
        $table->foreignId('deck_version_id')->nullable()->after('state')->constrained('deck_versions')->nullOnDelete();
    });

    // Backfill: set each league's deck_version_id from its first match
    $leagues = DB::table('leagues')->whereNull('deck_version_id')->pluck('id');

    foreach ($leagues as $leagueId) {
        $deckVersionId = DB::table('matches')
            ->where('league_id', $leagueId)
            ->whereNotNull('deck_version_id')
            ->whereNull('deleted_at')
            ->orderBy('started_at')
            ->value('deck_version_id');

        if ($deckVersionId) {
            DB::table('leagues')
                ->where('id', $leagueId)
                ->update(['deck_version_id' => $deckVersionId]);
        }
    }
}

public function down(): void
{
    Schema::table('leagues', function (Blueprint $table) {
        $table->dropConstrainedForeignId('deck_version_id');
    });
}
```

- [ ] **Step 3: Run migration**

Run: `php artisan migrate`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*add_deck_version_id_to_leagues_table.php
git commit -m "feat: add deck_version_id column to leagues table with backfill"
```

---

### Task 3: Update League Model

**Files:**
- Modify: `app/Models/League.php`

- [ ] **Step 1: Add deckVersion relationship**

Add to `League.php`:

```php
public function deckVersion(): BelongsTo
{
    return $this->belongsTo(DeckVersion::class);
}
```

Requires adding this import:

```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;
```

- [ ] **Step 2: Commit**

```bash
git add app/Models/League.php
git commit -m "feat: add deckVersion and deck relationships to League model"
```

---

### Task 4: Write Tests for League Assignment Logic

**Files:**
- Create: `tests/Feature/Actions/Matches/AssignLeagueTest.php`

@pest-testing

- [ ] **Step 1: Write the test file**

Run: `php artisan make:test --pest Actions/Matches/AssignLeagueTest --no-interaction`

Write these tests:

```php
<?php

use App\Actions\Matches\AdvanceMatchState;
use App\Enums\LeagueState;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Call the private assignLeague method via reflection.
 */
function callAssignLeague(MtgoMatch $match, array $gameMeta): void
{
    $method = new ReflectionMethod(AdvanceMatchState::class, 'assignLeague');
    $method->invoke(null, $match, $gameMeta);
}

function makeMatchWithDeck(DeckVersion $deckVersion, array $overrides = []): MtgoMatch
{
    return MtgoMatch::factory()->create(array_merge([
        'deck_version_id' => $deckVersion->id,
    ], $overrides));
}

function defaultGameMeta(string $token = 'league-token-123', string $format = 'CStandard'): array
{
    return [
        'League Token' => $token,
        'PlayFormatCd' => $format,
        'GameStructureCd' => 'Constructed',
    ];
}

/*
|--------------------------------------------------------------------------
| Real League Assignment
|--------------------------------------------------------------------------
*/

it('creates a league for a new token + deck version', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = makeMatchWithDeck($deckVersion);

    callAssignLeague($match, defaultGameMeta());

    $match->refresh();
    expect($match->league_id)->not->toBeNull();
    expect($match->league->token)->toBe('league-token-123');
    expect($match->league->deck_version_id)->toBe($deckVersion->id);
});

it('reuses existing league for same token + same deck version', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match1 = makeMatchWithDeck($deckVersion);
    $match2 = makeMatchWithDeck($deckVersion);

    callAssignLeague($match1, defaultGameMeta());
    callAssignLeague($match2, defaultGameMeta());

    $match1->refresh();
    $match2->refresh();
    expect($match1->league_id)->toBe($match2->league_id);
});

it('creates a new league for same token + different deck version', function () {
    $deck1Version = DeckVersion::factory()->create();
    $deck2Version = DeckVersion::factory()->create();
    $match1 = makeMatchWithDeck($deck1Version);
    $match2 = makeMatchWithDeck($deck2Version);

    callAssignLeague($match1, defaultGameMeta());
    callAssignLeague($match2, defaultGameMeta());

    $match1->refresh();
    $match2->refresh();
    expect($match1->league_id)->not->toBe($match2->league_id);
    expect($match1->league->token)->toBe('league-token-123');
    expect($match2->league->token)->toBe('league-token-123');
});

it('marks previous run as partial when new run created for same token', function () {
    $deck1Version = DeckVersion::factory()->create();
    $deck2Version = DeckVersion::factory()->create();
    $match1 = makeMatchWithDeck($deck1Version);
    $match2 = makeMatchWithDeck($deck2Version);

    callAssignLeague($match1, defaultGameMeta());
    callAssignLeague($match2, defaultGameMeta());

    $match1->refresh();
    expect($match1->league->state)->toBe(LeagueState::Partial);
});

it('falls back to token-only matching when deck version is null', function () {
    $match1 = MtgoMatch::factory()->create(['deck_version_id' => null]);
    $match2 = MtgoMatch::factory()->create(['deck_version_id' => null]);

    callAssignLeague($match1, defaultGameMeta());
    callAssignLeague($match2, defaultGameMeta());

    $match1->refresh();
    $match2->refresh();
    expect($match1->league_id)->toBe($match2->league_id);
});

it('sets deck_version_id on the league when created', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = makeMatchWithDeck($deckVersion);

    callAssignLeague($match, defaultGameMeta());

    $match->refresh();
    expect($match->league->deck_version_id)->toBe($deckVersion->id);
});

it('is idempotent — calling assignLeague twice with same match produces same result', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = makeMatchWithDeck($deckVersion);

    callAssignLeague($match, defaultGameMeta());
    $match->refresh();
    $firstLeagueId = $match->league_id;

    // Reset league_id to simulate re-processing
    $match->update(['league_id' => null]);
    callAssignLeague($match, defaultGameMeta());
    $match->refresh();

    expect($match->league_id)->toBe($firstLeagueId);
    expect(League::where('token', 'league-token-123')->count())->toBe(1);
});

it('assigns null-deck match to token-only league even if deck-versioned league exists', function () {
    $deckVersion = DeckVersion::factory()->create();
    $matchWithDeck = makeMatchWithDeck($deckVersion);
    $matchWithoutDeck = MtgoMatch::factory()->create(['deck_version_id' => null]);

    callAssignLeague($matchWithDeck, defaultGameMeta());
    callAssignLeague($matchWithoutDeck, defaultGameMeta());

    $matchWithDeck->refresh();
    $matchWithoutDeck->refresh();

    // The null-deck match falls back to [token, format] lookup which won't match
    // the [token, format, deck_version_id] league, so it creates a separate league
    expect($matchWithoutDeck->league_id)->not->toBe($matchWithDeck->league_id);
});

/*
|--------------------------------------------------------------------------
| Phantom League Assignment
|--------------------------------------------------------------------------
*/

it('sets deck_version_id on phantom leagues', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = makeMatchWithDeck($deckVersion);

    // Phantom league — no League Token
    $gameMeta = [
        'League Token' => '',
        'PlayFormatCd' => 'CStandard',
        'GameStructureCd' => 'Constructed',
    ];

    callAssignLeague($match, $gameMeta);

    $match->refresh();
    expect($match->league)->not->toBeNull();
    expect($match->league->phantom)->toBeTrue();
    expect($match->league->deck_version_id)->toBe($deckVersion->id);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AssignLeagueTest`

Expected: Tests fail because `assignLeague` doesn't use `deck_version_id` yet.

---

### Task 5: Update `assignLeague` in AdvanceMatchState

**Files:**
- Modify: `app/Actions/Matches/AdvanceMatchState.php:355-393`

- [ ] **Step 1: Update `assignLeague` for real leagues**

Replace the real league block (lines 357-373) with:

```php
if (! empty($gameMeta['League Token'])) {
    $leagueKey = [
        'token' => $gameMeta['League Token'],
        'format' => $gameMeta['PlayFormatCd'],
    ];

    // Include deck version in the composite key when available,
    // so re-entering the same league with a different deck creates a new run.
    if ($match->deck_version_id) {
        $leagueKey['deck_version_id'] = $match->deck_version_id;
    }

    $league = League::firstOrCreate($leagueKey, [
        'started_at' => now(),
        'name' => trim(($gameMeta['GameStructureCd'] ?? '').' League '.now()->format('d-m-Y h:ma')),
    ]);

    if ($league->wasRecentlyCreated) {
        // Mark older active leagues with the same token as partial
        League::where('token', $gameMeta['League Token'])
            ->where('format', $gameMeta['PlayFormatCd'])
            ->where('state', LeagueState::Active)
            ->where('id', '!=', $league->id)
            ->where('started_at', '<', $league->started_at)
            ->update(['state' => LeagueState::Partial]);
    }
}
```

Note: The partial marking now targets leagues with the same **token** (not just same format), which is more precise — it marks previous runs of the same league entry as partial, not unrelated leagues.

- [ ] **Step 2: Update phantom league to also set deck_version_id**

In `findOrCreatePhantomLeague` (line 425), add `deck_version_id` to the create attributes. This requires passing it through. Update `assignLeague` line 381:

```php
$league = self::findOrCreatePhantomLeague($gameMeta, $deckId, $match->deck_version_id);
```

Update `findOrCreatePhantomLeague` signature (line 406):

```php
private static function findOrCreatePhantomLeague(array $gameMeta, ?int $deckId, ?int $deckVersionId = null): League
```

Add `'deck_version_id' => $deckVersionId` to the create array (line 425):

```php
return League::create([
    'token' => Str::random(),
    'format' => $gameMeta['PlayFormatCd'],
    'phantom' => true,
    'deck_version_id' => $deckVersionId,
    'started_at' => now(),
    'name' => 'Phantom '.trim(($gameMeta['GameStructureCd'] ?? '').' League '.now()->format('d-m-Y h:ma')),
]);
```

- [ ] **Step 3: Run tests to verify they pass**

Run: `php artisan test --compact --filter=AssignLeagueTest`

Expected: All tests pass.

- [ ] **Step 4: Run existing AdvanceMatchState tests to check for regressions**

Run: `php artisan test --compact --filter=AdvanceMatchState`

Expected: All existing tests still pass.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Matches/AdvanceMatchState.php tests/Feature/Actions/Matches/AssignLeagueTest.php
git commit -m "feat: split league runs by deck version — same token + different deck = new run"
```

---

### Task 6: Update Dashboard Controller (`buildActiveLeague`)

**Files:**
- Modify: `app/Http/Controllers/IndexController.php:73-113`
- Create: `tests/Feature/Controllers/LeagueDashboardTest.php`

@pest-testing

- [ ] **Step 1: Write test for dashboard active league**

Run: `php artisan make:test --pest Controllers/LeagueDashboardTest --no-interaction`

```php
<?php

use App\Enums\LeagueState;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;

it('shows deck name and version label from league relationship', function () {
    $deck = Deck::factory()->create(['name' => 'Jund Snow']);
    $version1 = DeckVersion::factory()->create(['deck_id' => $deck->id, 'modified_at' => now()->subDays(5)]);
    $version2 = DeckVersion::factory()->create(['deck_id' => $deck->id, 'modified_at' => now()]);

    $league = League::factory()->create([
        'deck_version_id' => $version2->id,
        'state' => LeagueState::Active,
    ]);

    MtgoMatch::factory()->won()->create([
        'league_id' => $league->id,
        'deck_version_id' => $version2->id,
        'started_at' => now(),
    ]);

    $response = $this->get('/');

    $response->assertInertia(fn ($page) => $page
        ->component('Index')
        ->has('activeLeague')
        ->where('activeLeague.deckName', 'Jund Snow')
        ->where('activeLeague.versionLabel', 'v2')
    );
});

it('shows separate league runs for same token with different decks', function () {
    $deck1 = Deck::factory()->create(['name' => 'Jund Snow']);
    $deck2 = Deck::factory()->create(['name' => 'Jeskai Control']);
    $version1 = DeckVersion::factory()->create(['deck_id' => $deck1->id]);
    $version2 = DeckVersion::factory()->create(['deck_id' => $deck2->id]);

    $league1 = League::factory()->partial()->create([
        'token' => 'same-token',
        'deck_version_id' => $version1->id,
        'started_at' => now()->subDay(),
    ]);
    $league2 = League::factory()->create([
        'token' => 'same-token',
        'deck_version_id' => $version2->id,
        'started_at' => now(),
    ]);

    MtgoMatch::factory()->lost()->count(2)->create([
        'league_id' => $league1->id,
        'deck_version_id' => $version1->id,
        'started_at' => now()->subDay(),
    ]);
    MtgoMatch::factory()->won()->create([
        'league_id' => $league2->id,
        'deck_version_id' => $version2->id,
        'started_at' => now(),
    ]);

    $response = $this->get('/');

    $response->assertInertia(fn ($page) => $page
        ->component('Index')
        ->where('activeLeague.deckName', 'Jeskai Control')
        ->where('activeLeague.wins', 1)
        ->where('activeLeague.losses', 0)
    );
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=LeagueDashboardTest`

- [ ] **Step 3: Update `buildActiveLeague` method**

Replace `app/Http/Controllers/IndexController.php` lines 73-113:

```php
private function buildActiveLeague(): ?array
{
    $league = League::whereHas('matches', function ($q) {
        $q->complete();
        if ($id = $this->activeAccountId()) {
            $q->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)));
        }
    })
        ->with(['deckVersion.deck'])
        ->latest('started_at')
        ->first();

    if (! $league) {
        return null;
    }

    $matches = MtgoMatch::complete()->where('league_id', $league->id)
        ->latest('started_at')
        ->take(5)
        ->get()
        ->reverse()
        ->values();

    $wins = $matches->filter(fn ($m) => $m->games_won > $m->games_lost)->count();
    $losses = $matches->filter(fn ($m) => $m->games_won <= $m->games_lost)->count();

    // Derive version label from chronological position within the deck's versions
    $versionLabel = null;
    if ($league->deckVersion) {
        $versionIndex = $league->deckVersion->deck->versions()
            ->where('modified_at', '<=', $league->deckVersion->modified_at)
            ->count();
        $versionLabel = 'v'.$versionIndex;
    }

    return [
        'name' => $league->name,
        'format' => MtgoMatch::displayFormat($league->format),
        'phantom' => $league->phantom,
        'isActive' => $matches->count() < 5,
        'isTrophy' => $wins === 5,
        'deckName' => $league->deckVersion?->deck?->name ?? $matches->last()?->deck?->name,
        'versionLabel' => $versionLabel,
        'results' => $matches
            ->map(fn ($m) => $m->games_won > $m->games_lost ? 'W' : 'L')
            ->pad(5, null)
            ->values()
            ->toArray(),
        'wins' => $wins,
        'losses' => $losses,
        'matchesRemaining' => 5 - $matches->count(),
    ];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=LeagueDashboardTest`

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/IndexController.php tests/Feature/Controllers/LeagueDashboardTest.php
git commit -m "feat: dashboard active league uses league's deck version instead of deriving from matches"
```

---

### Task 7: Update Overlay Controller

**Files:**
- Modify: `app/Http/Controllers/Leagues/OverlayController.php:45-49`

- [ ] **Step 1: Replace match-derived deck name with league relationship**

Replace lines 45-49:

```php
$deckName = $league->matches()
    ->whereNotNull('deck_version_id')
    ->with('deck')
    ->first()
    ?->deck?->name;
```

With:

```php
$deckName = $league->deckVersion?->deck?->name
    ?? $league->matches()
        ->whereNotNull('deck_version_id')
        ->with('deck')
        ->first()
        ?->deck?->name;
```

- [ ] **Step 2: Run existing tests**

Run: `php artisan test --compact`

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Leagues/OverlayController.php
git commit -m "feat: overlay uses league's deck version for deck name"
```

---

### Task 8: Update Leagues Index Controller

**Files:**
- Modify: `app/Http/Controllers/Leagues/IndexController.php:66-76`

- [ ] **Step 1: Use league's deck version when available**

Replace the deck derivation block (lines 69-76) inside the `$leagues->map()` closure:

```php
$matches = $matchesByLeague[$league->id] ?? collect();

// Use the most common deck across the run's matches
$deck = $matches->groupBy('deck_id')
    ->map->count()
    ->sortDesc()
    ->keys()
    ->map(fn ($deckId) => $matches->firstWhere('deck_id', $deckId))
    ->map(fn ($row) => ['id' => $row->deck_id, 'name' => $row->deck_name, 'colorIdentity' => $row->deck_color_identity])
    ->first();
```

With:

```php
$matches = $matchesByLeague[$league->id] ?? collect();

// Prefer league's direct deck version; fall back to most common deck in matches
if ($league->deck_version_id && $league->deckVersion?->deck) {
    $deckModel = $league->deckVersion->deck;
    $deck = ['id' => $deckModel->id, 'name' => $deckModel->name, 'colorIdentity' => $deckModel->color_identity];
} else {
    $deck = $matches->groupBy('deck_id')
        ->map->count()
        ->sortDesc()
        ->keys()
        ->map(fn ($deckId) => $matches->firstWhere('deck_id', $deckId))
        ->map(fn ($row) => ['id' => $row->deck_id, 'name' => $row->deck_name, 'colorIdentity' => $row->deck_color_identity])
        ->first();
}
```

Also add eager loading to the league query (line 21-25). Add `->with(['deckVersion.deck'])`:

```php
$leagues = League::query()
    ->when($hidePhantom, fn ($q) => $q->where('phantom', false))
    ->whereHas('matches', fn ($q) => $q->where('state', 'complete')->whereNull('deleted_at'))
    ->with(['deckVersion.deck'])
    ->orderByDesc('started_at')
    ->get();
```

- [ ] **Step 2: Add version label to league run output**

In the return array (around line 101), add `versionLabel`:

```php
// Compute version label
$versionLabel = null;
if ($league->deckVersion) {
    $versionIndex = $league->deckVersion->deck->versions()
        ->where('modified_at', '<=', $league->deckVersion->modified_at)
        ->count();
    $versionLabel = 'v'.$versionIndex;
}

return [
    'id' => $league->id,
    'name' => $league->name,
    'format' => MtgoMatch::displayFormat($league->format),
    'phantom' => (bool) $league->phantom,
    'state' => $league->state?->value ?? 'active',
    'startedAt' => $league->started_at,
    'deck' => $deck,
    'versionLabel' => $versionLabel,
    'results' => $results,
    'matches' => $matchData,
];
```

- [ ] **Step 3: Run existing tests**

Run: `php artisan test --compact`

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Leagues/IndexController.php
git commit -m "feat: leagues index uses league deck version with version label"
```

---

### Task 9: Update Deck Show Controller

**Files:**
- Modify: `app/Http/Controllers/Decks/ShowController.php:205-258`

- [ ] **Step 1: Use league deck version in deck leagues tab**

Same pattern as Task 8. In the leagues tab mapping (around line 249-258), replace the deck derivation:

```php
// Prefer league's direct deck version; fall back to match-derived
if ($league->deck_version_id && $league->deckVersion?->deck) {
    $deckModel = $league->deckVersion->deck;
    $deck = ['id' => $deckModel->id, 'name' => $deckModel->name, 'colorIdentity' => $deckModel->color_identity];
} else {
    $deck = $matches->groupBy('deck_id')
        ->map->count()
        ->sortDesc()
        ->keys()
        ->map(fn ($deckId) => $matches->firstWhere('deck_id', $deckId))
        ->map(fn ($row) => ['id' => $row->deck_id, 'name' => $row->deck_name, 'colorIdentity' => $row->deck_color_identity])
        ->first();
}
```

Add eager loading to the league query on line 206:

```php
$leagues = League::whereHas('matches', fn ($q) => $q->whereIn('matches.id', $allMatchIds))
    ->with(['deckVersion.deck'])
    ->orderByDesc('started_at')
    ->get();
```

- [ ] **Step 2: Run existing tests**

Run: `php artisan test --compact`

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Decks/ShowController.php
git commit -m "feat: deck show page uses league deck version"
```

---

### Task 10: Frontend — Show Version Label

**Files:**
- Modify: `resources/js/pages/partials/DashboardLeague.vue`
- Modify: `resources/js/components/leagues/LeagueTable.vue`
- Modify: `resources/js/components/leagues/LeagueScreenshot.vue`

@inertia-vue-development @tailwindcss-development

- [ ] **Step 1: Update DashboardLeague.vue**

Add `versionLabel` to the type (line 8-19):

```typescript
type League = {
    name: string;
    format: string;
    phantom: boolean;
    isActive: boolean;
    isTrophy: boolean;
    deckName: string | null;
    versionLabel: string | null;
    results: ('W' | 'L' | null)[];
    wins: number;
    losses: number;
    matchesRemaining: number;
};
```

Display it next to the deck name (line 40):

```vue
<span class="text-lg font-semibold leading-tight">
    {{ league.deckName ?? league.name }}
    <span v-if="league.versionLabel" class="text-sm font-normal text-muted-foreground">{{ league.versionLabel }}</span>
</span>
```

- [ ] **Step 2: Update LeagueTable.vue**

Add `versionLabel` to the `LeagueRun` type (line 28-38):

```typescript
type LeagueRun = {
    id: number;
    name: string;
    format: string;
    deck: { id: number; name: string } | null;
    versionLabel: string | null;
    startedAt: string;
    results: ('W' | 'L' | null)[];
    phantom: boolean;
    state: 'active' | 'complete' | 'partial';
    matches: LeagueMatch[];
};
```

Display it next to the deck name (line 76-82), after the deck name span:

```vue
<span
    v-if="league.deck"
    class="cursor-pointer truncate text-sm font-medium text-primary hover:underline"
    @click="router.visit(DeckShowController({ deck: league.deck.id }).url)"
>
    {{ league.deck.name }}
</span>
<span v-if="league.versionLabel" class="text-xs text-muted-foreground">{{ league.versionLabel }}</span>
```

- [ ] **Step 3: Update LeagueScreenshot.vue type**

Add `versionLabel` to the `LeagueRun` type (line 13-23):

```typescript
type LeagueRun = {
    id: number;
    name: string;
    format: string;
    deck: { id: number; name: string; colorIdentity?: string | null } | null;
    versionLabel: string | null;
    startedAt: string;
    results: ('W' | 'L' | null)[];
    phantom: boolean;
    state: 'active' | 'complete' | 'partial';
    matches: LeagueMatch[];
};
```

- [ ] **Step 4: Verify frontend builds**

Run: `npm run build`

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/partials/DashboardLeague.vue resources/js/components/leagues/LeagueTable.vue resources/js/components/leagues/LeagueScreenshot.vue
git commit -m "feat: show deck version label in league displays"
```

---

### Task 11: Run Pint and Full Test Suite

- [ ] **Step 1: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 2: Run full test suite**

Run: `php artisan test --compact`

- [ ] **Step 3: Commit any formatting fixes**

```bash
git add -A
git commit -m "style: apply Pint formatting"
```
