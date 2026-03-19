# League Lifecycle Events — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Detect league join events from the MTGO log to create leagues proactively, so run boundaries are explicit rather than guessed from match data.

**Architecture:** Add a `league_joined` event type to `ClassifyLogEvent`. A new `ProcessLeagueEvents` action runs before `BuildMatches` in the pipeline — it finds unprocessed `league_joined` events, marks any active league for the same token as `Partial`, and creates a fresh league row. `AssignLeague` gains an `event_id` lookup as its preferred path, with existing composite key logic as fallback.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, SQLite

**Spec:** `docs/superpowers/specs/2026-03-18-league-lifecycle-events-design.md`

---

## File Structure

| File | Action | Purpose |
|------|--------|---------|
| `database/migrations/..._add_event_id_to_leagues_table.php` | Create | Add `event_id`, `joined_at` columns |
| `app/Enums/LogEventType.php` | Modify | Add `LEAGUE_JOINED` case |
| `app/Actions/Logs/ClassifyLogEvent.php` | Modify | Detect `league_joined` event type |
| `app/Actions/Leagues/ProcessLeagueEvents.php` | Create | Process unprocessed league join events → create leagues |
| `app/Jobs/ProcessLogEvents.php` | Modify | Call `ProcessLeagueEvents` before `BuildMatches` |
| `app/Actions/Matches/AssignLeague.php` | Modify | Prefer `event_id` lookup for real leagues |
| `app/Models/League.php` | Modify | Add `joined_at` cast |
| `tests/Feature/Actions/Logs/ClassifyLeagueEventsTest.php` | Create | Tests for league event classification |
| `tests/Feature/Actions/Leagues/ProcessLeagueEventsTest.php` | Create | Tests for league creation from join events |
| `tests/Feature/Actions/Matches/AssignLeagueTest.php` | Modify | Add event_id lookup tests |

---

### Task 1: Migration — Add `event_id` and `joined_at` to leagues

**Files:**
- Create: `database/migrations/..._add_event_id_to_leagues_table.php`
- Modify: `app/Models/League.php`

- [ ] **Step 1: Create migration**

Run: `php artisan make:migration add_event_id_to_leagues_table --table=leagues --no-interaction`

- [ ] **Step 2: Write migration**

```php
public function up(): void
{
    Schema::table('leagues', function (Blueprint $table) {
        $table->unsignedInteger('event_id')->nullable()->after('token')->index();
        $table->dateTime('joined_at')->nullable()->after('started_at');
    });
}

public function down(): void
{
    Schema::table('leagues', function (Blueprint $table) {
        $table->dropIndex(['event_id']);
        $table->dropColumn(['event_id', 'joined_at']);
    });
}
```

- [ ] **Step 3: Update League model**

Add `joined_at` to the casts in `app/Models/League.php`:

```php
protected $casts = [
    'started_at' => 'datetime',
    'joined_at' => 'datetime',
    'state' => LeagueState::class,
];
```

- [ ] **Step 4: Run migration**

Run: `php artisan migrate`

- [ ] **Step 5: Commit**

```bash
git add database/migrations/*add_event_id_to_leagues_table.php app/Models/League.php
git commit -m "feat: add event_id and joined_at columns to leagues table"
```

---

### Task 2: Classify league join events

**Files:**
- Modify: `app/Enums/LogEventType.php`
- Modify: `app/Actions/Logs/ClassifyLogEvent.php`
- Create: `tests/Feature/Actions/Logs/ClassifyLeagueEventsTest.php`

@pest-testing

- [ ] **Step 1: Add enum case**

Add to `app/Enums/LogEventType.php`:

```php
case LEAGUE_JOINED = 'league_joined';
```

- [ ] **Step 2: Write tests**

Run: `php artisan make:test --pest Actions/Logs/ClassifyLeagueEventsTest --no-interaction`

```php
<?php

use App\Actions\Logs\ClassifyLogEvent;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('classifies league join from GameDetailsView event', function () {
    $event = new LogEvent([
        'raw_text' => "12:24:23 [INF] (UI|Creating GameDetailsView) League\nEventToken=d2050286-53fd-4072-804f-190d6a3c030a\nEventId=10397\nCurrentState=WotC.MtGO.Client.Model.Play.LeagueEvent.League+LeagueGenericState\nPlayFormatCd=Modern\nGameStructureCd= Modern\nJoinedToGame=False",
        'context' => 'Creating GameDetailsView',
        'category' => 'UI',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('league_joined');
    expect($result->match_token)->toBe('d2050286-53fd-4072-804f-190d6a3c030a');
    expect($result->match_id)->toBe('10397');
});

it('does not classify non-league GameDetailsView events', function () {
    $event = new LogEvent([
        'raw_text' => "12:24:23 [INF] (UI|Creating GameDetailsView) Tournament\nEventToken=abc-123\nEventId=99999",
        'context' => 'Creating GameDetailsView',
        'category' => 'UI',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBeNull();
});

it('extracts format from league join event', function () {
    $event = new LogEvent([
        'raw_text' => "12:24:23 [INF] (UI|Creating GameDetailsView) League\nEventToken=abc-def\nEventId=12345\nPlayFormatCd=CPauper\nGameStructureCd= Pauper",
        'context' => 'Creating GameDetailsView',
        'category' => 'UI',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('league_joined');
    expect($result->match_token)->toBe('abc-def');
    expect($result->match_id)->toBe('12345');
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ClassifyLeagueEventsTest`

- [ ] **Step 4: Implement classification**

Add to `app/Actions/Logs/ClassifyLogEvent.php`, before the final `return $event`:

```php
// League joined — "(UI|Creating GameDetailsView) League" with EventToken and EventId
if (str_contains($text, 'Creating GameDetailsView') && str_contains($text, 'League') && str_contains($text, 'EventToken=')) {
    // Only classify as league join if it's actually a league (not a tournament)
    if (preg_match('/Creating GameDetailsView\) League\b/', $text)) {
        $eventToken = null;
        $eventId = null;

        if (preg_match('/EventToken=(\S+)/', $text, $m)) {
            $eventToken = $m[1];
        }
        if (preg_match('/EventId=(\d+)/', $text, $m)) {
            $eventId = $m[1];
        }

        if ($eventToken && $eventId) {
            return $event->fill([
                'event_type' => 'league_joined',
                'match_token' => $eventToken,  // Reuse match_token column for League Token
                'match_id' => $eventId,         // Reuse match_id column for Event ID
            ]);
        }
    }
}
```

**Note:** We reuse `match_token` for the League Token and `match_id` for the Event ID. These columns are nullable and indexed, and the `event_type` distinguishes the meaning. This avoids a migration to add league-specific columns to `log_events`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ClassifyLeagueEventsTest`

- [ ] **Step 6: Commit**

```bash
git add app/Enums/LogEventType.php app/Actions/Logs/ClassifyLogEvent.php tests/Feature/Actions/Logs/ClassifyLeagueEventsTest.php
git commit -m "feat: classify league join events from MTGO log"
```

---

### Task 3: ProcessLeagueEvents — Create leagues from join events

**Files:**
- Create: `app/Actions/Leagues/ProcessLeagueEvents.php`
- Create: `tests/Feature/Actions/Leagues/ProcessLeagueEventsTest.php`

@pest-testing

- [ ] **Step 1: Write tests**

Run: `php artisan make:test --pest Actions/Leagues/ProcessLeagueEventsTest --no-interaction`

```php
<?php

use App\Actions\Leagues\ProcessLeagueEvents;
use App\Enums\LeagueState;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createLeagueJoinEvent(array $overrides = []): LogEvent
{
    return LogEvent::create(array_merge([
        'file_path' => '/test/log.txt',
        'byte_offset_start' => rand(1, 999999),
        'byte_offset_end' => rand(1, 999999),
        'timestamp' => now(),
        'level' => 'INF',
        'category' => 'UI',
        'context' => 'Creating GameDetailsView',
        'raw_text' => "12:24:23 [INF] (UI|Creating GameDetailsView) League\nEventToken=test-league-token\nEventId=10397\nPlayFormatCd=Modern",
        'event_type' => 'league_joined',
        'match_token' => 'test-league-token',
        'match_id' => '10397',
        'ingested_at' => now(),
        'logged_at' => now(),
    ], $overrides));
}

it('creates a league from a join event', function () {
    createLeagueJoinEvent();

    ProcessLeagueEvents::run();

    $league = League::where('event_id', 10397)->first();
    expect($league)->not->toBeNull();
    expect($league->token)->toBe('test-league-token');
    expect($league->state)->toBe(LeagueState::Active);
    expect($league->phantom)->toBeFalsy();
    expect($league->joined_at)->not->toBeNull();
});

it('marks existing active league as partial on re-join', function () {
    $oldLeague = League::factory()->create([
        'token' => 'test-league-token',
        'event_id' => 10397,
        'state' => LeagueState::Active,
    ]);

    // Add a match to the old league so it's not empty
    MtgoMatch::factory()->create(['league_id' => $oldLeague->id]);

    createLeagueJoinEvent();
    ProcessLeagueEvents::run();

    $oldLeague->refresh();
    expect($oldLeague->state)->toBe(LeagueState::Partial);

    // New league created
    $newLeague = League::where('event_id', 10397)
        ->where('state', LeagueState::Active)
        ->first();
    expect($newLeague)->not->toBeNull();
    expect($newLeague->id)->not->toBe($oldLeague->id);
});

it('does not create duplicate league on repeated processing', function () {
    createLeagueJoinEvent();

    ProcessLeagueEvents::run();
    ProcessLeagueEvents::run();

    expect(League::where('event_id', 10397)->count())->toBe(1);
});

it('marks join event as processed', function () {
    $event = createLeagueJoinEvent();

    ProcessLeagueEvents::run();

    $event->refresh();
    expect($event->processed_at)->not->toBeNull();
});

it('does not mark empty active league as partial on re-join', function () {
    $emptyLeague = League::factory()->create([
        'token' => 'test-league-token',
        'event_id' => 10397,
        'state' => LeagueState::Active,
    ]);

    // No matches — this is the same join being processed again
    createLeagueJoinEvent();
    ProcessLeagueEvents::run();

    $emptyLeague->refresh();
    // Empty league should be reused, not marked partial
    expect($emptyLeague->state)->toBe(LeagueState::Active);
    expect(League::where('event_id', 10397)->count())->toBe(1);
});

it('extracts format from raw_text', function () {
    createLeagueJoinEvent([
        'raw_text' => "12:24:23 [INF] (UI|Creating GameDetailsView) League\nEventToken=abc\nEventId=555\nPlayFormatCd=CPauper",
        'match_token' => 'abc',
        'match_id' => '555',
    ]);

    ProcessLeagueEvents::run();

    $league = League::where('event_id', 555)->first();
    expect($league->format)->toBe('CPauper');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ProcessLeagueEventsTest`

- [ ] **Step 3: Implement ProcessLeagueEvents**

Create `app/Actions/Leagues/ProcessLeagueEvents.php`:

```php
<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueState;
use App\Models\League;
use App\Models\LogEvent;
use Illuminate\Support\Facades\Log;

class ProcessLeagueEvents
{
    public static function run(): void
    {
        $joinEvents = LogEvent::where('event_type', 'league_joined')
            ->whereNull('processed_at')
            ->orderBy('timestamp')
            ->get();

        foreach ($joinEvents as $event) {
            self::processJoin($event);
            $event->update(['processed_at' => now()]);
        }
    }

    private static function processJoin(LogEvent $event): void
    {
        $leagueToken = $event->match_token;
        $eventId = (int) $event->match_id;

        // Extract format from raw_text
        $format = 'Unknown';
        if (preg_match('/PlayFormatCd=(\S+)/', $event->raw_text, $m)) {
            $format = $m[1];
        }

        // Check for existing active league with this token
        $existingLeague = League::where('token', $leagueToken)
            ->where('state', LeagueState::Active)
            ->latest('started_at')
            ->first();

        if ($existingLeague) {
            // If the existing league has no matches, it's likely the same join
            // being re-processed (idempotent) — reuse it
            if ($existingLeague->matches()->count() === 0) {
                Log::channel('pipeline')->info("ProcessLeagueEvents: reusing empty active league #{$existingLeague->id} for event_id={$eventId}");

                return;
            }

            // Existing league has matches — this is a re-entry. Mark old as partial.
            $existingLeague->update(['state' => LeagueState::Partial]);

            Log::channel('pipeline')->info("ProcessLeagueEvents: marked league #{$existingLeague->id} as partial (re-entry detected)", [
                'event_id' => $eventId,
                'old_matches' => $existingLeague->matches()->count(),
            ]);
        }

        // Create new league row
        $league = League::create([
            'token' => $leagueToken,
            'event_id' => $eventId,
            'format' => $format,
            'phantom' => false,
            'state' => LeagueState::Active,
            'started_at' => $event->timestamp,
            'joined_at' => $event->timestamp,
            'name' => trim("League {$event->timestamp->format('d-m-Y h:ma')}"),
        ]);

        Log::channel('pipeline')->info("ProcessLeagueEvents: created league #{$league->id}", [
            'event_id' => $eventId,
            'token' => $leagueToken,
            'format' => $format,
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ProcessLeagueEventsTest`

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Leagues/ProcessLeagueEvents.php tests/Feature/Actions/Leagues/ProcessLeagueEventsTest.php
git commit -m "feat: create leagues proactively from join events"
```

---

### Task 4: Wire into pipeline

**Files:**
- Modify: `app/Jobs/ProcessLogEvents.php`

- [ ] **Step 1: Add ProcessLeagueEvents call before BuildMatches**

Update `app/Jobs/ProcessLogEvents.php`:

```php
<?php

namespace App\Jobs;

use App\Actions\Leagues\ProcessLeagueEvents;
use App\Actions\Matches\BuildMatches;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessLogEvents implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 2;

    public function __construct() {}

    public function handle(): void
    {
        ProcessLeagueEvents::run();
        BuildMatches::run();
    }
}
```

- [ ] **Step 2: Run full test suite**

Run: `php artisan test --compact`

- [ ] **Step 3: Commit**

```bash
git add app/Jobs/ProcessLogEvents.php
git commit -m "feat: process league events before building matches in pipeline"
```

---

### Task 5: Update AssignLeague — prefer event_id lookup

**Files:**
- Modify: `app/Actions/Matches/AssignLeague.php`
- Modify: `tests/Feature/Actions/Matches/AssignLeagueTest.php`

@pest-testing

- [ ] **Step 1: Write new tests**

Add to `tests/Feature/Actions/Matches/AssignLeagueTest.php`:

```php
/*
|--------------------------------------------------------------------------
| Event ID Lookup
|--------------------------------------------------------------------------
*/

it('finds league by event_id when available in gameMeta', function () {
    $deckVersion = DeckVersion::factory()->create();

    $league = League::factory()->create([
        'token' => 'league-token-123',
        'event_id' => 10397,
        'state' => LeagueState::Active,
    ]);

    $match = makeMatchWithDeck($deckVersion);

    $gameMeta = defaultGameMeta();
    $gameMeta['EventId'] = '10397';

    callAssignLeague($match, $gameMeta);

    $match->refresh();
    expect($match->league_id)->toBe($league->id);
});

it('falls back to composite key when event_id not in gameMeta', function () {
    $deckVersion = DeckVersion::factory()->create();

    $league = League::factory()->create([
        'token' => 'league-token-123',
        'event_id' => 10397,
        'deck_version_id' => $deckVersion->id,
        'state' => LeagueState::Active,
    ]);

    $match = makeMatchWithDeck($deckVersion);

    // No EventId in gameMeta — falls back to token + deck_version_id
    callAssignLeague($match, defaultGameMeta());

    $match->refresh();
    expect($match->league_id)->toBe($league->id);
});

it('creates league reactively when no pre-existing league found', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = makeMatchWithDeck($deckVersion);

    // No league exists — safety net creates one
    callAssignLeague($match, defaultGameMeta());

    $match->refresh();
    expect($match->league_id)->not->toBeNull();
    expect($match->league->token)->toBe('league-token-123');
});
```

- [ ] **Step 2: Run tests to verify new tests fail**

Run: `php artisan test --compact --filter=AssignLeagueTest`

- [ ] **Step 3: Update AssignLeague**

Replace the real league block in `app/Actions/Matches/AssignLeague.php` `run()` method. The section that starts with `if (! empty($gameMeta['League Token']))`:

```php
if (! empty($gameMeta['League Token'])) {
    $league = null;

    // 1. Best path: find by event_id (set by ProcessLeagueEvents)
    if (! empty($gameMeta['EventId'])) {
        $league = League::where('event_id', (int) $gameMeta['EventId'])
            ->where('state', '!=', LeagueState::Complete)
            ->latest('started_at')
            ->first();
    }

    // 2. Fallback: find by token + deck_version_id
    if (! $league) {
        $leagueKey = [
            'token' => $gameMeta['League Token'],
            'format' => $gameMeta['PlayFormatCd'],
        ];

        if ($match->deck_version_id) {
            $leagueKey['deck_version_id'] = $match->deck_version_id;
        }

        $league = League::where($leagueKey)
            ->where('state', '!=', LeagueState::Complete)
            ->latest('started_at')
            ->first();
    }

    // 3. Safety net: create reactively
    $isNew = false;
    if (! $league) {
        $league = League::create([
            'token' => $gameMeta['League Token'],
            'format' => $gameMeta['PlayFormatCd'],
            'deck_version_id' => $match->deck_version_id,
            'started_at' => now(),
            'name' => trim(($gameMeta['GameStructureCd'] ?? '').' League '.now()->format('d-m-Y h:ma')),
        ]);
        $isNew = true;
    }

    if ($isNew) {
        // Mark older active leagues with the same token as partial
        League::where('token', $gameMeta['League Token'])
            ->where('format', $gameMeta['PlayFormatCd'])
            ->where('state', LeagueState::Active)
            ->where('id', '!=', $league->id)
            ->where('started_at', '<=', $league->started_at)
            ->update(['state' => LeagueState::Partial]);
    }
}
```

- [ ] **Step 4: Run all AssignLeague tests**

Run: `php artisan test --compact --filter=AssignLeagueTest`

- [ ] **Step 5: Run full test suite**

Run: `php artisan test --compact`

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Matches/AssignLeague.php tests/Feature/Actions/Matches/AssignLeagueTest.php
git commit -m "feat: AssignLeague prefers event_id lookup with composite key fallback"
```

---

### Task 6: Copy log fixture + Run Pint + Full Test Suite

- [ ] **Step 1: Copy raw log file as test fixture**

```bash
cp storage/app/mtgo.log tests/fixtures/mtgo_league_join_drop.log
```

This preserves the real log data with league join/drop patterns for future integration tests.

- [ ] **Step 2: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 3: Run full test suite**

Run: `php artisan test --compact`

Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
git add tests/fixtures/mtgo_league_join_drop.log
git commit -m "feat: add raw log fixture with league join/drop patterns"
```

If Pint made changes:

```bash
git add -A
git commit -m "style: apply Pint formatting"
```
