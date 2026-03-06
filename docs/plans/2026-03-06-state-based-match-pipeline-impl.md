# State-Based Match Pipeline — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Refactor the match pipeline from one-shot build to incremental state advancement (`started → in_progress → ended → complete`), with event-driven processing replacing 30s polling.

**Architecture:** Add a `state` column to matches, replace `BuildMatch` with `AdvanceMatchState` (idempotent, incremental), fire a `LogEventsIngested` event from `IngestLog` to trigger processing immediately. Existing UI consumers filter on `complete` only.

**Tech Stack:** PHP 8.4, Laravel 12, Pest v4, SQLite, NativePHP 2.0

---

### Task 1: MatchState Enum

**Files:**
- Create: `app/Enums/MatchState.php`

**Step 1: Create the enum**

```php
<?php

namespace App\Enums;

enum MatchState: string
{
    case Started = 'started';
    case InProgress = 'in_progress';
    case Ended = 'ended';
    case Complete = 'complete';
}
```

**Step 2: Commit**

```bash
git add app/Enums/MatchState.php
git commit -m "Add MatchState enum"
```

---

### Task 2: Migration — add state column to matches

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_state_to_matches_table.php`

**Step 1: Generate migration**

```bash
php artisan make:migration add_state_to_matches_table --no-interaction
```

**Step 2: Write the migration**

```php
public function up(): void
{
    Schema::table('matches', function (Blueprint $table) {
        $table->string('state')->default('complete')->index()->after('match_type');
    });
}

public function down(): void
{
    Schema::table('matches', function (Blueprint $table) {
        $table->dropIndex(['state']);
        $table->dropColumn('state');
    });
}
```

Default is `complete` so all existing matches remain valid.

**Step 3: Run migration**

```bash
php artisan migrate
```

Expected: Migration runs successfully, existing matches get `state = 'complete'`.

**Step 4: Commit**

```bash
git add database/migrations/*add_state_to_matches_table*
git commit -m "Add state column to matches table"
```

---

### Task 3: Update MtgoMatch model

**Files:**
- Modify: `app/Models/MtgoMatch.php`

**Step 1: Add state cast, scope, and update isCompleted**

Changes to `app/Models/MtgoMatch.php`:

1. Add import for `App\Enums\MatchState` at top
2. Add `'state' => MatchState::class` to `$casts` array (line 19-25)
3. Replace `isCompleted()` method (line 79-82) with:

```php
public function isCompleted(): bool
{
    return $this->state === MatchState::Complete;
}
```

4. Add `scopeComplete` method after `scopeSubmittable`:

```php
public function scopeComplete(Builder $query): Builder
{
    return $query->where('state', MatchState::Complete);
}

public function scopeIncomplete(Builder $query): Builder
{
    return $query->where('state', '!=', MatchState::Complete);
}
```

5. Update `scopeSubmittable` (line 67-72) to include state filter:

```php
public function scopeSubmittable(Builder $query): Builder
{
    return $query->where('state', MatchState::Complete)
        ->whereNull('submitted_at')
        ->whereNotNull('deck_version_id')
        ->whereHas('archetypes');
}
```

**Step 2: Commit**

```bash
git add app/Models/MtgoMatch.php
git commit -m "Add MatchState cast, scopes, and update isCompleted"
```

---

### Task 4: Update Deck model relationships

**Files:**
- Modify: `app/Models/Deck.php:28-41`

**Step 1: Add state filter to match relationships**

Add import for `App\Enums\MatchState` at top.

Update the three relationship methods:

```php
public function matches(): HasManyThrough
{
    return $this->hasManyThrough(MtgoMatch::class, DeckVersion::class, 'deck_id', 'deck_version_id')
        ->where('state', MatchState::Complete);
}

public function lostMatches(): HasManyThrough
{
    return $this->matches()->whereRaw('games_lost > games_won');
}

public function wonMatches(): HasManyThrough
{
    return $this->matches()->whereRaw('games_lost < games_won');
}
```

Note: `lostMatches` and `wonMatches` inherit the state filter from `matches()`.

**Step 2: Commit**

```bash
git add app/Models/Deck.php
git commit -m "Filter Deck match relationships to complete state"
```

---

### Task 5: Update DeckVersion model relationship

**Files:**
- Modify: `app/Models/DeckVersion.php:33-36`

**Step 1: Add state filter**

Add import for `App\Enums\MatchState` at top.

```php
public function matches(): HasMany
{
    return $this->hasMany(MtgoMatch::class, 'deck_version_id')
        ->where('state', MatchState::Complete);
}
```

**Step 2: Commit**

```bash
git add app/Models/DeckVersion.php
git commit -m "Filter DeckVersion matches to complete state"
```

---

### Task 6: Update consumer queries — Controllers

**Files:**
- Modify: `app/Http/Controllers/IndexController.php`
- Modify: `app/Http/Controllers/Leagues/IndexController.php`
- Modify: `app/Http/Controllers/Opponents/IndexController.php`
- Modify: `app/Http/Controllers/Decks/ShowController.php`
- Modify: `app/Actions/Decks/GetArchetypeMatchupSpread.php`
- Modify: `app/Console/Commands/SyncMatchArchetypes.php`

**Step 1: IndexController (dashboard)**

Add import for `App\Enums\MatchState`.

Line 21 — stats query, add `->complete()`:
```php
$stats = MtgoMatch::complete()->whereBetween('started_at', [$start, $end])
```

Line 34 — recent matches, add `->complete()`:
```php
$recentMatches = MtgoMatch::complete()->with([...])
```

Line 66 — buildActiveLeague, the League query is fine (leagues only have complete matches currently), but the match query on line 72 needs `->complete()`:
```php
$matches = MtgoMatch::complete()->where('league_id', $league->id)
```

Line 103 — buildFormatChart, add `->complete()`:
```php
$rows = MtgoMatch::complete()
    ->selectRaw(...)
```

**Step 2: Leagues/IndexController**

Line 31 — raw DB query, add state filter:
```php
$matchRows = DB::table('matches as m')
    ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
    ->join('decks as d', 'd.id', '=', 'dv.deck_id')
    ->whereIn('m.league_id', $leagueIds)
    ->whereNull('m.deleted_at')
    ->where('m.state', 'complete')
    // ... rest unchanged
```

**Step 3: Opponents/IndexController**

Line 16 — raw DB query, add state filter:
```php
$opponentMatches = DB::table('game_player as gp')
    ->join('players as p', 'p.id', '=', 'gp.player_id')
    ->join('games as g', 'g.id', '=', 'gp.game_id')
    ->join('matches as m', 'm.id', '=', 'g.match_id')
    ->where('gp.is_local', false)
    ->whereNull('m.deleted_at')
    ->where('m.state', 'complete')
    // ... rest unchanged
```

**Step 4: Decks/ShowController**

Line 42 — matchesQuery already goes through `$deck->matches()` which now has the state filter. No change needed here.

Line 322 (buildDeckChartData) — raw MtgoMatch query, add `->complete()`:
```php
$query = MtgoMatch::complete()
    ->selectRaw(...)
```

Line 337 — firstMatch query, add `->complete()`:
```php
$firstMatch = MtgoMatch::complete()->whereIn('deck_version_id', $versionIds)
```

**Step 5: GetArchetypeMatchupSpread**

Line 15 — raw DB query, add state filter:
```php
$query = DB::table('matches as m')
    ->join('match_archetypes as ma', 'ma.mtgo_match_id', '=', 'm.id')
    ->join('archetypes as a', 'a.id', '=', 'ma.archetype_id')
    ->whereIn('m.deck_version_id', $deckVersions->toArray())
    ->where('m.state', 'complete');
```

**Step 6: SyncMatchArchetypes**

Line 30 — add `complete()` scope:
```php
$matches = MtgoMatch::complete()->get();
```

**Step 7: Commit**

```bash
git add app/Http/Controllers/IndexController.php \
    app/Http/Controllers/Leagues/IndexController.php \
    app/Http/Controllers/Opponents/IndexController.php \
    app/Http/Controllers/Decks/ShowController.php \
    app/Actions/Decks/GetArchetypeMatchupSpread.php \
    app/Console/Commands/SyncMatchArchetypes.php
git commit -m "Filter all consumer queries to complete matches only"
```

---

### Task 7: LogEventsIngested Event + Listener

**Files:**
- Create: `app/Events/LogEventsIngested.php`
- Create: `app/Listeners/DispatchProcessLogEvents.php`

**Step 1: Create the event**

```php
<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class LogEventsIngested
{
    use Dispatchable;
}
```

**Step 2: Create the listener**

```php
<?php

namespace App\Listeners;

use App\Events\LogEventsIngested;
use App\Jobs\ProcessLogEvents;

class DispatchProcessLogEvents
{
    public function handle(LogEventsIngested $event): void
    {
        ProcessLogEvents::dispatch();
    }
}
```

**Step 3: Register in EventServiceProvider or bootstrap**

Check if `app/Providers/EventServiceProvider.php` exists. If using Laravel 12 auto-discovery, the listener should be auto-discovered. If not, register manually.

Alternatively, add to `bootstrap/app.php` or use the `Event::listen()` approach in `AppServiceProvider`.

**Step 4: Commit**

```bash
git add app/Events/LogEventsIngested.php app/Listeners/DispatchProcessLogEvents.php
git commit -m "Add LogEventsIngested event and listener"
```

---

### Task 8: Update ProcessLogEvents job — ShouldBeUnique

**Files:**
- Modify: `app/Jobs/ProcessLogEvents.php`

**Step 1: Make the job unique**

```php
<?php

namespace App\Jobs;

use App\Actions\Matches\BuildMatches;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessLogEvents implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 10;

    public function __construct() {}

    public function handle(): void
    {
        BuildMatches::run();
    }
}
```

`uniqueFor = 10` means if the job is already queued/running, duplicates are dropped for 10 seconds.

**Step 2: Commit**

```bash
git add app/Jobs/ProcessLogEvents.php
git commit -m "Make ProcessLogEvents a unique job"
```

---

### Task 9: Fire event from IngestLog

**Files:**
- Modify: `app/Actions/Logs/IngestLog.php:160-164`

**Step 1: Dispatch event after inserting rows**

Add import for `App\Events\LogEventsIngested` at top.

After the row insertion block (line 160-164), fire the event:

```php
if (! empty($rows)) {
    foreach (array_chunk($rows, 500) as $chunk) {
        LogEvent::query()->insertOrIgnore($chunk);
    }

    LogEventsIngested::dispatch();
}
```

**Step 2: Commit**

```bash
git add app/Actions/Logs/IngestLog.php
git commit -m "Fire LogEventsIngested event after log ingestion"
```

---

### Task 10: Update schedule — replace 30s polling with 60s fallback

**Files:**
- Modify: `app/Managers/MtgoManager.php:214-216`

**Step 1: Change schedule interval**

Replace lines 214-216:
```php
$schedule->call(
    fn () => $this->processLogEvents()
)->everyThirtySeconds()->name('process_log_events')->withoutOverlapping(30);
```

With:
```php
$schedule->call(
    fn () => $this->processLogEvents()
)->everyMinute()->name('process_log_events_fallback')->withoutOverlapping(60);
```

**Step 2: Commit**

```bash
git add app/Managers/MtgoManager.php
git commit -m "Replace 30s polling with 60s fallback schedule"
```

---

### Task 11: AdvanceMatchState action — foundation + tests

This is the core refactor. We replace `BuildMatch` action with `AdvanceMatchState`. The new action is idempotent and incremental.

**Files:**
- Create: `app/Actions/Matches/AdvanceMatchState.php`
- Create: `tests/Feature/Actions/Matches/AdvanceMatchStateTest.php`
- Modify: `app/Jobs/BuildMatch.php`

**Step 1: Write the failing tests**

Create `tests/Feature/Actions/Matches/AdvanceMatchStateTest.php`:

```php
<?php

use App\Actions\Matches\AdvanceMatchState;
use App\Enums\MatchState;
use App\Models\MtgoMatch;

it('does not create a match without a join event', function () {
    // Create log events with a match token but no MatchJoinedEventUnderwayState
    $token = 'test-token-123';
    $matchId = 99999;

    \App\Models\LogEvent::create([
        'file_path' => '/test/log',
        'byte_offset_start' => 0,
        'byte_offset_end' => 100,
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Match',
        'context' => 'SomeOtherState',
        'raw_text' => 'test',
        'event_type' => 'match_state_changed',
        'logged_at' => now(),
        'match_id' => $matchId,
        'match_token' => $token,
    ]);

    $result = AdvanceMatchState::run($token, $matchId);

    expect($result)->toBeNull();
    expect(MtgoMatch::count())->toBe(0);
});

it('creates a match in started state when join event exists', function () {
    $token = 'test-token-456';
    $matchId = 88888;

    \App\Models\LogEvent::create([
        'file_path' => '/test/log',
        'byte_offset_start' => 0,
        'byte_offset_end' => 500,
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Match',
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => "12:00:00 [INF] (Match|MatchJoinedEventUnderwayState)\nPlayFormatCd = Pmodern\nGameStructureCd = Constructed\nLeague Token = league-abc",
        'event_type' => 'match_state_changed',
        'logged_at' => now(),
        'match_id' => $matchId,
        'match_token' => $token,
    ]);

    $result = AdvanceMatchState::run($token, $matchId);

    expect($result)->toBeInstanceOf(MtgoMatch::class);
    expect($result->state)->toBe(MatchState::Started);
    expect($result->mtgo_id)->toBe((string) $matchId);
    expect($result->token)->toBe($token);
});

it('is idempotent — running twice does not duplicate', function () {
    $token = 'test-token-789';
    $matchId = 77777;

    \App\Models\LogEvent::create([
        'file_path' => '/test/log',
        'byte_offset_start' => 0,
        'byte_offset_end' => 500,
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Match',
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => "12:00:00 [INF] (Match|MatchJoinedEventUnderwayState)\nPlayFormatCd = Pmodern\nGameStructureCd = Constructed",
        'event_type' => 'match_state_changed',
        'logged_at' => now(),
        'match_id' => $matchId,
        'match_token' => $token,
    ]);

    AdvanceMatchState::run($token, $matchId);
    AdvanceMatchState::run($token, $matchId);

    expect(MtgoMatch::where('mtgo_id', $matchId)->count())->toBe(1);
});

it('does not regress state', function () {
    $match = MtgoMatch::create([
        'mtgo_id' => 66666,
        'token' => 'test-token-no-regress',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'state' => MatchState::Complete,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    $result = AdvanceMatchState::run($match->token, $match->mtgo_id);

    expect($result->state)->toBe(MatchState::Complete);
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --filter=AdvanceMatchState --compact
```

Expected: FAIL — `AdvanceMatchState` class does not exist.

**Step 3: Write AdvanceMatchState action**

Create `app/Actions/Matches/AdvanceMatchState.php`:

```php
<?php

namespace App\Actions\Matches;

use App\Actions\DetermineMatchArchetypes;
use App\Actions\Util\ExtractJson;
use App\Actions\Util\ExtractKeyValueBlock;
use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Jobs\SubmitMatch;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Native\Desktop\Facades\Notification;
use Native\Desktop\Facades\Settings;

class AdvanceMatchState
{
    /**
     * Advance a match through its state machine.
     * Idempotent — safe to call multiple times for the same match.
     * Returns null if match cannot be created (no join event).
     */
    public static function run(string $matchToken, int|string $matchId): ?MtgoMatch
    {
        $match = MtgoMatch::where('mtgo_id', $matchId)->first();

        // If match already complete, nothing to do
        if ($match && $match->state === MatchState::Complete) {
            return $match;
        }

        $events = LogEvent::where('match_id', $matchId)->orderBy('timestamp')->get();
        $stateChanges = LogEvent::where('match_token', $matchToken)
            ->where('event_type', LogEventType::MATCH_STATE_CHANGED->value)
            ->get()
            ->values();

        $joinedState = $events->first(
            fn (LogEvent $event) => str_contains($event->context, 'MatchJoinedEventUnderwayState')
        );

        // Gate: no join event = not our match (or not yet ingested)
        if (! $joinedState) {
            return null;
        }

        // Create match if it doesn't exist
        if (! $match) {
            $gameMeta = ExtractKeyValueBlock::run($joinedState->raw_text);

            $match = MtgoMatch::create([
                'mtgo_id' => $matchId,
                'token' => $matchToken,
                'format' => $gameMeta['PlayFormatCd'] ?? 'Unknown',
                'match_type' => $gameMeta['GameStructureCd'] ?? 'Unknown',
                'state' => MatchState::Started,
                'started_at' => now()->parse($joinedState->logged_at)
                    ->setTimeFromTimeString($joinedState->timestamp),
                'ended_at' => now()->parse($joinedState->logged_at)
                    ->setTimeFromTimeString($joinedState->timestamp),
            ]);
        }

        // Advance through states — each method returns true if it advanced
        if ($match->state === MatchState::Started) {
            static::tryAdvanceToInProgress($match, $events, $stateChanges);
        }

        if ($match->state === MatchState::InProgress) {
            static::tryAdvanceToEnded($match, $stateChanges);
        }

        if ($match->state === MatchState::Ended) {
            static::tryAdvanceToComplete($match, $events, $stateChanges);
        }

        return $match->fresh();
    }

    /**
     * started → in_progress
     * Trigger: game_state_update events exist for this match
     * Actions: create games, link deck, assign league
     */
    private static function tryAdvanceToInProgress(MtgoMatch $match, $events, $stateChanges): void
    {
        $gameStateEvents = $events->filter(
            fn (LogEvent $e) => $e->event_type === LogEventType::GAME_STATE_UPDATE->value
        );

        if ($gameStateEvents->isEmpty()) {
            return;
        }

        $joinedState = $events->first(
            fn (LogEvent $event) => str_contains($event->context, 'MatchJoinedEventUnderwayState')
        );
        $gameMeta = ExtractKeyValueBlock::run($joinedState->raw_text);

        DB::beginTransaction();

        // Create games if they don't already exist
        if ($match->games()->count() === 0) {
            $games = $events->groupBy('game_id')->filter(fn ($g, $key) => $key !== null && $key !== '');
            $gameIds = $games->keys();

            $decksEvents = LogEvent::where('event_type', 'deck_used')
                ->whereIn('game_id', $gameIds)->get();

            $gameIndex = 0;
            foreach ($games as $gameId => $gameEvents) {
                $playerDeck = $decksEvents->first(
                    fn ($event) => (int) $event->game_id === (int) $gameId
                );

                $deckJson = $playerDeck ? (ExtractJson::run($playerDeck->raw_text)->first() ?: []) : [];

                CreateGames::run($match, $gameId, $gameEvents, $gameIndex, $deckJson);
                $gameIndex++;
            }
        }

        // Link deck
        if (! $match->deck_version_id) {
            DetermineMatchDeck::run($match);
        }

        // Assign league
        if (! $match->league_id) {
            if (! empty($gameMeta['League Token'])) {
                $league = League::firstOrCreate([
                    'token' => $gameMeta['League Token'],
                    'format' => $gameMeta['PlayFormatCd'],
                ], [
                    'started_at' => now(),
                    'name' => trim(($gameMeta['GameStructureCd'] ?? '') . ' League ' . now()->format('d-m-Y h:ma')),
                ]);
            } elseif (! Settings::get('hide_phantom_leagues')) {
                $match->refresh();
                $deckId = $match->deck_version_id
                    ? DeckVersion::find($match->deck_version_id)?->deck_id
                    : null;
                $league = static::findOrCreatePhantomLeague($gameMeta, $deckId);
            } else {
                $league = null;
            }

            if ($league) {
                $match->update(['league_id' => $league->id]);
            }
        }

        $match->update(['state' => MatchState::InProgress]);

        DB::commit();
    }

    /**
     * in_progress → ended
     * Trigger: match end signal detected
     */
    private static function tryAdvanceToEnded(MtgoMatch $match, $stateChanges): void
    {
        $matchEnded = $stateChanges->first(
            fn (LogEvent $event) => str_contains($event->context, 'TournamentMatchClosedState')
                || str_contains($event->context, 'MatchCompletedState')
                || str_contains($event->context, 'MatchEndedState')
                || str_contains($event->context, 'MatchClosedState')
        );

        $idxConcede = $stateChanges->search(fn ($e) => str_contains($e->context ?? '', 'MatchConcedeReq'));
        $idxNotJoined = $stateChanges->search(fn ($e) => str_contains($e->context ?? '', 'MatchNotJoinedUnderway'));
        $idxConcedeFulfilled = $stateChanges->search(fn ($e) => str_contains($e->context ?? '', 'MatchConcedeReqState to MatchNotJoinedEventUnderwayState'));

        $concededAndQuit = ($idxConcede !== false && $idxNotJoined !== false && $idxNotJoined > $idxConcede)
            || $idxConcedeFulfilled !== false;

        if (! $matchEnded && ! $concededAndQuit) {
            return;
        }

        $lastEvent = LogEvent::where('match_id', $match->mtgo_id)->orderBy('timestamp', 'desc')->first();

        $match->update([
            'state' => MatchState::Ended,
            'ended_at' => $lastEvent
                ? now()->parse($lastEvent->logged_at)->setTimeFromTimeString($lastEvent->timestamp)
                : now(),
        ]);
    }

    /**
     * ended → complete
     * Trigger: all data available to determine result
     * Actions: determine win/loss, archetypes, notify, submit
     */
    private static function tryAdvanceToComplete(MtgoMatch $match, $events, $stateChanges): void
    {
        $gameLog = GetGameLog::run($match->token);

        $gameCount = $match->games()->count();
        $logResults = $gameLog ? array_slice($gameLog['results'] ?? [], 0, $gameCount) : [];

        $wins = count(array_filter($logResults, fn ($r) => $r === true));
        $losses = count(array_filter($logResults, fn ($r) => $r === false));

        $winThreshold = $gameCount >= 3 && ($wins >= 3 || $losses >= 3) ? 3 : 2;

        if (($wins + $losses) < $winThreshold) {
            $localConceded = $stateChanges->contains(
                fn (LogEvent $event) => str_contains($event->context ?? '', 'MatchConcedeReqState to MatchNotJoinedEventUnderwayState')
            );

            if ($localConceded) {
                $losses = $winThreshold;
            } else {
                $wins = $winThreshold;
            }
        }

        DB::beginTransaction();

        $match->update([
            'games_won' => $wins,
            'games_lost' => $losses,
            'state' => MatchState::Complete,
        ]);

        DetermineMatchArchetypes::run($match);

        DB::commit();

        SubmitMatch::dispatch($match->id);

        Notification::title('New match Recorded')
            ->message($match->deck?->name . ' // ' . $match->games_won . '-' . $match->games_lost)
            ->show();

        LogEvent::where('match_id', $match->mtgo_id)
            ->orWhere('match_token', $match->token)
            ->orWhereIn('game_id', $match->games->pluck('mtgo_id'))
            ->update(['processed_at' => now()]);
    }

    /**
     * Find or create a phantom league for matches without a real league token.
     * Copied from the original BuildMatch — same logic.
     */
    private static function findOrCreatePhantomLeague(array $gameMeta, ?int $deckId): League
    {
        if ($deckId) {
            $existing = League::where('format', $gameMeta['PlayFormatCd'])
                ->where('phantom', true)
                ->where('deck_change_detected', false)
                ->has('matches', '<', 5)
                ->whereHas('matches', fn ($q) => $q
                    ->join('deck_versions as dv', 'dv.id', '=', 'matches.deck_version_id')
                    ->where('dv.deck_id', $deckId)
                )
                ->latest('started_at')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return League::create([
            'token' => Str::random(),
            'format' => $gameMeta['PlayFormatCd'],
            'phantom' => true,
            'started_at' => now(),
            'name' => 'Phantom ' . trim(($gameMeta['GameStructureCd'] ?? '') . ' League ' . now()->format('d-m-Y h:ma')),
        ]);
    }
}
```

**Step 4: Run tests**

```bash
php artisan test --filter=AdvanceMatchState --compact
```

Expected: Tests pass (the basic gate + started state tests). Some tests may need adjustments depending on the exact LogEvent schema — adapt as needed.

**Step 5: Commit**

```bash
git add app/Actions/Matches/AdvanceMatchState.php tests/Feature/Actions/Matches/AdvanceMatchStateTest.php
git commit -m "Add AdvanceMatchState action with tests"
```

---

### Task 12: Update BuildMatches to use AdvanceMatchState

**Files:**
- Modify: `app/Actions/Matches/BuildMatches.php`

**Step 1: Rewrite BuildMatches**

```php
<?php

namespace App\Actions\Matches;

use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\LogCursor;
use App\Models\LogEvent;
use App\Models\MtgoMatch;

class BuildMatches
{
    public static function run()
    {
        $username = LogCursor::first()?->local_username;

        if (! $username) {
            return;
        }

        Mtgo::setUsername($username);

        // 1. New match detection — find unprocessed match tokens
        $matchTokens = LogEvent::whereNotNull('match_id')
            ->whereNotNull('match_token')
            ->whereNull('processed_at')
            ->distinct()
            ->pluck('match_token');

        $matchIds = LogEvent::whereIn('match_token', $matchTokens)
            ->whereNotNull('match_id')
            ->distinct()
            ->pluck('match_id', 'match_token');

        foreach ($matchIds as $matchToken => $matchId) {
            // Skip if already exists (will be handled in state advancement below)
            if (MtgoMatch::where('mtgo_id', $matchId)->exists()) {
                continue;
            }

            AdvanceMatchState::run($matchToken, $matchId);
        }

        // 2. State advancement — advance all incomplete matches
        $incompleteMatches = MtgoMatch::incomplete()->get();

        foreach ($incompleteMatches as $match) {
            AdvanceMatchState::run($match->token, $match->mtgo_id);
        }
    }
}
```

**Step 2: Commit**

```bash
git add app/Actions/Matches/BuildMatches.php
git commit -m "Update BuildMatches to use AdvanceMatchState for new and incomplete matches"
```

---

### Task 13: Update BuildMatch job

**Files:**
- Modify: `app/Jobs/BuildMatch.php`

**Step 1: Update to call AdvanceMatchState**

```php
<?php

namespace App\Jobs;

use App\Actions\Matches\AdvanceMatchState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildMatch implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $matchToken,
        protected int|string $matchId,
    ) {}

    public function handle(): void
    {
        AdvanceMatchState::run($this->matchToken, $this->matchId);
    }
}
```

**Step 2: Commit**

```bash
git add app/Jobs/BuildMatch.php
git commit -m "Update BuildMatch job to use AdvanceMatchState"
```

---

### Task 14: Clean up old BuildMatch action

**Files:**
- Delete: `app/Actions/Matches/BuildMatch.php`

**Step 1: Verify no remaining references to the old action**

Search for `Actions\Matches\BuildMatch` or `BuildMatch::run(` in the codebase (excluding the job). There should be none after the previous tasks.

```bash
grep -r "Actions\\\\Matches\\\\BuildMatch" app/ --include="*.php" | grep -v "AdvanceMatchState"
```

Expected: No results (the Job now uses AdvanceMatchState, BuildMatches now uses AdvanceMatchState).

**Step 2: Delete the old action**

```bash
rm app/Actions/Matches/BuildMatch.php
```

**Step 3: Commit**

```bash
git add -A
git commit -m "Remove old BuildMatch action — replaced by AdvanceMatchState"
```

---

### Task 15: Run Pint + full test suite

**Step 1: Run Pint on changed files**

```bash
vendor/bin/pint --dirty
```

**Step 2: Run full test suite**

```bash
php artisan test --compact
```

Expected: All tests pass.

**Step 3: Commit any formatting fixes**

```bash
git add -A
git commit -m "pint"
```

---

### Task 16: Integration test — full pipeline

**Files:**
- Create: `tests/Feature/Actions/Matches/MatchStatePipelineTest.php`

**Step 1: Write integration test for the full state machine**

This test verifies the complete flow: a match token with all events available advances through all states to complete in a single `BuildMatches::run()` call. This mirrors the "skip-ahead" scenario.

```php
<?php

use App\Actions\Matches\BuildMatches;
use App\Enums\MatchState;
use App\Models\LogCursor;
use App\Models\MtgoMatch;

it('advances a match through all states when all events are available', function () {
    // This test requires realistic log events — it's an integration test.
    // If test fixtures exist, use them. Otherwise, this serves as a template
    // to be completed once the pipeline is verified manually.
    expect(true)->toBeTrue();
})->todo();

it('does not create matches for foreign match tokens without join events', function () {
    LogCursor::create([
        'file_path' => '/test/log',
        'byte_offset' => 0,
        'local_username' => 'TestPlayer',
    ]);

    \App\Models\LogEvent::create([
        'file_path' => '/test/log',
        'byte_offset_start' => 0,
        'byte_offset_end' => 100,
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Match',
        'context' => 'SomeRandomState',
        'raw_text' => 'foreign match event',
        'event_type' => 'match_state_changed',
        'logged_at' => now(),
        'match_id' => 55555,
        'match_token' => 'foreign-token',
    ]);

    BuildMatches::run();

    expect(MtgoMatch::count())->toBe(0);
});

it('only shows complete matches in consumer scopes', function () {
    MtgoMatch::create([
        'mtgo_id' => 11111,
        'token' => 'complete-token',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'state' => MatchState::Complete,
        'games_won' => 2,
        'games_lost' => 1,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    MtgoMatch::create([
        'mtgo_id' => 22222,
        'token' => 'in-progress-token',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'state' => MatchState::InProgress,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    expect(MtgoMatch::complete()->count())->toBe(1);
    expect(MtgoMatch::incomplete()->count())->toBe(1);
    expect(MtgoMatch::count())->toBe(2);
});
```

**Step 2: Run tests**

```bash
php artisan test --filter=MatchStatePipeline --compact
```

**Step 3: Commit**

```bash
git add tests/Feature/Actions/Matches/MatchStatePipelineTest.php
git commit -m "Add match state pipeline integration tests"
```

---

### Task 17: Final verification + Pint

**Step 1: Run full test suite**

```bash
php artisan test --compact
```

Expected: All tests pass.

**Step 2: Run Pint**

```bash
vendor/bin/pint --dirty
```

**Step 3: Verify no regressions in consumer queries**

Manually check that the app boots without errors:

```bash
php artisan route:list
```

**Step 4: Commit any remaining fixes**

```bash
git add -A
git commit -m "Final cleanup and verification"
```

---

## Task Dependency Order

```
Task 1 (enum) ──→ Task 2 (migration) ──→ Task 3 (model)
                                              │
                              ┌────────────────┼───────────────┐
                              ▼                ▼               ▼
                     Task 4 (Deck)    Task 5 (DeckVersion)  Task 6 (controllers)
                              │                │               │
                              └────────────────┴───────────────┘
                                              │
                    ┌─────────────────────────┤
                    ▼                         ▼
           Task 7 (event)           Task 11 (AdvanceMatchState)
                    │                         │
                    ▼                         ▼
           Task 8 (unique job)      Task 12 (BuildMatches)
                    │                         │
                    ▼                         ▼
           Task 9 (fire event)      Task 13 (BuildMatch job)
                    │                         │
                    ▼                         ▼
           Task 10 (schedule)       Task 14 (delete old action)
                    │                         │
                    └─────────────────────────┘
                                   │
                                   ▼
                          Task 15 (Pint + tests)
                                   │
                                   ▼
                          Task 16 (integration tests)
                                   │
                                   ▼
                          Task 17 (final verification)
```

Tasks 4, 5, 6 can run in parallel. Tasks 7-10 and 11-14 can run in parallel as two independent tracks.
