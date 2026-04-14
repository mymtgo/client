# Challenge Tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Challenge tab that captures and displays MTGO tournament/challenge broadcast data — standings, eliminations, lifecycle events — with live polling for active challenges.

**Architecture:** Split pipeline — `ProcessChallengeEvents` handles all challenge domain logic (lifecycle, standings, timeline) from classified log events; `LinkMatchToChallenge` (deferred) bridges the match pipeline for participation. Data seeded from existing log file for development. UI follows existing Leagues pattern: index with filters, detail with three-column layout, deck tab for per-deck challenge history.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, Inertia.js v2, Vue 3, TypeScript, Tailwind v4, SQLite

**Spec:** `docs/superpowers/specs/2026-04-14-challenge-tab-design.md`

---

## File Map

### New Files

**Enums:**
- `app/Enums/TournamentState.php` — agnostic tournament lifecycle states
- `app/Enums/ChallengeTimelineEventType.php` — timeline event types
- `app/Enums/EliminationReason.php` — player elimination reasons
- `app/Enums/TournamentStructure.php` — tournament structure types

**Migrations:**
- `database/migrations/2026_04_14_000001_create_challenges_table.php`
- `database/migrations/2026_04_14_000002_create_challenge_standings_table.php`
- `database/migrations/2026_04_14_000003_create_challenge_timeline_events_table.php`
- `database/migrations/2026_04_14_000004_add_challenge_id_to_matches_table.php`
- `database/migrations/2026_04_14_000005_add_login_id_to_players_table.php`

**Models:**
- `app/Models/Challenge.php`
- `app/Models/ChallengeStanding.php`
- `app/Models/ChallengeTimelineEvent.php`

**Factories:**
- `database/factories/ChallengeFactory.php`

**Actions:**
- `app/Actions/Challenges/ProcessChallengeEvents.php`
- `app/Actions/Challenges/StripBbCode.php`

**Controllers:**
- `app/Http/Controllers/Challenges/IndexController.php`
- `app/Http/Controllers/Challenges/ShowController.php`
- `app/Http/Controllers/Decks/ChallengesController.php`

**Console Commands:**
- `app/Console/Commands/SeedChallengesFromLog.php`

**Vue Pages:**
- `resources/js/pages/challenges/Index.vue`
- `resources/js/pages/challenges/Show.vue`
- `resources/js/pages/decks/Challenges.vue`

**Tests:**
- `tests/Feature/Actions/Challenges/ProcessChallengeEventsTest.php`
- `tests/Feature/Actions/Logs/ClassifyChallengeEventsTest.php`
- `tests/Feature/Http/Challenges/IndexControllerTest.php`
- `tests/Feature/Http/Challenges/ShowControllerTest.php`
- `tests/Feature/Http/Decks/ChallengesControllerTest.php`

### Modified Files

- `app/Enums/LogEventType.php` — add 6 new cases
- `app/Actions/Logs/ClassifyLogEvent.php` — add challenge classification patterns
- `app/Models/MtgoMatch.php` — add `challenge()` relationship
- `app/Models/Player.php` — add `login_id` to fillable
- `routes/web.php` — add challenge routes + deck tab route
- `resources/js/components/AppNav.vue` — add Challenges nav item
- `resources/js/components/decks/DeckSidebar.vue` — add Challenges tab

---

## Task 1: Enums

**Files:**
- Create: `app/Enums/TournamentState.php`
- Create: `app/Enums/ChallengeTimelineEventType.php`
- Create: `app/Enums/EliminationReason.php`
- Create: `app/Enums/TournamentStructure.php`
- Modify: `app/Enums/LogEventType.php`

- [ ] **Step 1: Create TournamentState enum**

```php
<?php

namespace App\Enums;

enum TournamentState: string
{
    case AwaitingPlayers = 'awaiting_players';
    case Firing = 'firing';
    case Drafting = 'drafting';
    case DeckBuilding = 'deck_building';
    case WaitingForFirstRound = 'waiting_for_first_round';
    case RoundInProgress = 'round_in_progress';
    case BetweenRounds = 'between_rounds';
    case Completed = 'completed';

    /**
     * Map an MTGO state string to a TournamentState.
     */
    public static function fromMtgoState(string $mtgoState): ?self
    {
        return match (true) {
            str_contains($mtgoState, 'AwaitingMinPlayers'),
            str_contains($mtgoState, 'AwaitingMaxPlayers') => self::AwaitingPlayers,

            str_contains($mtgoState, 'AwaitingStart'),
            str_contains($mtgoState, 'FiredState') => self::Firing,

            str_contains($mtgoState, 'DraftingState') => self::Drafting,

            str_contains($mtgoState, 'DeckBuildingState') => self::DeckBuilding,

            str_contains($mtgoState, 'WaitingForFirstRoundToStart') => self::WaitingForFirstRound,

            str_contains($mtgoState, 'RoundInProgressState') => self::RoundInProgress,

            str_contains($mtgoState, 'BetweenRoundsState') => self::BetweenRounds,

            str_contains($mtgoState, 'CompletedState') => self::Completed,

            default => null,
        };
    }

    public function isActive(): bool
    {
        return $this !== self::Completed;
    }
}
```

- [ ] **Step 2: Create ChallengeTimelineEventType enum**

```php
<?php

namespace App\Enums;

enum ChallengeTimelineEventType: string
{
    case StateChanged = 'state_changed';
    case RoundResult = 'round_result';
    case PlayerEliminated = 'player_eliminated';
    case MatchStateChanged = 'match_state_changed';
}
```

- [ ] **Step 3: Create EliminationReason enum**

```php
<?php

namespace App\Enums;

enum EliminationReason: string
{
    case MatchLoss = 'match_loss';
    case Drop = 'drop';

    public static function fromMtgoReason(string $reason): ?self
    {
        return match ($reason) {
            'Match Loss' => self::MatchLoss,
            'Drop' => self::Drop,
            default => null,
        };
    }
}
```

- [ ] **Step 4: Create TournamentStructure enum**

```php
<?php

namespace App\Enums;

enum TournamentStructure: string
{
    case Swiss = 'swiss';

    public static function fromMtgoCode(string $code): ?self
    {
        return match (strtoupper($code)) {
            'SWISS' => self::Swiss,
            default => null,
        };
    }
}
```

- [ ] **Step 5: Add challenge event types to LogEventType**

Add these cases to `app/Enums/LogEventType.php`:

```php
case CHALLENGE_SYNC = 'challenge_sync';
case CHALLENGE_STATE_CHANGED = 'challenge_state_changed';
case CHALLENGE_ROUND_RESULT = 'challenge_round_result';
case CHALLENGE_PLAYER_ELIMINATED = 'challenge_player_eliminated';
case CHALLENGE_ENDED = 'challenge_ended';
case CHALLENGE_MATCH_STATE_CHANGED = 'challenge_match_state_changed';
```

- [ ] **Step 6: Commit**

```bash
git add app/Enums/TournamentState.php app/Enums/ChallengeTimelineEventType.php app/Enums/EliminationReason.php app/Enums/TournamentStructure.php app/Enums/LogEventType.php
git commit -m "feat: add challenge-related enums and log event types"
```

---

## Task 2: Migrations

**Files:**
- Create: `database/migrations/2026_04_14_000001_create_challenges_table.php`
- Create: `database/migrations/2026_04_14_000002_create_challenge_standings_table.php`
- Create: `database/migrations/2026_04_14_000003_create_challenge_timeline_events_table.php`
- Create: `database/migrations/2026_04_14_000004_add_challenge_id_to_matches_table.php`
- Create: `database/migrations/2026_04_14_000005_add_login_id_to_players_table.php`

- [ ] **Step 1: Create challenges table migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenges', function (Blueprint $table) {
            $table->id();
            $table->string('token')->unique();
            $table->string('name')->nullable();
            $table->string('format')->nullable();
            $table->text('description')->nullable();
            $table->string('tournament_structure')->nullable();
            $table->string('state')->default('awaiting_players');
            $table->integer('current_round')->nullable();
            $table->integer('max_rounds')->nullable();
            $table->integer('player_count')->default(0);
            $table->integer('min_players')->nullable();
            $table->integer('max_players')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->boolean('participated')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['format', 'state']);
            $table->index(['participated', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenges');
    }
};
```

- [ ] **Step 2: Create challenge_standings table migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenge_standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->integer('round');
            $table->integer('login_id');
            $table->string('username')->nullable();
            $table->integer('rank');
            $table->integer('points');
            $table->string('match_record');
            $table->float('opponent_match_win_pct')->nullable();
            $table->float('game_win_pct')->nullable();
            $table->boolean('is_local')->default(false);
            $table->timestamps();

            $table->unique(['challenge_id', 'round', 'login_id']);
            $table->index(['login_id', 'is_local']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_standings');
    }
};
```

- [ ] **Step 3: Create challenge_timeline_events table migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenge_timeline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->integer('round')->nullable();
            $table->string('event_type');
            $table->integer('login_id')->nullable();
            $table->string('username')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['challenge_id', 'round']);
            $table->index(['challenge_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_timeline_events');
    }
};
```

- [ ] **Step 4: Add challenge_id to matches table**

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
            $table->foreignId('challenge_id')->nullable()->after('league_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('challenge_id');
        });
    }
};
```

- [ ] **Step 5: Add login_id to players table**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->integer('login_id')->nullable()->after('username');
            $table->index('login_id');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropIndex(['login_id']);
            $table->dropColumn('login_id');
        });
    }
};
```

- [ ] **Step 6: Run migrations**

Run: `php artisan migrate`
Expected: All 5 migrations run successfully.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_04_14_*
git commit -m "feat: add challenge schema — tables, indexes, FK on matches and players"
```

---

## Task 3: Models & Factory

**Files:**
- Create: `app/Models/Challenge.php`
- Create: `app/Models/ChallengeStanding.php`
- Create: `app/Models/ChallengeTimelineEvent.php`
- Create: `database/factories/ChallengeFactory.php`
- Modify: `app/Models/MtgoMatch.php`
- Modify: `app/Models/Player.php`

- [ ] **Step 1: Create Challenge model**

```php
<?php

namespace App\Models;

use App\Enums\TournamentState;
use App\Enums\TournamentStructure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Challenge extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'state' => TournamentState::class,
        'tournament_structure' => TournamentStructure::class,
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'participated' => 'boolean',
    ];

    /** @return HasMany<ChallengeStanding, $this> */
    public function standings(): HasMany
    {
        return $this->hasMany(ChallengeStanding::class);
    }

    /** @return HasMany<ChallengeTimelineEvent, $this> */
    public function timelineEvents(): HasMany
    {
        return $this->hasMany(ChallengeTimelineEvent::class);
    }

    /** @return HasMany<MtgoMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(MtgoMatch::class);
    }

    public function scopeActive($query)
    {
        return $query->where('state', '!=', TournamentState::Completed);
    }

    public function scopeCompleted($query)
    {
        return $query->where('state', TournamentState::Completed);
    }

    public function scopeParticipated($query)
    {
        return $query->where('participated', true);
    }

    public function scopeForFormat($query, string $format)
    {
        return $query->where('format', $format);
    }

    /**
     * Get the local user's standing for the latest round.
     */
    public function localStanding(): ?ChallengeStanding
    {
        return $this->standings()
            ->where('is_local', true)
            ->orderByDesc('round')
            ->first();
    }
}
```

- [ ] **Step 2: Create ChallengeStanding model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeStanding extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_local' => 'boolean',
        'opponent_match_win_pct' => 'float',
        'game_win_pct' => 'float',
    ];

    /** @return BelongsTo<Challenge, $this> */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }
}
```

- [ ] **Step 3: Create ChallengeTimelineEvent model**

```php
<?php

namespace App\Models;

use App\Enums\ChallengeTimelineEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeTimelineEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'event_type' => ChallengeTimelineEventType::class,
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    /** @return BelongsTo<Challenge, $this> */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }
}
```

- [ ] **Step 4: Create ChallengeFactory**

```php
<?php

namespace Database\Factories;

use App\Enums\TournamentState;
use App\Models\Challenge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Challenge> */
class ChallengeFactory extends Factory
{
    protected $model = Challenge::class;

    public function definition(): array
    {
        return [
            'token' => Str::uuid()->toString(),
            'name' => $this->faker->randomElement(['Modern Challenge', 'Legacy Challenge', 'Pauper Challenge', 'Vintage Challenge']),
            'format' => $this->faker->randomElement(['Modern', 'Legacy', 'Pauper', 'Vintage']),
            'state' => TournamentState::AwaitingPlayers,
            'started_at' => now(),
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'state' => TournamentState::RoundInProgress,
            'current_round' => 2,
            'max_rounds' => 7,
            'player_count' => 32,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'state' => TournamentState::Completed,
            'current_round' => 7,
            'max_rounds' => 7,
            'player_count' => 32,
            'ended_at' => now(),
        ]);
    }

    public function participated(): static
    {
        return $this->state(fn () => [
            'participated' => true,
        ]);
    }
}
```

- [ ] **Step 5: Add challenge relationship to MtgoMatch**

In `app/Models/MtgoMatch.php`, add the import and relationship:

```php
use App\Models\Challenge;
```

Add the relationship method after the existing `league()` method:

```php
/** @return BelongsTo<Challenge, $this> */
public function challenge(): BelongsTo
{
    return $this->belongsTo(Challenge::class);
}
```

- [ ] **Step 6: Add login_id to Player model**

In `app/Models/Player.php`, update fillable:

```php
protected $fillable = ['username', 'login_id'];
```

- [ ] **Step 7: Commit**

```bash
git add app/Models/Challenge.php app/Models/ChallengeStanding.php app/Models/ChallengeTimelineEvent.php database/factories/ChallengeFactory.php app/Models/MtgoMatch.php app/Models/Player.php
git commit -m "feat: add Challenge models, factory, and relationship updates"
```

---

## Task 4: Log Event Classification

**Files:**
- Modify: `app/Actions/Logs/ClassifyLogEvent.php`
- Create: `tests/Feature/Actions/Logs/ClassifyChallengeEventsTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Actions\Logs\ClassifyLogEvent;
use App\Models\LogEvent;

it('classifies tournament state change events', function () {
    $event = new LogEvent([
        'raw_text' => '18:42:27 [INF] (Game Management|Tournament State Changed for b049851f-3a2b-41e6-9260-ed2100d57071 from TournamentUninitializedState to PremierNotJoinedAwaitingMinPlayersState)',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('challenge_state_changed')
        ->and($result->match_token)->toBe('b049851f-3a2b-41e6-9260-ed2100d57071');
});

it('classifies tournament round result events', function () {
    $event = new LogEvent([
        'raw_text' => '18:43:20 [INF] (Game Management|Processing Registered Handler for FlsTournamentRoundResultMessage in TournamentNotJoinedRoundInProgressState) Processor: TournamentNotJoinedRoundInProgressState Message: {"Token":"18c84071-d8b7-474e-9fdc-efaa08bcf02f","Round":3,"Results":[]}',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('challenge_round_result')
        ->and($result->match_token)->toBe('18c84071-d8b7-474e-9fdc-efaa08bcf02f');
});

it('classifies tournament player elimination events', function () {
    $event = new LogEvent([
        'raw_text' => '19:00:00 [INF] (Game Management|Processing Registered Handler for FlsTournamentPlayerIsEliminatedMessage in TournamentNotJoinedRoundInProgressState) Processor: TournamentNotJoinedRoundInProgressState Message: {"Token":"6eaaa32d-de66-45f8-85b9-cfde3eaa0924","LoginID":829651,"Reason":"Match Loss"}',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('challenge_player_eliminated')
        ->and($result->match_token)->toBe('6eaaa32d-de66-45f8-85b9-cfde3eaa0924');
});

it('classifies tournament end events', function () {
    $event = new LogEvent([
        'raw_text' => '19:07:06 [INF] (Game Management|Processing Registered Handler for FlsTournamentEndRespMessage in TournamentNotJoinedRoundInProgressState) Processor: TournamentNotJoinedRoundInProgressState Message: {"Token":"e63ba74a-50e1-4321-a123-456789abcdef","EndDate":"2026-03-18T19:07:06"}',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('challenge_ended')
        ->and($result->match_token)->toBe('e63ba74a-50e1-4321-a123-456789abcdef');
});

it('classifies tournament sync events', function () {
    $event = new LogEvent([
        'raw_text' => '18:42:28 [INF] (Game Management|Processing Registered Handler for EventSyncData_t in TournamentUninitializedState) Processor: TournamentUninitializedState Message: {"EventToken":"43bd3465-f61e-4d92-bb46-eecae05612d5","EventID":12835954,"Players":[]}',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('challenge_sync')
        ->and($result->match_token)->toBe('43bd3465-f61e-4d92-bb46-eecae05612d5');
});

it('classifies tournament match state change events', function () {
    $event = new LogEvent([
        'raw_text' => '18:45:00 [INF] (Game Management|TournamentMatch State Changed for d7da3580-a227-48b5-b449-22910c7404ea from TournamentMatchUninitializedState to TournamentMatchNotJoinedEventUnderwayState)',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('challenge_match_state_changed')
        ->and($result->match_token)->toBe('d7da3580-a227-48b5-b449-22910c7404ea');
});

it('does not classify regular match state changes as challenge events', function () {
    $event = new LogEvent([
        'raw_text' => '18:42:27 [INF] (Game Management|Match State Changed for abc12345-1234-5678-9012-abcdef123456 from MatchUninitializedState to MatchNotJoinedAwaitingMinPlayersState)',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('match_state_changed');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ClassifyChallengeEvents`
Expected: All 7 tests FAIL.

- [ ] **Step 3: Add challenge classification patterns to ClassifyLogEvent**

In `app/Actions/Logs/ClassifyLogEvent.php`, add these blocks **before** the existing `Match State Changed` pattern (so tournament-specific patterns match first):

```php
// Tournament sync data — the richest single message with full tournament snapshot
if (str_contains($text, 'EventSyncData_t in Tournament')) {
    $json = ExtractJson::run($text)->first();
    if (is_array($json) && isset($json['EventToken'])) {
        return $event->fill([
            'event_type' => 'challenge_sync',
            'match_token' => $json['EventToken'],
        ]);
    }
}

// Tournament state change
if (preg_match('/Tournament State Changed for (?<token>[a-f0-9\-]+) from (?<from>\S+) to (?<to>\S+)/', $text, $m)) {
    return $event->fill([
        'event_type' => 'challenge_state_changed',
        'match_token' => $m['token'],
    ]);
}

// Tournament round result
if (str_contains($text, 'FlsTournamentRoundResultMessage')) {
    $json = ExtractJson::run($text)->first();
    if (is_array($json) && isset($json['Token'])) {
        return $event->fill([
            'event_type' => 'challenge_round_result',
            'match_token' => $json['Token'],
        ]);
    }
}

// Tournament player eliminated
if (str_contains($text, 'FlsTournamentPlayerIsEliminatedMessage')) {
    $json = ExtractJson::run($text)->first();
    if (is_array($json) && isset($json['Token'])) {
        return $event->fill([
            'event_type' => 'challenge_player_eliminated',
            'match_token' => $json['Token'],
        ]);
    }
}

// Tournament ended
if (str_contains($text, 'FlsTournamentEndRespMessage')) {
    $json = ExtractJson::run($text)->first();
    if (is_array($json) && isset($json['Token'])) {
        return $event->fill([
            'event_type' => 'challenge_ended',
            'match_token' => $json['Token'],
        ]);
    }
}

// Tournament match state change (distinct from regular match state changes)
if (preg_match('/TournamentMatch State Changed for (?<token>[a-f0-9\-]+)/', $text, $m)) {
    return $event->fill([
        'event_type' => 'challenge_match_state_changed',
        'match_token' => $m['token'],
    ]);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ClassifyChallengeEvents`
Expected: All 7 tests PASS.

- [ ] **Step 5: Run existing classification tests to check for regressions**

Run: `php artisan test --compact --filter=ClassifyLeagueEvents`
Expected: All existing tests PASS.

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --dirty`

- [ ] **Step 7: Commit**

```bash
git add app/Actions/Logs/ClassifyLogEvent.php tests/Feature/Actions/Logs/ClassifyChallengeEventsTest.php
git commit -m "feat: classify challenge log events — sync, state, results, eliminations"
```

---

## Task 5: StripBbCode Utility & ProcessChallengeEvents

**Files:**
- Create: `app/Actions/Challenges/StripBbCode.php`
- Create: `app/Actions/Challenges/ProcessChallengeEvents.php`
- Create: `tests/Feature/Actions/Challenges/ProcessChallengeEventsTest.php`

- [ ] **Step 1: Create StripBbCode utility**

```php
<?php

namespace App\Actions\Challenges;

class StripBbCode
{
    /**
     * Strip BBCode tags from MTGO text.
     */
    public static function run(string $text): string
    {
        return preg_replace('/\[\/?\w+\]/', '', $text);
    }
}
```

- [ ] **Step 2: Write ProcessChallengeEvents tests**

```php
<?php

use App\Actions\Challenges\ProcessChallengeEvents;
use App\Enums\ChallengeTimelineEventType;
use App\Enums\TournamentState;
use App\Models\Challenge;
use App\Models\ChallengeStanding;
use App\Models\ChallengeTimelineEvent;
use App\Models\LogEvent;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a challenge from a state changed event', function () {
    LogEvent::create([
        'raw_text' => '18:42:27 [INF] (Game Management|Tournament State Changed for aaa-bbb-ccc from TournamentUninitializedState to PremierNotJoinedAwaitingMinPlayersState)',
        'event_type' => 'challenge_state_changed',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '18:42:27',
        'logged_at' => '2026-03-18 18:42:27',
    ]);

    ProcessChallengeEvents::run();

    $challenge = Challenge::where('token', 'aaa-bbb-ccc')->first();
    expect($challenge)->not->toBeNull()
        ->and($challenge->state)->toBe(TournamentState::AwaitingPlayers);
});

it('updates challenge state on subsequent state changes', function () {
    $challenge = Challenge::factory()->create([
        'token' => 'aaa-bbb-ccc',
        'state' => TournamentState::AwaitingPlayers,
    ]);

    LogEvent::create([
        'raw_text' => '18:50:00 [INF] (Game Management|Tournament State Changed for aaa-bbb-ccc from PremierNotJoinedAwaitingMinPlayersState to TournamentNotJoinedRoundInProgressState)',
        'event_type' => 'challenge_state_changed',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '18:50:00',
        'logged_at' => '2026-03-18 18:50:00',
    ]);

    ProcessChallengeEvents::run();

    $challenge->refresh();
    expect($challenge->state)->toBe(TournamentState::RoundInProgress);
});

it('creates timeline events for state changes', function () {
    LogEvent::create([
        'raw_text' => '18:42:27 [INF] (Game Management|Tournament State Changed for aaa-bbb-ccc from TournamentUninitializedState to PremierNotJoinedAwaitingMinPlayersState)',
        'event_type' => 'challenge_state_changed',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '18:42:27',
        'logged_at' => '2026-03-18 18:42:27',
    ]);

    ProcessChallengeEvents::run();

    $challenge = Challenge::where('token', 'aaa-bbb-ccc')->first();
    expect($challenge->timelineEvents)->toHaveCount(1)
        ->and($challenge->timelineEvents->first()->event_type)->toBe(ChallengeTimelineEventType::StateChanged);
});

it('processes round results into standings', function () {
    $challenge = Challenge::factory()->inProgress()->create([
        'token' => 'aaa-bbb-ccc',
    ]);

    $json = json_encode([
        'Token' => 'aaa-bbb-ccc',
        'Round' => 1,
        'Results' => [
            [
                'LoginID' => 12345,
                'Rank' => 1,
                'Points' => 3,
                'OpponentResults' => [['Round' => 1, 'LoginID' => 67890, 'Win' => 2, 'Loss' => 0, 'Draw' => 0, 'Bye' => 0]],
                'OpponentMatchWinPercentage' => '0.5556',
                'GameWinPercentage' => '0.8571',
            ],
        ],
    ]);

    LogEvent::create([
        'raw_text' => "18:50:00 [INF] (Game Management|Processing Registered Handler for FlsTournamentRoundResultMessage) Message: {$json}",
        'event_type' => 'challenge_round_result',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '18:50:00',
        'logged_at' => '2026-03-18 18:50:00',
    ]);

    ProcessChallengeEvents::run();

    $standing = ChallengeStanding::where('challenge_id', $challenge->id)->first();
    expect($standing)->not->toBeNull()
        ->and($standing->login_id)->toBe(12345)
        ->and($standing->rank)->toBe(1)
        ->and($standing->points)->toBe(3)
        ->and($standing->match_record)->toBe('2-0');
});

it('resolves usernames from players table', function () {
    Player::create(['username' => 'TestPlayer', 'login_id' => 12345]);

    $challenge = Challenge::factory()->inProgress()->create(['token' => 'aaa-bbb-ccc']);

    $json = json_encode([
        'Token' => 'aaa-bbb-ccc',
        'Round' => 1,
        'Results' => [
            [
                'LoginID' => 12345,
                'Rank' => 1,
                'Points' => 3,
                'OpponentResults' => [['Round' => 1, 'LoginID' => 99999, 'Win' => 2, 'Loss' => 0, 'Draw' => 0, 'Bye' => 0]],
                'OpponentMatchWinPercentage' => '0.5000',
                'GameWinPercentage' => '1.0000',
            ],
        ],
    ]);

    LogEvent::create([
        'raw_text' => "18:50:00 [INF] Message: {$json}",
        'event_type' => 'challenge_round_result',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '18:50:00',
        'logged_at' => '2026-03-18 18:50:00',
    ]);

    ProcessChallengeEvents::run();

    $standing = ChallengeStanding::first();
    expect($standing->username)->toBe('TestPlayer');
});

it('processes sync data and creates player mappings', function () {
    $json = json_encode([
        'EventToken' => 'aaa-bbb-ccc',
        'EventID' => 12835954,
        'Description' => 'Modern Challenge',
        'FormatDescription' => '[b]Modern[/b]',
        'Players' => [
            ['LoginID' => 111, 'PlayerName' => 'Alice', 'AvatarID' => 1, 'State' => 1, 'IsMatchConceded' => false],
            ['LoginID' => 222, 'PlayerName' => 'Bob', 'AvatarID' => 2, 'State' => 1, 'IsMatchConceded' => false],
        ],
        'PremiereEventSyncData' => [
            'TournamentStructureCd' => 'SWISS',
            'NumberOfRounds' => 7,
            'MinPlayers' => 32,
            'MaxPlayers' => 256,
        ],
    ]);

    LogEvent::create([
        'raw_text' => "18:42:28 [INF] (Game Management|Processing Registered Handler for EventSyncData_t in TournamentUninitializedState) Message: {$json}",
        'event_type' => 'challenge_sync',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '18:42:28',
        'logged_at' => '2026-03-18 18:42:28',
    ]);

    ProcessChallengeEvents::run();

    $challenge = Challenge::where('token', 'aaa-bbb-ccc')->first();
    expect($challenge)->not->toBeNull()
        ->and($challenge->name)->toBe('Modern Challenge')
        ->and($challenge->description)->toBe('Modern')
        ->and($challenge->tournament_structure->value)->toBe('swiss')
        ->and($challenge->max_rounds)->toBe(7);

    // Players created with login_id mapping
    expect(Player::where('login_id', 111)->first()->username)->toBe('Alice')
        ->and(Player::where('login_id', 222)->first()->username)->toBe('Bob');
});

it('marks events as processed', function () {
    $event = LogEvent::create([
        'raw_text' => '18:42:27 [INF] (Game Management|Tournament State Changed for aaa-bbb-ccc from TournamentUninitializedState to PremierNotJoinedAwaitingMinPlayersState)',
        'event_type' => 'challenge_state_changed',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '18:42:27',
        'logged_at' => '2026-03-18 18:42:27',
    ]);

    ProcessChallengeEvents::run();

    $event->refresh();
    expect($event->processed_at)->not->toBeNull();
});

it('is idempotent — reprocessing does not duplicate standings', function () {
    $challenge = Challenge::factory()->inProgress()->create(['token' => 'aaa-bbb-ccc']);

    $json = json_encode([
        'Token' => 'aaa-bbb-ccc',
        'Round' => 1,
        'Results' => [
            [
                'LoginID' => 12345,
                'Rank' => 1,
                'Points' => 3,
                'OpponentResults' => [['Round' => 1, 'LoginID' => 99999, 'Win' => 2, 'Loss' => 1, 'Draw' => 0, 'Bye' => 0]],
                'OpponentMatchWinPercentage' => '0.5000',
                'GameWinPercentage' => '0.8000',
            ],
        ],
    ]);

    // Create two identical events (simulating reprocessing)
    foreach ([1, 2] as $i) {
        LogEvent::create([
            'raw_text' => "18:50:0{$i} [INF] Message: {$json}",
            'event_type' => 'challenge_round_result',
            'match_token' => 'aaa-bbb-ccc',
            'timestamp' => "18:50:0{$i}",
            'logged_at' => "2026-03-18 18:50:0{$i}",
        ]);
    }

    ProcessChallengeEvents::run();

    expect(ChallengeStanding::count())->toBe(1);
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ProcessChallengeEventsTest`
Expected: All tests FAIL (class doesn't exist yet).

- [ ] **Step 4: Create ProcessChallengeEvents action**

```php
<?php

namespace App\Actions\Challenges;

use App\Actions\Util\ExtractJson;
use App\Enums\ChallengeTimelineEventType;
use App\Enums\TournamentState;
use App\Enums\TournamentStructure;
use App\Models\Challenge;
use App\Models\ChallengeStanding;
use App\Models\ChallengeTimelineEvent;
use App\Models\LogEvent;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

class ProcessChallengeEvents
{
    public static function run(): void
    {
        $eventTypes = [
            'challenge_sync',
            'challenge_state_changed',
            'challenge_round_result',
            'challenge_player_eliminated',
            'challenge_ended',
            'challenge_match_state_changed',
        ];

        $events = LogEvent::whereIn('event_type', $eventTypes)
            ->whereNull('processed_at')
            ->orderBy('logged_at')
            ->get();

        foreach ($events as $event) {
            match ($event->event_type) {
                'challenge_sync' => self::processSync($event),
                'challenge_state_changed' => self::processStateChanged($event),
                'challenge_round_result' => self::processRoundResult($event),
                'challenge_player_eliminated' => self::processElimination($event),
                'challenge_ended' => self::processEnded($event),
                'challenge_match_state_changed' => self::processMatchStateChanged($event),
                default => null,
            };

            $event->update(['processed_at' => now()]);
        }
    }

    private static function processSync(LogEvent $event): void
    {
        $json = ExtractJson::run($event->raw_text)->first();
        if (! is_array($json) || ! isset($json['EventToken'])) {
            return;
        }

        $token = $json['EventToken'];
        $tournamentData = $json['PremiereEventSyncData'] ?? [];

        $challenge = Challenge::updateOrCreate(
            ['token' => $token],
            array_filter([
                'name' => $json['Description'] ?? null,
                'description' => isset($json['FormatDescription']) ? StripBbCode::run($json['FormatDescription']) : null,
                'tournament_structure' => isset($tournamentData['TournamentStructureCd'])
                    ? TournamentStructure::fromMtgoCode($tournamentData['TournamentStructureCd'])
                    : null,
                'max_rounds' => $tournamentData['NumberOfRounds'] ?? null,
                'min_players' => $tournamentData['MinPlayers'] ?? null,
                'max_players' => $tournamentData['MaxPlayers'] ?? null,
                'player_count' => count($json['Players'] ?? []),
            ], fn ($v) => $v !== null),
        );

        // Bulk upsert player login_id → username mappings
        foreach ($json['Players'] ?? [] as $player) {
            if (isset($player['LoginID'], $player['PlayerName'])) {
                Player::updateOrCreate(
                    ['login_id' => $player['LoginID']],
                    ['username' => $player['PlayerName']],
                );
            }
        }

        Log::channel('pipeline')->info("ProcessChallengeEvents: synced challenge #{$challenge->id}", [
            'token' => $token,
            'name' => $challenge->name,
            'players' => count($json['Players'] ?? []),
        ]);
    }

    private static function processStateChanged(LogEvent $event): void
    {
        $token = $event->match_token;
        $text = $event->raw_text;

        // Extract the "to" state from the raw text
        $toState = null;
        if (preg_match('/to (\S+)\)/', $text, $m)) {
            $toState = TournamentState::fromMtgoState($m[1]);
        }

        if (! $toState) {
            return;
        }

        $updates = ['state' => $toState];

        if ($toState === TournamentState::RoundInProgress) {
            // Increment round on transition to RoundInProgress
            $existing = Challenge::where('token', $token)->first();
            $updates['current_round'] = ($existing->current_round ?? 0) + 1;
        }

        if ($toState === TournamentState::Completed) {
            $updates['ended_at'] = $event->logged_at;
        }

        // Set started_at on first non-awaiting state
        if ($toState !== TournamentState::AwaitingPlayers) {
            $existing = Challenge::where('token', $token)->first();
            if ($existing && ! $existing->started_at) {
                $updates['started_at'] = $event->logged_at;
            }
        }

        $challenge = Challenge::updateOrCreate(
            ['token' => $token],
            $updates,
        );

        ChallengeTimelineEvent::create([
            'challenge_id' => $challenge->id,
            'event_type' => ChallengeTimelineEventType::StateChanged,
            'payload' => ['to_state' => $toState->value],
            'occurred_at' => $event->logged_at,
        ]);
    }

    private static function processRoundResult(LogEvent $event): void
    {
        $json = ExtractJson::run($event->raw_text)->first();
        if (! is_array($json) || ! isset($json['Token'], $json['Round'], $json['Results'])) {
            return;
        }

        $challenge = Challenge::where('token', $json['Token'])->first();
        if (! $challenge) {
            return;
        }

        $round = (int) $json['Round'];
        $challenge->update([
            'current_round' => $round,
            'player_count' => count($json['Results']),
        ]);

        // Build a login_id → username lookup from players table
        $loginIds = collect($json['Results'])->pluck('LoginID')->all();
        $usernameMap = Player::whereIn('login_id', $loginIds)
            ->pluck('username', 'login_id')
            ->all();

        foreach ($json['Results'] as $result) {
            $loginId = (int) $result['LoginID'];

            // Build match record string from opponent results
            $records = collect($result['OpponentResults'] ?? [])
                ->sortBy('Round')
                ->map(fn ($r) => $r['Win'].'-'.$r['Loss'])
                ->implode(', ');

            ChallengeStanding::updateOrCreate(
                [
                    'challenge_id' => $challenge->id,
                    'round' => $round,
                    'login_id' => $loginId,
                ],
                [
                    'username' => $usernameMap[$loginId] ?? null,
                    'rank' => (int) $result['Rank'],
                    'points' => (int) $result['Points'],
                    'match_record' => $records,
                    'opponent_match_win_pct' => isset($result['OpponentMatchWinPercentage'])
                        ? (float) $result['OpponentMatchWinPercentage']
                        : null,
                    'game_win_pct' => isset($result['GameWinPercentage'])
                        ? (float) $result['GameWinPercentage']
                        : null,
                ],
            );
        }

        ChallengeTimelineEvent::create([
            'challenge_id' => $challenge->id,
            'round' => $round,
            'event_type' => ChallengeTimelineEventType::RoundResult,
            'payload' => ['player_count' => count($json['Results'])],
            'occurred_at' => $event->logged_at,
        ]);

        Log::channel('pipeline')->info("ProcessChallengeEvents: round {$round} results for challenge #{$challenge->id}", [
            'players' => count($json['Results']),
        ]);
    }

    private static function processElimination(LogEvent $event): void
    {
        $json = ExtractJson::run($event->raw_text)->first();
        if (! is_array($json) || ! isset($json['Token'], $json['LoginID'])) {
            return;
        }

        $challenge = Challenge::where('token', $json['Token'])->first();
        if (! $challenge) {
            return;
        }

        $loginId = (int) $json['LoginID'];
        $username = Player::where('login_id', $loginId)->value('username');

        ChallengeTimelineEvent::create([
            'challenge_id' => $challenge->id,
            'round' => $challenge->current_round,
            'event_type' => ChallengeTimelineEventType::PlayerEliminated,
            'login_id' => $loginId,
            'username' => $username,
            'payload' => ['reason' => $json['Reason'] ?? null],
            'occurred_at' => $event->logged_at,
        ]);
    }

    private static function processEnded(LogEvent $event): void
    {
        $json = ExtractJson::run($event->raw_text)->first();
        if (! is_array($json) || ! isset($json['Token'])) {
            return;
        }

        $challenge = Challenge::where('token', $json['Token'])->first();
        if (! $challenge) {
            return;
        }

        $challenge->update([
            'state' => TournamentState::Completed,
            'ended_at' => isset($json['EndDate']) ? $json['EndDate'] : $event->logged_at,
        ]);

        ChallengeTimelineEvent::create([
            'challenge_id' => $challenge->id,
            'event_type' => ChallengeTimelineEventType::StateChanged,
            'payload' => ['to_state' => TournamentState::Completed->value],
            'occurred_at' => $event->logged_at,
        ]);
    }

    private static function processMatchStateChanged(LogEvent $event): void
    {
        $token = $event->match_token;

        // Tournament match events reference match tokens, not tournament tokens.
        // We create timeline events but need to find the parent challenge.
        // For now, these are low-priority feed events — skip if no challenge found.
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ProcessChallengeEventsTest`
Expected: All tests PASS.

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --dirty`

- [ ] **Step 7: Commit**

```bash
git add app/Actions/Challenges/StripBbCode.php app/Actions/Challenges/ProcessChallengeEvents.php tests/Feature/Actions/Challenges/ProcessChallengeEventsTest.php
git commit -m "feat: ProcessChallengeEvents — sync, state, standings, eliminations"
```

---

## Task 6: Seed Command

**Files:**
- Create: `app/Console/Commands/SeedChallengesFromLog.php`

- [ ] **Step 1: Create the seed command**

```php
<?php

namespace App\Console\Commands;

use App\Actions\Challenges\ProcessChallengeEvents;
use App\Actions\Logs\ClassifyLogEvent;
use App\Models\LogEvent;
use Illuminate\Console\Command;

class SeedChallengesFromLog extends Command
{
    protected $signature = 'challenges:seed-from-log {path=storage/app/mtgo.log}';

    protected $description = 'Seed challenge data from an MTGO log file through the real pipeline';

    public function handle(): int
    {
        $path = $this->argument('path');
        $fullPath = base_path($path);

        if (! file_exists($fullPath)) {
            $this->error("Log file not found: {$fullPath}");

            return self::FAILURE;
        }

        $this->info("Reading log file: {$fullPath}");

        $challengeEventTypes = [
            'challenge_sync',
            'challenge_state_changed',
            'challenge_round_result',
            'challenge_player_eliminated',
            'challenge_ended',
            'challenge_match_state_changed',
        ];

        $lines = file($fullPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $created = 0;

        // Group multi-line log entries (lines starting with timestamp are new entries)
        $entries = [];
        $currentEntry = '';
        foreach ($lines as $line) {
            if (preg_match('/^\d{2}:\d{2}:\d{2}\s+\[/', $line)) {
                if ($currentEntry !== '') {
                    $entries[] = $currentEntry;
                }
                $currentEntry = $line;
            } else {
                $currentEntry .= "\n".$line;
            }
        }
        if ($currentEntry !== '') {
            $entries[] = $currentEntry;
        }

        $this->info('Parsed '.count($entries).' log entries');

        $bar = $this->output->createProgressBar(count($entries));

        foreach ($entries as $entry) {
            // Classify the event without persisting first
            $event = new LogEvent(['raw_text' => $entry]);
            $classified = ClassifyLogEvent::run($event);

            if ($classified->event_type && in_array($classified->event_type, $challengeEventTypes)) {
                // Extract timestamp from raw text
                $loggedAt = null;
                if (preg_match('/^(\d{2}:\d{2}:\d{2})/', $entry, $m)) {
                    $loggedAt = '2026-03-18 '.$m[1]; // Date from the log session
                }

                LogEvent::create([
                    'raw_text' => $entry,
                    'event_type' => $classified->event_type,
                    'match_token' => $classified->match_token,
                    'match_id' => $classified->match_id,
                    'game_id' => $classified->game_id,
                    'timestamp' => $m[1] ?? null,
                    'logged_at' => $loggedAt,
                ]);
                $created++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Created {$created} challenge log events");

        // Process through the real pipeline
        $this->info('Processing challenge events...');
        ProcessChallengeEvents::run();

        $challengeCount = \App\Models\Challenge::count();
        $standingCount = \App\Models\ChallengeStanding::count();
        $timelineCount = \App\Models\ChallengeTimelineEvent::count();
        $playerCount = \App\Models\Player::whereNotNull('login_id')->count();

        $this->info("Done! Created {$challengeCount} challenges, {$standingCount} standings, {$timelineCount} timeline events, {$playerCount} player mappings");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Test the command against the real log file**

Run: `php artisan challenges:seed-from-log`
Expected: Output shows challenges, standings, timeline events, and player mappings created.

- [ ] **Step 3: Verify data was created**

Run: `php artisan tinker --execute 'echo "Challenges: " . App\Models\Challenge::count() . ", Standings: " . App\Models\ChallengeStanding::count() . ", Timeline: " . App\Models\ChallengeTimelineEvent::count() . ", Players with login_id: " . App\Models\Player::whereNotNull("login_id")->count();'`
Expected: Non-zero counts for each.

- [ ] **Step 4: Run Pint**

Run: `vendor/bin/pint --dirty`

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/SeedChallengesFromLog.php
git commit -m "feat: challenges:seed-from-log command for dev seeding from log file"
```

---

## Task 7: Routes & Controllers

**Files:**
- Create: `app/Http/Controllers/Challenges/IndexController.php`
- Create: `app/Http/Controllers/Challenges/ShowController.php`
- Create: `app/Http/Controllers/Decks/ChallengesController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Create Challenges IndexController**

```php
<?php

namespace App\Http\Controllers\Challenges;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $format = $request->input('format');
        $state = $request->input('state', 'active');
        $participated = $request->boolean('participated', false);

        $query = Challenge::query()
            ->orderByRaw("CASE WHEN state = 'completed' THEN 1 ELSE 0 END")
            ->orderByDesc('started_at');

        if ($format) {
            $query->forFormat($format);
        }

        if ($state === 'active') {
            $query->active();
        } elseif ($state === 'completed') {
            $query->completed();
        }

        if ($participated) {
            $query->participated();
        }

        $challenges = $query->paginate(20)->withQueryString();

        // Attach local user rank to each challenge
        $challengeIds = collect($challenges->items())->pluck('id')->all();
        $localStandings = \App\Models\ChallengeStanding::whereIn('challenge_id', $challengeIds)
            ->where('is_local', true)
            ->select('challenge_id', 'rank', 'round')
            ->orderByDesc('round')
            ->get()
            ->unique('challenge_id')
            ->keyBy('challenge_id');

        $allFormats = Challenge::distinct()->whereNotNull('format')->pluck('format')->sort()->values()->all();

        return Inertia::render('challenges/Index', [
            'challenges' => $challenges,
            'localStandings' => $localStandings,
            'allFormats' => $allFormats,
            'filters' => [
                'format' => $format ?? '',
                'state' => $state,
                'participated' => $participated,
            ],
        ]);
    }
}
```

- [ ] **Step 2: Create Challenges ShowController**

```php
<?php

namespace App\Http\Controllers\Challenges;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowController extends Controller
{
    public function __invoke(Request $request, Challenge $challenge): Response
    {
        // Get standings for the latest round
        $latestRound = $challenge->standings()->max('round') ?? 0;

        $standings = $challenge->standings()
            ->where('round', $latestRound)
            ->orderBy('rank')
            ->get();

        // Get all timeline events grouped by round
        $timelineEvents = $challenge->timelineEvents()
            ->orderByDesc('occurred_at')
            ->get();

        // Get eliminated player login_ids from timeline
        $eliminatedIds = $challenge->timelineEvents()
            ->where('event_type', 'player_eliminated')
            ->pluck('login_id')
            ->filter()
            ->all();

        return Inertia::render('challenges/Show', [
            'challenge' => $challenge,
            'standings' => $standings,
            'timelineEvents' => $timelineEvents,
            'eliminatedIds' => $eliminatedIds,
            'latestRound' => $latestRound,
            'fromDeck' => $request->input('deck_id'),
        ]);
    }
}
```

- [ ] **Step 3: Create Decks ChallengesController**

```php
<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GetDeckViewSharedProps;
use App\Concerns\HasTimeframeFilter;
use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\Deck;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChallengesController extends Controller
{
    use HasTimeframeFilter;

    public function __invoke(Request $request, Deck $deck): Response
    {
        $timeframe = $request->input('timeframe', 'alltime');
        [$from, $to] = $this->getTimeRange($timeframe);

        $shared = GetDeckViewSharedProps::run($deck, $from, $to);

        // Find challenges linked to this deck via matches
        $challenges = Challenge::whereHas('matches', function ($q) use ($deck) {
            $q->whereHas('deckVersion', fn ($dv) => $dv->where('deck_id', $deck->id));
        })
            ->orderByDesc('started_at')
            ->get();

        // Attach local standings
        $challengeIds = $challenges->pluck('id')->all();
        $localStandings = \App\Models\ChallengeStanding::whereIn('challenge_id', $challengeIds)
            ->where('is_local', true)
            ->orderByDesc('round')
            ->get()
            ->unique('challenge_id')
            ->keyBy('challenge_id');

        return Inertia::render('decks/Challenges', [
            ...$shared,
            'currentPage' => 'challenges',
            'timeframe' => $timeframe,
            'challenges' => $challenges,
            'localStandings' => $localStandings,
        ]);
    }
}
```

- [ ] **Step 4: Add routes to web.php**

In `routes/web.php`, add the challenges group after the leagues group (after line 99), and add the deck challenges route in the decks group:

After the leagues group:
```php
$router->group([
    'prefix' => 'challenges',
], function (Router $group) {
    $group->get('/', \App\Http\Controllers\Challenges\IndexController::class)->name('challenges.index');
    $group->get('{challenge}', \App\Http\Controllers\Challenges\ShowController::class)->name('challenges.show');
});
```

In the decks group, after the leagues route (after line 114):
```php
$group->get('{deck:id}/challenges', \App\Http\Controllers\Decks\ChallengesController::class)->name('decks.challenges');
```

- [ ] **Step 5: Generate Wayfinder types**

Run: `php artisan wayfinder:generate`
Expected: Generates TypeScript types for the new controllers.

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --dirty`

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Challenges/IndexController.php app/Http/Controllers/Challenges/ShowController.php app/Http/Controllers/Decks/ChallengesController.php routes/web.php
git commit -m "feat: challenge routes and controllers — index, show, deck tab"
```

---

## Task 8: Controller Tests

**Files:**
- Create: `tests/Feature/Http/Challenges/IndexControllerTest.php`
- Create: `tests/Feature/Http/Challenges/ShowControllerTest.php`
- Create: `tests/Feature/Http/Decks/ChallengesControllerTest.php`

- [ ] **Step 1: Write IndexController tests**

```php
<?php

use App\Enums\TournamentState;
use App\Models\Challenge;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the challenges index page', function () {
    Challenge::factory()->inProgress()->create();

    $response = $this->get('/challenges');

    $response->assertOk()
        ->assertInertiaComponent('challenges/Index');
});

it('filters by format', function () {
    Challenge::factory()->create(['format' => 'Modern']);
    Challenge::factory()->create(['format' => 'Legacy']);

    $response = $this->get('/challenges?format=Modern');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('challenges.data', 1)
        );
});

it('filters active challenges by default', function () {
    Challenge::factory()->inProgress()->create();
    Challenge::factory()->completed()->create();

    $response = $this->get('/challenges');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('challenges.data', 1)
        );
});

it('shows all challenges when state is all', function () {
    Challenge::factory()->inProgress()->create();
    Challenge::factory()->completed()->create();

    $response = $this->get('/challenges?state=all');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('challenges.data', 2)
        );
});
```

- [ ] **Step 2: Write ShowController tests**

```php
<?php

use App\Models\Challenge;
use App\Models\ChallengeStanding;
use App\Models\ChallengeTimelineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the challenge detail page', function () {
    $challenge = Challenge::factory()->inProgress()->create();

    $response = $this->get("/challenges/{$challenge->id}");

    $response->assertOk()
        ->assertInertiaComponent('challenges/Show');
});

it('includes standings for the latest round', function () {
    $challenge = Challenge::factory()->inProgress()->create();

    ChallengeStanding::create([
        'challenge_id' => $challenge->id,
        'round' => 1,
        'login_id' => 12345,
        'username' => 'TestPlayer',
        'rank' => 1,
        'points' => 3,
        'match_record' => '2-0',
    ]);

    $response = $this->get("/challenges/{$challenge->id}");

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('standings', 1)
        );
});
```

- [ ] **Step 3: Write Decks ChallengesController tests**

```php
<?php

use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the deck challenges tab', function () {
    $deck = Deck::factory()->create();

    $response = $this->get("/decks/{$deck->id}/challenges");

    $response->assertOk()
        ->assertInertiaComponent('decks/Challenges');
});
```

- [ ] **Step 4: Run all controller tests**

Run: `php artisan test --compact --filter="IndexControllerTest|ShowControllerTest|ChallengesControllerTest"`
Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Http/Challenges/IndexControllerTest.php tests/Feature/Http/Challenges/ShowControllerTest.php tests/Feature/Http/Decks/ChallengesControllerTest.php
git commit -m "test: controller tests for challenge index, show, and deck tab"
```

---

## Task 9: Vue Pages — Challenges Index

**Files:**
- Create: `resources/js/pages/challenges/Index.vue`
- Modify: `resources/js/components/AppNav.vue`

- [ ] **Step 1: Add Challenges to AppNav**

In `resources/js/components/AppNav.vue`, add the import:

```typescript
import ChallengesIndexController from '@/actions/App/Http/Controllers/Challenges/IndexController';
```

Add the `Sword` icon import (or another appropriate icon — `Trophy` is taken by Leagues):

```typescript
import { Bug, Layers, LayoutDashboard, Puzzle, Swords, Trophy, Medal } from 'lucide-vue-next';
```

Add the nav item between Leagues and Opponents in the `nav` array:

```typescript
{ label: 'Challenges', icon: Medal, href: ChallengesIndexController.url() },
```

- [ ] **Step 2: Create the Challenges Index page**

```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import ShowController from '@/actions/App/Http/Controllers/Challenges/ShowController';
import { computed } from 'vue';

type PaginatorLink = { url: string | null; label: string; active: boolean };

type Challenge = {
    id: number;
    token: string;
    name: string | null;
    format: string | null;
    state: string;
    current_round: number | null;
    max_rounds: number | null;
    player_count: number;
    max_players: number | null;
    started_at: string | null;
    participated: boolean;
};

type LocalStanding = {
    challenge_id: number;
    rank: number;
    round: number;
};

const props = defineProps<{
    challenges: {
        data: Challenge[];
        links: PaginatorLink[];
        current_page: number;
        last_page: number;
        total: number;
    };
    localStandings: Record<number, LocalStanding>;
    allFormats: string[];
    filters: {
        format: string;
        state: string;
        participated: boolean;
    };
}>();

function setFilter(key: string, value: string | boolean | undefined) {
    const params: Record<string, string | boolean | undefined> = {
        format: props.filters.format || undefined,
        state: props.filters.state,
        participated: props.filters.participated || undefined,
        [key]: value || undefined,
    };
    router.get('/challenges', params, { preserveState: true, preserveScroll: true });
}

function stateLabel(state: string): string {
    return state.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function stateColor(state: string): string {
    if (state === 'completed') return 'text-zinc-400';
    if (state === 'round_in_progress') return 'text-green-500';
    if (state === 'between_rounds') return 'text-yellow-500';
    return 'text-blue-500';
}

function relativeTime(dateStr: string | null): string {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    const now = new Date();
    const diff = Math.floor((now.getTime() - date.getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <h1 class="text-lg font-semibold">Challenges</h1>

        <!-- Filters -->
        <div class="flex flex-wrap items-center gap-2">
            <select
                class="rounded border border-zinc-700 bg-zinc-800 px-2 py-1 text-sm text-zinc-200"
                :value="filters.format"
                @change="setFilter('format', ($event.target as HTMLSelectElement).value)"
            >
                <option value="">All Formats</option>
                <option v-for="f in allFormats" :key="f" :value="f">{{ f }}</option>
            </select>

            <div class="flex gap-1">
                <Button
                    v-for="s in ['active', 'completed', 'all']"
                    :key="s"
                    size="sm"
                    :variant="filters.state === s ? 'default' : 'ghost'"
                    @click="setFilter('state', s)"
                >
                    {{ s.charAt(0).toUpperCase() + s.slice(1) }}
                </Button>
            </div>

            <Button
                size="sm"
                :variant="filters.participated ? 'default' : 'ghost'"
                @click="setFilter('participated', !filters.participated)"
            >
                Participated
            </Button>
        </div>

        <!-- Empty state -->
        <div v-if="challenges.data.length === 0" class="py-12 text-center text-sm text-zinc-500">
            No challenges match your filters.
        </div>

        <!-- Table -->
        <Card v-if="challenges.data.length > 0">
            <CardContent class="p-0">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-800 text-left text-xs text-zinc-500">
                            <th class="px-3 py-2">Name</th>
                            <th class="px-3 py-2">Format</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Round</th>
                            <th class="px-3 py-2">Players</th>
                            <th class="px-3 py-2">Started</th>
                            <th class="px-3 py-2">Your Rank</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="challenge in challenges.data"
                            :key="challenge.id"
                            class="border-b border-zinc-800/50 hover:bg-zinc-800/30"
                        >
                            <td class="px-3 py-2">{{ challenge.name || 'Challenge' }}</td>
                            <td class="px-3 py-2">{{ challenge.format || '-' }}</td>
                            <td class="px-3 py-2">
                                <span :class="stateColor(challenge.state)" class="text-xs font-medium">
                                    {{ stateLabel(challenge.state) }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <template v-if="challenge.current_round">
                                    {{ challenge.current_round }}<span v-if="challenge.max_rounds">/{{ challenge.max_rounds }}</span>
                                </template>
                                <template v-else>-</template>
                            </td>
                            <td class="px-3 py-2">
                                {{ challenge.player_count }}<span v-if="challenge.max_players">/{{ challenge.max_players }}</span>
                            </td>
                            <td class="px-3 py-2 text-zinc-400">
                                {{ relativeTime(challenge.started_at) }}
                            </td>
                            <td class="px-3 py-2">
                                <template v-if="localStandings[challenge.id]">
                                    #{{ localStandings[challenge.id].rank }}
                                </template>
                                <template v-else>-</template>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Link
                                    :href="ShowController.url({ challenge: challenge.id })"
                                    class="text-xs text-blue-400 hover:text-blue-300"
                                >
                                    View
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <!-- Pagination -->
        <div v-if="challenges.last_page > 1" class="flex justify-center gap-1">
            <Button
                v-for="link in challenges.links"
                :key="link.label"
                size="sm"
                :variant="link.active ? 'default' : 'ghost'"
                :disabled="!link.url"
                @click="link.url && router.get(link.url, {}, { preserveState: true })"
                v-html="link.label"
            />
        </div>
    </div>
</template>
```

- [ ] **Step 3: Build frontend and verify page loads**

Run: `npm run build`
Then visit `/challenges` in the browser.
Expected: Page renders with seeded challenge data, filters work, table displays correctly.

- [ ] **Step 4: Commit**

```bash
git add resources/js/pages/challenges/Index.vue resources/js/components/AppNav.vue
git commit -m "feat: challenges index page with filters and nav item"
```

---

## Task 10: Vue Pages — Challenge Detail

**Files:**
- Create: `resources/js/pages/challenges/Show.vue`

- [ ] **Step 1: Create the Challenge Show page**

```vue
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Card, CardContent } from '@/components/ui/card';
import IndexController from '@/actions/App/Http/Controllers/Challenges/IndexController';
import DashboardController from '@/actions/App/Http/Controllers/Decks/DashboardController';
import { computed, onUnmounted } from 'vue';

type Standing = {
    id: number;
    login_id: number;
    username: string | null;
    rank: number;
    points: number;
    match_record: string;
    opponent_match_win_pct: number | null;
    game_win_pct: number | null;
    is_local: boolean;
};

type TimelineEvent = {
    id: number;
    round: number | null;
    event_type: string;
    login_id: number | null;
    username: string | null;
    payload: Record<string, unknown> | null;
    occurred_at: string;
};

type Challenge = {
    id: number;
    name: string | null;
    format: string | null;
    description: string | null;
    tournament_structure: string | null;
    state: string;
    current_round: number | null;
    max_rounds: number | null;
    player_count: number;
    min_players: number | null;
    max_players: number | null;
    started_at: string | null;
    ended_at: string | null;
    participated: boolean;
};

const props = defineProps<{
    challenge: Challenge;
    standings: Standing[];
    timelineEvents: TimelineEvent[];
    eliminatedIds: number[];
    latestRound: number;
    fromDeck: number | null;
}>();

const isActive = computed(() => props.challenge.state !== 'completed');

// Poll every 30 seconds while active
let pollInterval: ReturnType<typeof setInterval> | null = null;
if (isActive.value) {
    pollInterval = setInterval(() => {
        router.reload({ only: ['challenge', 'standings', 'timelineEvents', 'eliminatedIds', 'latestRound'] });
    }, 30000);
}

onUnmounted(() => {
    if (pollInterval) clearInterval(pollInterval);
});

const localStanding = computed(() => props.standings.find(s => s.is_local));

// Group timeline events by round
const groupedTimeline = computed(() => {
    const groups: Record<string, TimelineEvent[]> = {};
    for (const event of props.timelineEvents) {
        const key = event.round !== null ? `Round ${event.round}` : 'General';
        if (!groups[key]) groups[key] = [];
        groups[key].push(event);
    }
    return groups;
});

function stateLabel(state: string): string {
    return state.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function stateColor(state: string): string {
    if (state === 'completed') return 'text-zinc-400';
    if (state === 'round_in_progress') return 'text-green-500';
    if (state === 'between_rounds') return 'text-yellow-500';
    return 'text-blue-500';
}

function eventDescription(event: TimelineEvent): string {
    switch (event.event_type) {
        case 'state_changed':
            return `Challenge moved to ${stateLabel(event.payload?.to_state as string ?? 'unknown')}`;
        case 'player_eliminated':
            return `${event.username ?? `Player ${event.login_id}`} ${(event.payload?.reason as string) === 'Drop' ? 'dropped' : 'eliminated'}`;
        case 'round_result':
            return `Round ${event.round} standings posted (${event.payload?.player_count ?? '?'} players)`;
        default:
            return event.event_type;
    }
}

function eventDotColor(eventType: string): string {
    switch (eventType) {
        case 'state_changed': return 'bg-blue-500';
        case 'player_eliminated': return 'bg-red-500';
        case 'round_result': return 'bg-green-500';
        default: return 'bg-zinc-500';
    }
}

function formatTime(dateStr: string): string {
    return new Date(dateStr).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <!-- Back navigation -->
        <div class="flex items-center gap-3 text-sm">
            <Link :href="IndexController.url()" class="text-zinc-400 hover:text-zinc-200">
                &larr; Back to Challenges
            </Link>
            <Link
                v-if="fromDeck"
                :href="DashboardController.url({ deck: fromDeck })"
                class="text-zinc-400 hover:text-zinc-200"
            >
                &larr; Back to Deck
            </Link>
        </div>

        <h1 class="text-lg font-semibold">{{ challenge.name || 'Challenge' }}</h1>

        <!-- Three column layout -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[240px_1fr_280px]">
            <!-- Left: Details -->
            <Card>
                <CardContent class="flex flex-col gap-3 p-4 text-sm">
                    <div v-if="challenge.format">
                        <div class="text-xs text-zinc-500">Format</div>
                        <div>{{ challenge.format }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-zinc-500">Status</div>
                        <span :class="stateColor(challenge.state)" class="font-medium">
                            {{ stateLabel(challenge.state) }}
                        </span>
                    </div>
                    <div v-if="challenge.tournament_structure">
                        <div class="text-xs text-zinc-500">Structure</div>
                        <div class="capitalize">{{ challenge.tournament_structure }}</div>
                    </div>
                    <div v-if="challenge.current_round">
                        <div class="text-xs text-zinc-500">Round</div>
                        <div>{{ challenge.current_round }}<span v-if="challenge.max_rounds"> of {{ challenge.max_rounds }}</span></div>
                    </div>
                    <div>
                        <div class="text-xs text-zinc-500">Players</div>
                        <div>{{ challenge.player_count }}<span v-if="challenge.max_players"> / {{ challenge.max_players }}</span></div>
                    </div>
                    <div v-if="challenge.started_at">
                        <div class="text-xs text-zinc-500">Started</div>
                        <div>{{ new Date(challenge.started_at).toLocaleString() }}</div>
                    </div>
                    <div v-if="challenge.ended_at">
                        <div class="text-xs text-zinc-500">Ended</div>
                        <div>{{ new Date(challenge.ended_at).toLocaleString() }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-zinc-500">Your Status</div>
                        <div>{{ challenge.participated ? 'Participating' : 'Spectating' }}</div>
                    </div>
                </CardContent>
            </Card>

            <!-- Middle: Standings -->
            <Card>
                <CardContent class="p-0">
                    <div class="max-h-[600px] overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-zinc-900">
                                <tr class="border-b border-zinc-800 text-left text-xs text-zinc-500">
                                    <th class="px-3 py-2">Rank</th>
                                    <th class="px-3 py-2">Player</th>
                                    <th class="px-3 py-2">Pts</th>
                                    <th class="px-3 py-2">Record</th>
                                    <th class="px-3 py-2">OMW%</th>
                                    <th class="px-3 py-2">GW%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="standing in standings"
                                    :key="standing.id"
                                    :class="{
                                        'bg-blue-500/10': standing.is_local,
                                        'text-zinc-500 line-through': eliminatedIds.includes(standing.login_id),
                                    }"
                                    class="border-b border-zinc-800/50"
                                >
                                    <td class="px-3 py-1.5">{{ standing.rank }}</td>
                                    <td class="px-3 py-1.5" :class="{ 'font-medium text-blue-400': standing.is_local }">
                                        {{ standing.username ?? `Player ${standing.login_id}` }}
                                    </td>
                                    <td class="px-3 py-1.5">{{ standing.points }}</td>
                                    <td class="px-3 py-1.5 text-zinc-400">{{ standing.match_record }}</td>
                                    <td class="px-3 py-1.5 text-zinc-400">
                                        {{ standing.opponent_match_win_pct ? (standing.opponent_match_win_pct * 100).toFixed(1) + '%' : '-' }}
                                    </td>
                                    <td class="px-3 py-1.5 text-zinc-400">
                                        {{ standing.game_win_pct ? (standing.game_win_pct * 100).toFixed(1) + '%' : '-' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pinned local standing -->
                    <div v-if="localStanding && standings.indexOf(localStanding) > 15" class="border-t border-blue-500/30 bg-blue-500/10">
                        <table class="w-full text-sm">
                            <tbody>
                                <tr>
                                    <td class="px-3 py-1.5">{{ localStanding.rank }}</td>
                                    <td class="px-3 py-1.5 font-medium text-blue-400">
                                        {{ localStanding.username ?? 'You' }}
                                    </td>
                                    <td class="px-3 py-1.5">{{ localStanding.points }}</td>
                                    <td class="px-3 py-1.5 text-zinc-400">{{ localStanding.match_record }}</td>
                                    <td class="px-3 py-1.5 text-zinc-400">
                                        {{ localStanding.opponent_match_win_pct ? (localStanding.opponent_match_win_pct * 100).toFixed(1) + '%' : '-' }}
                                    </td>
                                    <td class="px-3 py-1.5 text-zinc-400">
                                        {{ localStanding.game_win_pct ? (localStanding.game_win_pct * 100).toFixed(1) + '%' : '-' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <!-- Right: Timeline -->
            <Card>
                <CardContent class="p-4">
                    <h3 class="mb-3 text-xs font-medium text-zinc-500">Timeline</h3>
                    <div class="max-h-[560px] space-y-4 overflow-y-auto">
                        <div v-for="(events, group) in groupedTimeline" :key="group">
                            <div class="mb-2 text-xs font-medium text-zinc-400">{{ group }}</div>
                            <div class="space-y-2">
                                <div
                                    v-for="event in events"
                                    :key="event.id"
                                    class="flex items-start gap-2 text-xs"
                                >
                                    <div :class="eventDotColor(event.event_type)" class="mt-1 size-2 shrink-0 rounded-full" />
                                    <div class="flex-1">
                                        <div class="text-zinc-300">{{ eventDescription(event) }}</div>
                                        <div class="text-zinc-600">{{ formatTime(event.occurred_at) }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-if="Object.keys(groupedTimeline).length === 0" class="text-xs text-zinc-600">
                            No events yet.
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Build frontend and verify page loads**

Run: `npm run build`
Then visit `/challenges/{id}` for a seeded challenge.
Expected: Three-column layout renders with standings table, timeline feed, and details panel.

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/challenges/Show.vue
git commit -m "feat: challenge detail page — standings, timeline, live polling"
```

---

## Task 11: Vue Pages — Deck Challenges Tab

**Files:**
- Create: `resources/js/pages/decks/Challenges.vue`
- Modify: `resources/js/components/decks/DeckSidebar.vue`

- [ ] **Step 1: Add Challenges nav item to DeckSidebar**

In `resources/js/components/decks/DeckSidebar.vue`, add the import:

```typescript
import DeckChallengesController from '@/actions/App/Http/Controllers/Decks/ChallengesController';
```

Add an icon import (reuse `Medal` from lucide-vue-next).

Add the nav item after the `leagues` item in the `navItems` computed array:

```typescript
{ key: 'challenges', label: 'Challenges', icon: Medal, href: DeckChallengesController.url({ deck: props.deck.id }) + timeframeQuery.value },
```

- [ ] **Step 2: Create the Deck Challenges page**

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Card, CardContent } from '@/components/ui/card';
import ShowController from '@/actions/App/Http/Controllers/Challenges/ShowController';
import type { DeckData } from '@/types/decks';

type Challenge = {
    id: number;
    name: string | null;
    format: string | null;
    state: string;
    current_round: number | null;
    max_rounds: number | null;
    started_at: string | null;
};

type LocalStanding = {
    challenge_id: number;
    rank: number;
    round: number;
};

const props = defineProps<{
    deck: DeckData;
    challenges: Challenge[];
    localStandings: Record<number, LocalStanding>;
    currentPage: string;
    timeframe: string;
}>();

function stateLabel(state: string): string {
    if (state === 'completed') return 'Completed';
    return 'In Progress';
}

function stateColor(state: string): string {
    return state === 'completed' ? 'text-zinc-400' : 'text-green-500';
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <h2 class="text-sm font-semibold">Challenges</h2>

        <div v-if="challenges.length === 0" class="py-8 text-center text-sm text-zinc-500">
            No challenges found for this deck. Challenges will appear here once you participate in a challenge with this deck.
        </div>

        <Card v-if="challenges.length > 0">
            <CardContent class="p-0">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-800 text-left text-xs text-zinc-500">
                            <th class="px-3 py-2">Name</th>
                            <th class="px-3 py-2">Format</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Round</th>
                            <th class="px-3 py-2">Your Rank</th>
                            <th class="px-3 py-2">Date</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="challenge in challenges"
                            :key="challenge.id"
                            class="border-b border-zinc-800/50 hover:bg-zinc-800/30"
                        >
                            <td class="px-3 py-2">{{ challenge.name || 'Challenge' }}</td>
                            <td class="px-3 py-2">{{ challenge.format || '-' }}</td>
                            <td class="px-3 py-2">
                                <span :class="stateColor(challenge.state)" class="text-xs font-medium">
                                    {{ stateLabel(challenge.state) }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <template v-if="challenge.current_round">
                                    {{ challenge.current_round }}<span v-if="challenge.max_rounds">/{{ challenge.max_rounds }}</span>
                                </template>
                                <template v-else>-</template>
                            </td>
                            <td class="px-3 py-2">
                                <template v-if="localStandings[challenge.id]">
                                    #{{ localStandings[challenge.id].rank }}
                                </template>
                                <template v-else>-</template>
                            </td>
                            <td class="px-3 py-2 text-zinc-400">
                                {{ challenge.started_at ? new Date(challenge.started_at).toLocaleDateString() : '-' }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Link
                                    :href="ShowController.url({ challenge: challenge.id }) + `?from=deck&deck_id=${deck.id}`"
                                    class="text-xs text-blue-400 hover:text-blue-300"
                                >
                                    View
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
```

- [ ] **Step 3: Build frontend and verify**

Run: `npm run build`
Visit a deck page and check that the Challenges tab appears in the sidebar. Click it to verify the page renders (will be empty until participation is implemented).

- [ ] **Step 4: Commit**

```bash
git add resources/js/pages/decks/Challenges.vue resources/js/components/decks/DeckSidebar.vue
git commit -m "feat: deck challenges tab and sidebar nav item"
```

---

## Task 12: Final Verification

- [ ] **Step 1: Run full test suite**

Run: `php artisan test --compact`
Expected: All tests pass, no regressions.

- [ ] **Step 2: Run Pint on all changed files**

Run: `vendor/bin/pint --dirty`

- [ ] **Step 3: Build frontend**

Run: `npm run build`
Expected: No build errors.

- [ ] **Step 4: Manual smoke test**

1. Run `php artisan challenges:seed-from-log` if not already run
2. Visit `/challenges` — verify index page shows seeded challenges with format filter and state toggle
3. Click "View" on a challenge — verify detail page shows standings, timeline, details
4. Check nav — Challenges appears between Leagues and Opponents
5. Visit a deck's Challenges tab — verify empty state message
6. Verify polling: on the detail page of an active challenge, the page should reload data every 30 seconds (check network tab)

- [ ] **Step 5: Commit any remaining changes**

```bash
git add -A
git commit -m "chore: final cleanup for challenge tab feature"
```

---

## Deferred Work (Not In This Plan)

- **Retention cleanup job** — scheduled command to soft-delete spectated challenges older than N days. Not needed for initial release; data won't accumulate quickly enough to matter yet.
- **LinkMatchToChallenge** — participation pipeline, bridges match and challenge domains. Requires participation log data.
- **Deck view KPIs** — best result, top 8/16 counts on deck challenges tab.
- **CHALLENGE_MATCH_STATE_CHANGED processing** — currently a no-op in the pipeline. These events reference match tokens not tournament tokens, so linking them to a challenge requires additional lookup logic.
