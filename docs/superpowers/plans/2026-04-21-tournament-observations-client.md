# Tournament Observations — Client Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Client half of sub-project 1: capture tournament log events, stamp participated matches with tournament context, queue observations, and ship them in gzipped batches to the mymtgo API.

**Architecture:** New tournament-specific classifier branches feed a queue table keyed by `log_event_id`. A scheduled sender job drains the queue in batches (up to 200 rows, gzipped). Participated tournament matches get two new nullable columns (`tournament_event_id`, `tournament_round`) stamped on join. `AssignLeague` skips tournament matches so they don't get bucketed as phantom leagues. No projection logic, no UI, no hydration — pure observation capture.

**Tech Stack:** PHP 8.4, Laravel 12, Pest v4, Pint v1, SQLite, `Http::` client, native `gzencode`.

**Spec:** `docs/superpowers/specs/2026-04-21-tournament-observations-design.md`

---

## File Structure

**New files:**

- `database/migrations/2026_04_21_120000_add_tournament_fields_to_matches_table.php`
- `database/migrations/2026_04_21_120100_add_tournament_token_to_log_events_table.php`
- `database/migrations/2026_04_21_120200_create_tournament_observation_queue_table.php`
- `app/Models/TournamentObservationQueue.php`
- `app/Actions/Tournaments/ExtractTournamentPayload.php`
- `app/Actions/Tournaments/EnqueueTournamentObservations.php`
- `app/Jobs/ShipTournamentObservations.php`
- `tests/Fixtures/log_samples/tournament_match_joined.txt` (real log fragments provided 2026-04-21)
- `tests/Feature/Actions/Logs/ClassifyLogEventTournamentTest.php`
- `tests/Feature/Actions/Matches/AdvanceMatchStateTournamentTest.php`
- `tests/Feature/Actions/Matches/AssignLeagueTournamentTest.php`
- `tests/Feature/Actions/Tournaments/ExtractTournamentPayloadTest.php`
- `tests/Feature/Actions/Tournaments/EnqueueTournamentObservationsTest.php`
- `tests/Feature/Jobs/ShipTournamentObservationsTest.php`

**Files to modify:**

- `app/Enums/LogEventType.php` — add 7 tournament cases
- `app/Actions/Logs/ClassifyLogEvent.php` — add tournament classifier branches
- `app/Actions/Matches/AdvanceMatchState.php` — stamp tournament columns on join
- `app/Actions/Matches/AssignLeague.php` — short-circuit on tournament Description
- `app/Actions/Pipeline/ProcessMatchEvents.php` — exclude tournament event types from match query
- `app/Actions/Pipeline/RunPipeline.php` — add enqueue step
- `app/Managers/MtgoManager.php` — add scheduled sender job

---

## Task 1: Migrations (matches + log_events + queue)

**Files:**
- Create: `database/migrations/2026_04_21_120000_add_tournament_fields_to_matches_table.php`
- Create: `database/migrations/2026_04_21_120100_add_tournament_token_to_log_events_table.php`
- Create: `database/migrations/2026_04_21_120200_create_tournament_observation_queue_table.php`

- [ ] **Step 1: Generate migrations**

Run:
```bash
php artisan make:migration add_tournament_fields_to_matches_table --no-interaction
php artisan make:migration add_tournament_token_to_log_events_table --no-interaction
php artisan make:migration create_tournament_observation_queue_table --no-interaction
```

Expected: three files created under `database/migrations/`. Rename them to the paths above if Laravel chose different timestamps.

- [ ] **Step 2: Fill in the matches migration**

Contents of `database/migrations/2026_04_21_120000_add_tournament_fields_to_matches_table.php`:

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
            $table->unsignedInteger('tournament_event_id')->nullable()->after('id');
            $table->unsignedSmallInteger('tournament_round')->nullable()->after('tournament_event_id');
            $table->index('tournament_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex(['tournament_event_id']);
            $table->dropColumn(['tournament_event_id', 'tournament_round']);
        });
    }
};
```

- [ ] **Step 3: Fill in the log_events migration**

Contents:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_events', function (Blueprint $table) {
            $table->string('tournament_token')->nullable()->after('match_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('log_events', function (Blueprint $table) {
            $table->dropIndex(['tournament_token']);
            $table->dropColumn('tournament_token');
        });
    }
};
```

- [ ] **Step 4: Fill in the queue migration**

Contents:

```php
<?php

use App\Models\LogEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_observation_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(LogEvent::class)->unique()->constrained()->cascadeOnDelete();
            $table->string('tournament_token')->nullable()->index();
            $table->string('match_token')->nullable()->index();
            $table->string('event_type')->index();
            $table->json('payload');
            $table->dateTime('client_observed_at');
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_observation_queue');
    }
};
```

- [ ] **Step 5: Run migrations to verify they apply cleanly**

Run:
```bash
php artisan migrate
```

Expected: `Migrated: ...add_tournament_fields_to_matches_table`, `...add_tournament_token_to_log_events_table`, `...create_tournament_observation_queue_table`.

- [ ] **Step 6: Run Pint on the migrations**

Run:
```bash
vendor/bin/pint --dirty --format agent
```

Expected: `{"result":"pass"}` or `{"result":"fixed", ...}`.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_04_21_120000_add_tournament_fields_to_matches_table.php \
        database/migrations/2026_04_21_120100_add_tournament_token_to_log_events_table.php \
        database/migrations/2026_04_21_120200_create_tournament_observation_queue_table.php
git commit -m "feat: migrations for tournament observations"
```

---

## Task 2: LogEventType enum additions

**Files:**
- Modify: `app/Enums/LogEventType.php`

- [ ] **Step 1: Add the seven tournament cases**

Replace the contents of `app/Enums/LogEventType.php` with:

```php
<?php

namespace App\Enums;

enum LogEventType: string
{
    case MATCH_STATE_CHANGED = 'match_state_changed';
    case GAME_STATE_UPDATE = 'game_state_update';
    case DECK_USED = 'deck_used';
    case LEAGUE_JOIN_REQUEST = 'league_join_request';
    case LEAGUE_JOINED = 'league_joined';

    case TOURNAMENT_SYNC = 'tournament_sync';
    case TOURNAMENT_STATE_CHANGED = 'tournament_state_changed';
    case TOURNAMENT_ROUND_RESULT = 'tournament_round_result';
    case TOURNAMENT_ROUND_INFO = 'tournament_round_info';
    case TOURNAMENT_PLAYER_ELIMINATED = 'tournament_player_eliminated';
    case TOURNAMENT_ENDED = 'tournament_ended';
    case TOURNAMENT_MATCH_STATE_CHANGED = 'tournament_match_state_changed';

    /**
     * @return array<string> Tournament event_type values.
     */
    public static function tournamentValues(): array
    {
        return [
            self::TOURNAMENT_SYNC->value,
            self::TOURNAMENT_STATE_CHANGED->value,
            self::TOURNAMENT_ROUND_RESULT->value,
            self::TOURNAMENT_ROUND_INFO->value,
            self::TOURNAMENT_PLAYER_ELIMINATED->value,
            self::TOURNAMENT_ENDED->value,
            self::TOURNAMENT_MATCH_STATE_CHANGED->value,
        ];
    }
}
```

- [ ] **Step 2: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Commit**

```bash
git add app/Enums/LogEventType.php
git commit -m "feat: add tournament log event types"
```

---

## Task 3: TournamentObservationQueue model

**Files:**
- Create: `app/Models/TournamentObservationQueue.php`

- [ ] **Step 1: Create the model**

Contents:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentObservationQueue extends Model
{
    protected $table = 'tournament_observation_queue';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'client_observed_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];

    /** @return BelongsTo<LogEvent, $this> */
    public function logEvent(): BelongsTo
    {
        return $this->belongsTo(LogEvent::class);
    }
}
```

- [ ] **Step 2: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/TournamentObservationQueue.php
git commit -m "feat: TournamentObservationQueue model"
```

---

## Task 4: Test fixture — real tournament match join events

**Files:**
- Create: `tests/Fixtures/log_samples/tournament_match_joined.txt`

- [ ] **Step 1: Create the fixture file with real log content**

The user provided four real `TournamentMatchJoinedEventUnderwayState` events on 2026-04-21 — the single-line lines that span 16:00:06, 18:20:11, 19:10:27, 20:01:18. Paste each as a separate block separated by a blank line into the fixture file:

```
16:00:06 [INF] (Game Management|Processing Registered Handler for GsMessageMessage in TournamentMatchJoinedEventUnderwayState) Processor: TournamentMatchJoinedEventUnderwayState Message: {"MatchToken":"2212f089-c748-42c8-ab3f-61c463a4278f","MatchID":286451927,"GameID":948240580,"MetaMessage":[108,2,0,0,82,18,181,208,196,0,133,56,60,0,0,0,32,57,2,0,0,0,0,0]} Receiver: Event Token=2212f089-c748-42c8-ab3f-61c463a4278fEvent Id:286451927CurrentStateProcessor=TournamentMatchJoinedEventUnderwayStateCurrentState=Joined, EventUnderway, ConnectedDescription=Tournament:12839714 Round:1Match Id:286451927Match Token:2212f089-c748-42c8-ab3f-61c463a4278fPlayFormatCd=CMODERNGameStructureCd= ModernJoinedToGame=TrueAmIHost=FalsePlayerIds=964394,2903591

18:20:11 [INF] (Game Management|Processing Registered Handler for GsMessageMessage in TournamentMatchJoinedEventUnderwayState) Processor: TournamentMatchJoinedEventUnderwayState Message: {"MatchToken":"3bb83a6a-9d7f-473e-b3ad-c174de849c71","MatchID":286415435,"GameID":948100826,"MetaMessage":[108,2,0,0]} Receiver: Event Token=3bb83a6a-9d7f-473e-b3ad-c174de849c71Event Id:286415435CurrentStateProcessor=TournamentMatchJoinedEventUnderwayStateCurrentState=Joined, EventUnderway, ConnectedDescription=Tournament:12839688 Round:2Match Id:286415435Match Token:3bb83a6a-9d7f-473e-b3ad-c174de849c71PlayFormatCd=CMODERNGameStructureCd= ModernJoinedToGame=TrueAmIHost=FalsePlayerIds=536053,964394

19:10:27 [INF] (Game Management|Processing Registered Handler for GsMessageMessage in TournamentMatchJoinedEventUnderwayState) Processor: TournamentMatchJoinedEventUnderwayState Message: {"MatchToken":"459eabbd-84b0-4549-a499-d53499350926","MatchID":286416330,"GameID":948104240,"MetaMessage":[108,2]} Receiver: Event Token=459eabbd-84b0-4549-a499-d53499350926Event Id:286416330CurrentStateProcessor=TournamentMatchJoinedEventUnderwayStateCurrentState=Joined, EventUnderway, ConnectedDescription=Tournament:12839688 Round:3Match Id:286416330Match Token:459eabbd-84b0-4549-a499-d53499350926PlayFormatCd=CMODERNGameStructureCd= ModernJoinedToGame=TrueAmIHost=FalsePlayerIds=964394,2888604

20:01:18 [INF] (Game Management|Processing Registered Handler for GsMessageMessage in TournamentMatchJoinedEventUnderwayState) Processor: TournamentMatchJoinedEventUnderwayState Message: {"MatchToken":"4491d0b9-ae24-40aa-ba18-0c8dc0a78442","MatchID":286417182,"GameID":948107588,"MetaMessage":[108,2]} Receiver: Event Token=4491d0b9-ae24-40aa-ba18-0c8dc0a78442Event Id:286417182CurrentStateProcessor=TournamentMatchJoinedEventUnderwayStateCurrentState=Joined, EventUnderway, ConnectedDescription=Tournament:12839688 Round:4Match Id:286417182Match Token:4491d0b9-ae24-40aa-ba18-0c8dc0a78442PlayFormatCd=CMODERNGameStructureCd= ModernJoinedToGame=TrueAmIHost=FalsePlayerIds=964394,2978442
```

The `MetaMessage` byte arrays are truncated here to keep the fixture readable — the real bytes aren't needed for the tests.

- [ ] **Step 2: Commit**

```bash
git add tests/Fixtures/log_samples/tournament_match_joined.txt
git commit -m "test: real tournament match join log fixtures"
```

---

## Task 5: Classifier — tournament_sync

Tournament sync events carry the tournament token inside a JSON payload (`{"Token":"..."}`). The existing classifier doesn't touch them — they currently fall through as unclassified noise.

**Files:**
- Modify: `app/Actions/Logs/ClassifyLogEvent.php`
- Test: `tests/Feature/Actions/Logs/ClassifyLogEventTournamentTest.php`

- [ ] **Step 1: Create the test file with the sync case**

```php
<?php

use App\Actions\Logs\ClassifyLogEvent;
use App\Enums\LogEventType;
use App\Models\LogEvent;

function makeLogEvent(string $rawText, string $category = 'Tournament', string $context = ''): LogEvent
{
    return (new LogEvent)->fill([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => strlen($rawText),
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => $category,
        'context' => $context,
        'raw_text' => $rawText,
        'ingested_at' => now(),
        'logged_at' => now(),
    ]);
}

it('classifies EventSyncData_t blocks as tournament_sync', function () {
    $raw = '12:34:56 [INF] (Tournament|Sync) EventSyncData_t in TournamentUninitializedState {"Token":"4b92a89a-a319-4725-aa5a-35bff1357ec9","Foo":1}';

    $event = ClassifyLogEvent::run(makeLogEvent($raw));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_SYNC->value);
    expect($event->tournament_token)->toBe('4b92a89a-a319-4725-aa5a-35bff1357ec9');
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test --compact --filter=ClassifyLogEventTournamentTest
```

Expected: FAIL — event_type is null (no classification branch yet).

- [ ] **Step 3: Add the sync branch to the classifier**

In `app/Actions/Logs/ClassifyLogEvent.php`, add this branch **before** the `return $event;` at the end (keep all existing branches intact):

```php
// Tournament sync — carries Token inside JSON block.
if (str_contains($text, 'EventSyncData_t')) {
    $json = ExtractJson::run($text)->first();

    if (is_array($json) && ! empty($json['Token'])) {
        return $event->fill([
            'event_type' => LogEventType::TOURNAMENT_SYNC->value,
            'tournament_token' => $json['Token'],
        ]);
    }
}
```

Also add at the top of the file:
```php
use App\Enums\LogEventType;
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php artisan test --compact --filter=ClassifyLogEventTournamentTest
```

Expected: PASS.

- [ ] **Step 5: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Logs/ClassifyLogEvent.php tests/Feature/Actions/Logs/ClassifyLogEventTournamentTest.php
git commit -m "feat: classify tournament_sync log events"
```

---

## Task 6: Classifier — tournament_state_changed

Tournament state change lines contain "Tournament State Changed from X to Y" and carry the tournament token either in surrounding context or (more reliably) in an adjacent `EventToken=` field.

**Files:**
- Modify: `app/Actions/Logs/ClassifyLogEvent.php`
- Modify: `tests/Feature/Actions/Logs/ClassifyLogEventTournamentTest.php`

- [ ] **Step 1: Add the failing test**

Append to the test file:

```php
it('classifies Tournament State Changed lines as tournament_state_changed', function () {
    $raw = '15:43:18 [INF] (Tournament|Transition) Token=4b92a89a-a319-4725-aa5a-35bff1357ec9 Tournament State Changed from TournamentUninitializedState to TournamentNotJoinedRoundInProgressState';

    $event = ClassifyLogEvent::run(makeLogEvent($raw));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_STATE_CHANGED->value);
    expect($event->tournament_token)->toBe('4b92a89a-a319-4725-aa5a-35bff1357ec9');
});

it('leaves tournament_token null when the state change line has no token', function () {
    $raw = '15:43:18 [INF] (Tournament|Transition) Tournament State Changed from X to Y';

    $event = ClassifyLogEvent::run(makeLogEvent($raw));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_STATE_CHANGED->value);
    expect($event->tournament_token)->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify failure**

```bash
php artisan test --compact --filter=ClassifyLogEventTournamentTest
```

Expected: two new cases fail.

- [ ] **Step 3: Add the state_changed branch to the classifier**

In `app/Actions/Logs/ClassifyLogEvent.php`, add this branch before the final `return $event;`:

```php
// Tournament state change — "Tournament State Changed from X to Y"
if (str_contains($text, 'Tournament State Changed from')) {
    $token = null;
    if (preg_match('/Token=([a-f0-9\-]{32,36})/i', $text, $m)) {
        $token = $m[1];
    }

    return $event->fill([
        'event_type' => LogEventType::TOURNAMENT_STATE_CHANGED->value,
        'tournament_token' => $token,
    ]);
}
```

- [ ] **Step 4: Run tests to verify pass**

```bash
php artisan test --compact --filter=ClassifyLogEventTournamentTest
```

Expected: PASS (all cases).

- [ ] **Step 5: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Logs/ClassifyLogEvent.php tests/Feature/Actions/Logs/ClassifyLogEventTournamentTest.php
git commit -m "feat: classify tournament_state_changed log events"
```

---

## Task 7: Classifier — JSON-payload tournament events (round_result / round_info / player_eliminated / ended)

Four event types share the same shape: a marker substring (`FlsTournamentXxxMessage`) followed by a JSON payload that carries `Token` (tournament UUID).

**Files:**
- Modify: `app/Actions/Logs/ClassifyLogEvent.php`
- Modify: `tests/Feature/Actions/Logs/ClassifyLogEventTournamentTest.php`

- [ ] **Step 1: Add failing tests**

Append to the test file:

```php
it('classifies FlsTournamentRoundResultMessage as tournament_round_result', function () {
    $raw = '19:35:39 [INF] (Tournament|Round) FlsTournamentRoundResultMessage {"Token":"4b92a89a-a319-4725-aa5a-35bff1357ec9","Round":3,"OpponentResults":[]}';

    $event = ClassifyLogEvent::run(makeLogEvent($raw));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_ROUND_RESULT->value);
    expect($event->tournament_token)->toBe('4b92a89a-a319-4725-aa5a-35bff1357ec9');
});

it('classifies FlsTournamentRoundInfoMessage as tournament_round_info', function () {
    $raw = '19:31:20 [INF] (Tournament|Round) FlsTournamentRoundInfoMessage {"Token":"4b92a89a-a319-4725-aa5a-35bff1357ec9","Round":{"Number":3,"Matches":[]}}';

    $event = ClassifyLogEvent::run(makeLogEvent($raw));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_ROUND_INFO->value);
    expect($event->tournament_token)->toBe('4b92a89a-a319-4725-aa5a-35bff1357ec9');
});

it('classifies FlsTournamentPlayerIsEliminatedMessage as tournament_player_eliminated', function () {
    $raw = '19:43:50 [INF] (Tournament|Player) FlsTournamentPlayerIsEliminatedMessage {"Token":"4b92a89a-a319-4725-aa5a-35bff1357ec9","LoginID":964394}';

    $event = ClassifyLogEvent::run(makeLogEvent($raw));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_PLAYER_ELIMINATED->value);
    expect($event->tournament_token)->toBe('4b92a89a-a319-4725-aa5a-35bff1357ec9');
});

it('classifies tournament end messages', function () {
    $raw = '22:00:00 [INF] (Tournament|End) FlsTournamentEndedMessage {"Token":"4b92a89a-a319-4725-aa5a-35bff1357ec9"}';

    $event = ClassifyLogEvent::run(makeLogEvent($raw));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_ENDED->value);
    expect($event->tournament_token)->toBe('4b92a89a-a319-4725-aa5a-35bff1357ec9');
});
```

- [ ] **Step 2: Run tests to confirm failures**

```bash
php artisan test --compact --filter=ClassifyLogEventTournamentTest
```

Expected: four new cases fail.

- [ ] **Step 3: Add the classifier branches**

In `app/Actions/Logs/ClassifyLogEvent.php`, add these four branches before the final `return $event;`:

```php
// JSON-payload tournament events.
$jsonMarkers = [
    'FlsTournamentRoundInfoMessage' => LogEventType::TOURNAMENT_ROUND_INFO,
    'FlsTournamentRoundResultMessage' => LogEventType::TOURNAMENT_ROUND_RESULT,
    'FlsTournamentPlayerIsEliminatedMessage' => LogEventType::TOURNAMENT_PLAYER_ELIMINATED,
    'FlsTournamentEndedMessage' => LogEventType::TOURNAMENT_ENDED,
];

foreach ($jsonMarkers as $marker => $type) {
    if (str_contains($text, $marker)) {
        $json = ExtractJson::run($text)->first();

        return $event->fill([
            'event_type' => $type->value,
            'tournament_token' => is_array($json) ? ($json['Token'] ?? null) : null,
        ]);
    }
}
```

- [ ] **Step 4: Run tests to confirm pass**

```bash
php artisan test --compact --filter=ClassifyLogEventTournamentTest
```

Expected: PASS.

- [ ] **Step 5: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Logs/ClassifyLogEvent.php tests/Feature/Actions/Logs/ClassifyLogEventTournamentTest.php
git commit -m "feat: classify JSON-payload tournament events"
```

---

## Task 8: Classifier — tournament_match_state_changed

Per-match state transitions during tournament play carry only a match token. Real signature unknown at plan-writing time — the findings describe them as high-volume "TournamentMatch State Changed" lines with a match UUID nearby. Use a conservative regex; if it matches nothing in real logs the classifier simply doesn't produce these events, which is acceptable (other classifications cover the major cases).

**Files:**
- Modify: `app/Actions/Logs/ClassifyLogEvent.php`
- Modify: `tests/Feature/Actions/Logs/ClassifyLogEventTournamentTest.php`

- [ ] **Step 1: Add failing test**

Append:

```php
it('classifies TournamentMatch State Changed lines with a match token', function () {
    $raw = '18:12:05 [INF] (Tournament|MatchTransition) TournamentMatch State Changed for 459eabbd-84b0-4549-a499-d53499350926 from MatchInProgressState to MatchCompleteState';

    $event = ClassifyLogEvent::run(makeLogEvent($raw));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_MATCH_STATE_CHANGED->value);
    expect($event->match_token)->toBe('459eabbd-84b0-4549-a499-d53499350926');
    expect($event->tournament_token)->toBeNull();
});
```

- [ ] **Step 2: Run the test to confirm failure**

```bash
php artisan test --compact --filter=ClassifyLogEventTournamentTest
```

Expected: FAIL.

- [ ] **Step 3: Add the branch**

In `app/Actions/Logs/ClassifyLogEvent.php`, add this branch before the final `return $event;`. It must come **after** the existing `match_state_changed` branch (line 16) because we want the tournament-prefixed variant to take precedence:

```php
// Tournament match state change — per-match transition, match_token only.
// Must match BEFORE the plain "Match State Changed" branch is exhausted;
// we place this in the classifier sequence after the json branches so the
// existing match_state_changed rule (which fires on "Match State Changed for ...")
// can still handle non-tournament matches.
if (preg_match('/TournamentMatch State Changed for (?<token>[a-f0-9\-]{32,36})/i', $text, $m)) {
    return $event->fill([
        'event_type' => LogEventType::TOURNAMENT_MATCH_STATE_CHANGED->value,
        'match_token' => $m['token'],
    ]);
}
```

**Important:** the existing `match_state_changed` branch at the top of `ClassifyLogEvent` uses the regex `/Match State Changed for (?<token>[a-f0-9\-]+)/i`, which would also match `TournamentMatch State Changed for ...` because the word `Match` is a substring of `TournamentMatch`. Move the new tournament branch to **before** the existing match branch so the tournament-prefixed variant wins. Final order: (1) tournament_match_state_changed, (2) match_state_changed, (3) game_state_update, … unchanged.

- [ ] **Step 4: Run tests to confirm pass**

```bash
php artisan test --compact --filter=ClassifyLogEventTournamentTest
```

Expected: PASS. Also verify the existing `match_state_changed` path still works by running the broader classifier test suite (if one exists) or at least:

```bash
php artisan test --compact --filter=ClassifyLogEvent
```

Expected: all prior classifier tests still pass.

- [ ] **Step 5: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Logs/ClassifyLogEvent.php tests/Feature/Actions/Logs/ClassifyLogEventTournamentTest.php
git commit -m "feat: classify tournament_match_state_changed log events"
```

---

## Task 9: Stamp tournament fields on participated matches

**Files:**
- Modify: `app/Actions/Matches/AdvanceMatchState.php`
- Create: `tests/Feature/Actions/Matches/AdvanceMatchStateTournamentTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Actions/Matches/AdvanceMatchStateTournamentTest.php`:

```php
<?php

use App\Actions\Matches\AdvanceMatchState;
use App\Enums\LogEventType;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stamps tournament_event_id and tournament_round on join when Description carries Tournament:N Round:M', function () {
    $rawJoin = file_get_contents(base_path('tests/Fixtures/log_samples/tournament_match_joined.txt'));
    // Use just the first block
    [$firstBlock] = explode("\n\n", trim($rawJoin));

    $matchToken = '2212f089-c748-42c8-ab3f-61c463a4278f';
    $matchId = 286451927;

    // Simulate the raw log being ingested as a game_management_json event.
    LogEvent::create([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => strlen($firstBlock),
        'timestamp' => '16:00:06',
        'level' => 'INF',
        'category' => 'Game Management',
        'context' => 'Processing Registered Handler for GsMessageMessage in TournamentMatchJoinedEventUnderwayState',
        'raw_text' => $firstBlock,
        'ingested_at' => now(),
        'logged_at' => now(),
        'event_type' => 'game_management_json',
        'match_token' => $matchToken,
        'match_id' => $matchId,
        'username' => 'testuser',
    ]);

    $match = AdvanceMatchState::run($matchToken, $matchId);

    expect($match)->not->toBeNull();
    expect($match->tournament_event_id)->toBe(12839714);
    expect($match->tournament_round)->toBe(1);
    expect($match->token)->toBe($matchToken);
    expect((int) $match->mtgo_id)->toBe($matchId);
});

it('leaves tournament fields null for non-tournament matches', function () {
    $matchToken = 'aaaa0000-0000-0000-0000-000000000000';
    $matchId = 999999999;

    $rawJoin = '12:00:00 [INF] (Game Management|Processing Registered Handler for GsMessageMessage in MatchJoinedEventUnderwayState) Processor: MatchJoinedEventUnderwayState Message: {"MatchToken":"'.$matchToken.'","MatchID":'.$matchId.',"GameID":1} Receiver: Event Token='.$matchToken.'Event Id:'.$matchId.'CurrentStateProcessor=MatchJoinedEventUnderwayStateCurrentState=Joined, EventUnderway, ConnectedDescription=LeagueMatch Id:'.$matchId.'Match Token:'.$matchToken.'PlayFormatCd=CMODERNGameStructureCd= ModernJoinedToGame=TrueAmIHost=FalsePlayerIds=964394,123';

    LogEvent::create([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => strlen($rawJoin),
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Game Management',
        'context' => 'Processing Registered Handler for GsMessageMessage in MatchJoinedEventUnderwayState',
        'raw_text' => $rawJoin,
        'ingested_at' => now(),
        'logged_at' => now(),
        'event_type' => 'game_management_json',
        'match_token' => $matchToken,
        'match_id' => $matchId,
        'username' => 'testuser',
    ]);

    $match = AdvanceMatchState::run($matchToken, $matchId);

    expect($match)->not->toBeNull();
    expect($match->tournament_event_id)->toBeNull();
    expect($match->tournament_round)->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test --compact --filter=AdvanceMatchStateTournamentTest
```

Expected: FAIL — `tournament_event_id` is null (no stamping logic yet).

- [ ] **Step 3: Update AdvanceMatchState to stamp tournament fields**

In `app/Actions/Matches/AdvanceMatchState.php`, find the `MtgoMatch::create([...])` call (around line 99–107). Extract the tournament fields from `$gameMeta` and include them in the create payload:

```php
$gameMeta = ExtractKeyValueBlock::run($joinedState->raw_text);

$started = ConvertMtgoTimestamp::run($joinedState->logged_at, $joinedState->timestamp);

$tournamentEventId = null;
$tournamentRound = null;
if (preg_match('/Tournament:(\d+)\s+Round:(\d+)/', $gameMeta['Description'] ?? '', $descMatch)) {
    $tournamentEventId = (int) $descMatch[1];
    $tournamentRound = (int) $descMatch[2];
}

$match = MtgoMatch::create([
    'mtgo_id' => $matchId,
    'token' => $matchToken,
    'format' => $gameMeta['PlayFormatCd'] ?? 'Unknown',
    'match_type' => $gameMeta['GameStructureCd'] ?? 'Unknown',
    'started_at' => $started,
    'ended_at' => null,
    'state' => MatchState::Started,
    'tournament_event_id' => $tournamentEventId,
    'tournament_round' => $tournamentRound,
]);
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact --filter=AdvanceMatchStateTournamentTest
```

Expected: PASS (both cases).

- [ ] **Step 5: Run the broader match test suite to confirm no regressions**

```bash
php artisan test --compact --filter=AdvanceMatchState
```

Expected: all pass.

- [ ] **Step 6: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add app/Actions/Matches/AdvanceMatchState.php tests/Feature/Actions/Matches/AdvanceMatchStateTournamentTest.php
git commit -m "feat: stamp tournament fields on participated matches"
```

---

## Task 10: Skip phantom-league assignment for tournament matches

**Files:**
- Modify: `app/Actions/Matches/AssignLeague.php`
- Create: `tests/Feature/Actions/Matches/AssignLeagueTournamentTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Actions/Matches/AssignLeagueTournamentTest.php`:

```php
<?php

use App\Actions\Matches\AssignLeague;
use App\Enums\MatchState;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not assign a league when the match has a tournament Description', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::Started]);

    $gameMeta = [
        'Description' => 'Tournament:12839688 Round:3',
        'PlayFormatCd' => 'CMODERN',
        'GameStructureCd' => 'Modern',
    ];

    AssignLeague::run($match, $gameMeta);

    expect($match->fresh()->league_id)->toBeNull();
    expect(League::count())->toBe(0);
});

it('still creates a phantom league for non-tournament matches', function () {
    $match = MtgoMatch::factory()->create(['state' => MatchState::Started, 'format' => 'CMODERN']);

    \Native\Desktop\Facades\Settings::set('hide_phantom_leagues', false);

    $gameMeta = [
        'Description' => 'LeagueMatch',
        'PlayFormatCd' => 'CMODERN',
        'GameStructureCd' => 'Modern',
    ];

    AssignLeague::run($match, $gameMeta);

    expect($match->fresh()->league_id)->not->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test --compact --filter=AssignLeagueTournamentTest
```

Expected: the first case fails (a phantom league IS being created).

- [ ] **Step 3: Add the exclusion to AssignLeague**

In `app/Actions/Matches/AssignLeague.php`, at the very top of `public static function run(...)` (before the existing `if (! empty($gameMeta['League Token']))` branch):

```php
// Tournament matches are handled separately — no league assignment.
// tournament_event_id / tournament_round were stamped by AdvanceMatchState.
if (preg_match('/Tournament:\d+\s+Round:\d+/', $gameMeta['Description'] ?? '')) {
    return;
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact --filter=AssignLeagueTournamentTest
```

Expected: PASS.

- [ ] **Step 5: Run broader league suite**

```bash
php artisan test --compact --filter=AssignLeague
```

Expected: all pass.

- [ ] **Step 6: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add app/Actions/Matches/AssignLeague.php tests/Feature/Actions/Matches/AssignLeagueTournamentTest.php
git commit -m "feat: skip phantom-league assignment for tournament matches"
```

---

## Task 11: Exclude tournament event types from ProcessMatchEvents

`ProcessMatchEvents` builds a `match_token → match_id` mapping from unprocessed events. Tournament events (particularly `tournament_match_state_changed`, which carries a match_token) would pollute that map.

**Files:**
- Modify: `app/Actions/Pipeline/ProcessMatchEvents.php`

- [ ] **Step 1: Update the query exclusions**

In `app/Actions/Pipeline/ProcessMatchEvents.php`, find the `$tokenToMatchId` query (around line 32) and the state-change resolver below it (around line 40). Update both to exclude tournament event types:

```php
$tokenToMatchId = LogEvent::whereNotNull('match_id')
    ->whereNotNull('match_token')
    ->whereNull('processed_at')
    ->whereNotIn('event_type', [
        'league_joined',
        'league_join_request',
        ...LogEventType::tournamentValues(),
    ])
    ->distinct()
    ->pluck('match_id', 'match_token');

LogEvent::whereNotNull('match_token')
    ->whereNull('match_id')
    ->whereNull('processed_at')
    ->whereNotIn('match_token', $tokenToMatchId->keys())
    ->whereNotIn('event_type', LogEventType::tournamentValues())
    ->distinct()
    ->pluck('match_token')
    ->each(function (string $token) use ($tokenToMatchId) {
        $matchId = LogEvent::where('match_token', $token)->whereNotNull('match_id')->value('match_id');

        if ($matchId) {
            $tokenToMatchId[$token] = $matchId;
        }
    });
```

- [ ] **Step 2: Confirm existing match tests still pass**

```bash
php artisan test --compact --filter=ProcessMatchEvents
```

If no `ProcessMatchEvents` test exists, instead run:

```bash
php artisan test --compact
```

Expected: all tests pass (no regression).

- [ ] **Step 3: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Commit**

```bash
git add app/Actions/Pipeline/ProcessMatchEvents.php
git commit -m "feat: exclude tournament event types from match processor"
```

---

## Task 12: Extract tournament payloads for observations

Each event type needs a structured payload the API can consume. JSON-carrying events use the extracted JSON directly; state-changes synthesise a small structured payload from the raw text.

**Files:**
- Create: `app/Actions/Tournaments/ExtractTournamentPayload.php`
- Create: `tests/Feature/Actions/Tournaments/ExtractTournamentPayloadTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\Tournaments\ExtractTournamentPayload;
use App\Enums\LogEventType;
use App\Models\LogEvent;

function makeTournamentLogEvent(string $eventType, string $rawText): LogEvent
{
    return (new LogEvent)->fill([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => strlen($rawText),
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Tournament',
        'context' => '',
        'raw_text' => $rawText,
        'ingested_at' => now(),
        'logged_at' => now(),
        'event_type' => $eventType,
    ]);
}

it('extracts JSON payload for round_result events', function () {
    $event = makeTournamentLogEvent(
        LogEventType::TOURNAMENT_ROUND_RESULT->value,
        '19:35:39 [INF] (Tournament|Round) FlsTournamentRoundResultMessage {"Token":"abc","Round":3,"OpponentResults":[{"LoginID":1,"Win":2}]}'
    );

    $payload = ExtractTournamentPayload::run($event);

    expect($payload)->toMatchArray([
        'Token' => 'abc',
        'Round' => 3,
    ]);
    expect($payload['OpponentResults'])->toBeArray();
});

it('extracts from/to for tournament_state_changed', function () {
    $event = makeTournamentLogEvent(
        LogEventType::TOURNAMENT_STATE_CHANGED->value,
        '15:43:18 [INF] (Tournament|Transition) Token=abc Tournament State Changed from TournamentUninitializedState to TournamentNotJoinedRoundInProgressState'
    );

    $payload = ExtractTournamentPayload::run($event);

    expect($payload)->toMatchArray([
        'from' => 'TournamentUninitializedState',
        'to' => 'TournamentNotJoinedRoundInProgressState',
    ]);
});

it('extracts from/to for tournament_match_state_changed', function () {
    $event = makeTournamentLogEvent(
        LogEventType::TOURNAMENT_MATCH_STATE_CHANGED->value,
        '18:12:05 [INF] (Tournament|MatchTransition) TournamentMatch State Changed for 459eabbd-84b0-4549-a499-d53499350926 from MatchInProgressState to MatchCompleteState'
    );

    $payload = ExtractTournamentPayload::run($event);

    expect($payload)->toMatchArray([
        'match_token' => '459eabbd-84b0-4549-a499-d53499350926',
        'from' => 'MatchInProgressState',
        'to' => 'MatchCompleteState',
    ]);
});

it('returns empty array when extraction fails', function () {
    $event = makeTournamentLogEvent(
        LogEventType::TOURNAMENT_SYNC->value,
        'something without JSON'
    );

    expect(ExtractTournamentPayload::run($event))->toBe([]);
});
```

- [ ] **Step 2: Run the test to confirm failure**

```bash
php artisan test --compact --filter=ExtractTournamentPayloadTest
```

Expected: FAIL — class doesn't exist.

- [ ] **Step 3: Create the action**

`app/Actions/Tournaments/ExtractTournamentPayload.php`:

```php
<?php

namespace App\Actions\Tournaments;

use App\Actions\Util\ExtractJson;
use App\Enums\LogEventType;
use App\Models\LogEvent;

class ExtractTournamentPayload
{
    /**
     * Return a structured payload for an already-classified tournament log event.
     * For JSON-carrying events, returns the extracted JSON object. For state
     * changes, returns a minimal synthesised payload. Returns [] if extraction fails.
     *
     * @return array<string, mixed>
     */
    public static function run(LogEvent $event): array
    {
        return match ($event->event_type) {
            LogEventType::TOURNAMENT_SYNC->value,
            LogEventType::TOURNAMENT_ROUND_RESULT->value,
            LogEventType::TOURNAMENT_ROUND_INFO->value,
            LogEventType::TOURNAMENT_PLAYER_ELIMINATED->value,
            LogEventType::TOURNAMENT_ENDED->value => self::fromJson($event),

            LogEventType::TOURNAMENT_STATE_CHANGED->value => self::fromTournamentStateChange($event),

            LogEventType::TOURNAMENT_MATCH_STATE_CHANGED->value => self::fromMatchStateChange($event),

            default => [],
        };
    }

    /** @return array<string, mixed> */
    private static function fromJson(LogEvent $event): array
    {
        $json = ExtractJson::run($event->raw_text)->first();

        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    private static function fromTournamentStateChange(LogEvent $event): array
    {
        if (preg_match('/Tournament State Changed from (\S+) to (\S+)/', $event->raw_text, $m)) {
            return [
                'from' => $m[1],
                'to' => $m[2],
            ];
        }

        return [];
    }

    /** @return array<string, mixed> */
    private static function fromMatchStateChange(LogEvent $event): array
    {
        if (preg_match('/TournamentMatch State Changed for (?<token>[a-f0-9\-]{32,36}) from (?<from>\S+) to (?<to>\S+)/i', $event->raw_text, $m)) {
            return [
                'match_token' => $m['token'],
                'from' => $m['from'],
                'to' => $m['to'],
            ];
        }

        return [];
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact --filter=ExtractTournamentPayloadTest
```

Expected: PASS.

- [ ] **Step 5: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Tournaments/ExtractTournamentPayload.php tests/Feature/Actions/Tournaments/ExtractTournamentPayloadTest.php
git commit -m "feat: ExtractTournamentPayload for observation payloads"
```

---

## Task 13: EnqueueTournamentObservations action

Reads tournament log events that aren't yet in the queue and enqueues them. Self-healing: re-runs pick up any stragglers.

**Files:**
- Create: `app/Actions/Tournaments/EnqueueTournamentObservations.php`
- Create: `tests/Feature/Actions/Tournaments/EnqueueTournamentObservationsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\Tournaments\EnqueueTournamentObservations;
use App\Enums\LogEventType;
use App\Models\LogEvent;
use App\Models\TournamentObservationQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeClassifiedLogEvent(string $eventType, ?string $tournamentToken, ?string $matchToken, string $rawText): LogEvent
{
    return LogEvent::create([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => strlen($rawText),
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Tournament',
        'context' => '',
        'raw_text' => $rawText,
        'ingested_at' => now(),
        'logged_at' => now(),
        'event_type' => $eventType,
        'tournament_token' => $tournamentToken,
        'match_token' => $matchToken,
    ]);
}

it('enqueues an observation for a newly classified tournament event', function () {
    $event = makeClassifiedLogEvent(
        LogEventType::TOURNAMENT_SYNC->value,
        'tok-1',
        null,
        '12:00:00 [INF] (Tournament|Sync) EventSyncData_t {"Token":"tok-1"}'
    );

    EnqueueTournamentObservations::run();

    expect(TournamentObservationQueue::count())->toBe(1);
    $row = TournamentObservationQueue::first();
    expect($row->log_event_id)->toBe($event->id);
    expect($row->tournament_token)->toBe('tok-1');
    expect($row->event_type)->toBe(LogEventType::TOURNAMENT_SYNC->value);
    expect($row->status)->toBe('pending');
    expect($row->payload)->toMatchArray(['Token' => 'tok-1']);
});

it('skips log events that are already enqueued', function () {
    $event = makeClassifiedLogEvent(
        LogEventType::TOURNAMENT_SYNC->value,
        'tok-1',
        null,
        '12:00:00 [INF] (Tournament|Sync) EventSyncData_t {"Token":"tok-1"}'
    );

    EnqueueTournamentObservations::run();
    EnqueueTournamentObservations::run();

    expect(TournamentObservationQueue::count())->toBe(1);
});

it('ignores non-tournament log events', function () {
    LogEvent::create([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => 10,
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Game Management',
        'context' => '',
        'raw_text' => 'something',
        'ingested_at' => now(),
        'logged_at' => now(),
        'event_type' => 'match_state_changed',
        'match_token' => 'abc',
    ]);

    EnqueueTournamentObservations::run();

    expect(TournamentObservationQueue::count())->toBe(0);
});
```

- [ ] **Step 2: Run the test to confirm failure**

```bash
php artisan test --compact --filter=EnqueueTournamentObservationsTest
```

Expected: FAIL — class doesn't exist.

- [ ] **Step 3: Create the action**

`app/Actions/Tournaments/EnqueueTournamentObservations.php`:

```php
<?php

namespace App\Actions\Tournaments;

use App\Enums\LogEventType;
use App\Models\LogEvent;
use App\Models\TournamentObservationQueue;

class EnqueueTournamentObservations
{
    /**
     * Create queue rows for tournament log events that haven't been
     * enqueued yet. Safe to call repeatedly; unique FK on log_event_id
     * guarantees idempotency.
     */
    public static function run(int $limit = 500): int
    {
        $events = LogEvent::query()
            ->whereIn('event_type', LogEventType::tournamentValues())
            ->whereNotIn('id', fn ($q) => $q
                ->select('log_event_id')
                ->from('tournament_observation_queue')
            )
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $inserted = 0;
        foreach ($events as $event) {
            $payload = ExtractTournamentPayload::run($event);

            TournamentObservationQueue::query()->insertOrIgnore([
                'log_event_id' => $event->id,
                'tournament_token' => $event->tournament_token,
                'match_token' => $event->match_token,
                'event_type' => $event->event_type,
                'payload' => json_encode($payload),
                'client_observed_at' => $event->ingested_at,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $inserted++;
        }

        return $inserted;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact --filter=EnqueueTournamentObservationsTest
```

Expected: PASS.

- [ ] **Step 5: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Tournaments/EnqueueTournamentObservations.php tests/Feature/Actions/Tournaments/EnqueueTournamentObservationsTest.php
git commit -m "feat: EnqueueTournamentObservations pipeline step"
```

---

## Task 14: ShipTournamentObservations job

Drains the queue: claims up to 200 pending rows, serialises them to the wire format, gzip-compresses, POSTs to the API with auth headers, and updates status.

**Files:**
- Create: `app/Jobs/ShipTournamentObservations.php`
- Create: `tests/Feature/Jobs/ShipTournamentObservationsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Jobs\ShipTournamentObservations;
use App\Models\LogEvent;
use App\Models\TournamentObservationQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Facades\Settings;

uses(RefreshDatabase::class);

beforeEach(function () {
    Settings::set('device_id', 'test-device');
    // Force RegisterDevice::retrieveKey() → return 'test-key' via the encrypted settings layer.
    Settings::set('api_key', \Illuminate\Support\Facades\Crypt::encrypt('test-key'));
});

function enqueueObservation(string $eventType = 'tournament_sync'): TournamentObservationQueue
{
    $logEvent = LogEvent::create([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => 10,
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Tournament',
        'context' => '',
        'raw_text' => 'x',
        'ingested_at' => now(),
        'logged_at' => now(),
        'event_type' => $eventType,
        'tournament_token' => 'tok-1',
    ]);

    return TournamentObservationQueue::create([
        'log_event_id' => $logEvent->id,
        'tournament_token' => 'tok-1',
        'event_type' => $eventType,
        'payload' => ['Token' => 'tok-1'],
        'client_observed_at' => now(),
        'status' => 'pending',
    ]);
}

it('marks observations as sent on 204 response', function () {
    $obs = enqueueObservation();

    Http::fake([
        '*/api/tournament-observations' => Http::response('', 204),
    ]);

    (new ShipTournamentObservations)->handle();

    expect($obs->fresh()->status)->toBe('sent');
    expect($obs->fresh()->attempts)->toBe(1);
});

it('sends gzipped body with auth headers', function () {
    enqueueObservation();

    Http::fake([
        '*/api/tournament-observations' => Http::response('', 204),
    ]);

    (new ShipTournamentObservations)->handle();

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-Device-Id', 'test-device')
            && $request->hasHeader('X-Api-Key', 'test-key')
            && $request->hasHeader('Content-Encoding', 'gzip')
            && strlen($request->body()) > 0;
    });
});

it('flips failed observations back to pending with backoff', function () {
    $obs = enqueueObservation();

    Http::fake([
        '*/api/tournament-observations' => Http::response('server error', 500),
    ]);

    (new ShipTournamentObservations)->handle();

    $obs->refresh();
    expect($obs->status)->toBe('pending');
    expect($obs->attempts)->toBe(1);
    expect($obs->next_attempt_at)->not->toBeNull();
    expect($obs->last_error)->toContain('500');
});

it('marks observations failed after 20 attempts', function () {
    $obs = enqueueObservation();
    $obs->update(['attempts' => 19, 'next_attempt_at' => now()->subMinute()]);

    Http::fake([
        '*/api/tournament-observations' => Http::response('server error', 500),
    ]);

    (new ShipTournamentObservations)->handle();

    expect($obs->fresh()->status)->toBe('failed');
    expect($obs->fresh()->attempts)->toBe(20);
});

it('skips observations whose next_attempt_at is in the future', function () {
    $obs = enqueueObservation();
    $obs->update(['next_attempt_at' => now()->addMinutes(5)]);

    Http::fake([
        '*/api/tournament-observations' => Http::response('', 204),
    ]);

    (new ShipTournamentObservations)->handle();

    expect($obs->fresh()->status)->toBe('pending');
    expect($obs->fresh()->attempts)->toBe(0);
    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run the test to confirm failure**

```bash
php artisan test --compact --filter=ShipTournamentObservationsTest
```

Expected: FAIL — job class doesn't exist.

- [ ] **Step 3: Generate the job**

```bash
php artisan make:job ShipTournamentObservations --no-interaction
```

- [ ] **Step 4: Implement the job**

Replace the generated file contents with:

```php
<?php

namespace App\Jobs;

use App\Actions\RegisterDevice;
use App\Models\TournamentObservationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Facades\Settings;
use Throwable;

class ShipTournamentObservations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const BATCH_LIMIT = 200;

    public const MAX_ATTEMPTS = 20;

    public function handle(): void
    {
        $rows = $this->claim();

        if ($rows->isEmpty()) {
            return;
        }

        $response = $this->send($rows);

        if ($response === null) {
            $this->markFailure($rows, 'request threw exception');

            return;
        }

        if ($response >= 200 && $response < 300) {
            TournamentObservationQueue::whereIn('id', $rows->pluck('id'))
                ->update([
                    'status' => 'sent',
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error' => null,
                    'updated_at' => now(),
                ]);

            return;
        }

        if ($response === 401) {
            RegisterDevice::run();
        }

        $this->markFailure($rows, "HTTP {$response}");
    }

    /**
     * @return \Illuminate\Support\Collection<int, TournamentObservationQueue>
     */
    private function claim(): \Illuminate\Support\Collection
    {
        return DB::transaction(function () {
            $rows = TournamentObservationQueue::query()
                ->where('status', 'pending')
                ->where(function ($q) {
                    $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                })
                ->orderBy('id')
                ->limit(self::BATCH_LIMIT)
                ->lockForUpdate()
                ->get();

            if ($rows->isNotEmpty()) {
                TournamentObservationQueue::whereIn('id', $rows->pluck('id'))
                    ->update(['status' => 'sending', 'updated_at' => now()]);
            }

            return $rows;
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TournamentObservationQueue>  $rows
     */
    private function send(\Illuminate\Support\Collection $rows): ?int
    {
        $body = $rows->map(fn (TournamentObservationQueue $row) => [
            'tournament_token' => $row->tournament_token,
            'match_token' => $row->match_token,
            'event_type' => $row->event_type,
            'payload' => $row->payload,
            'client_observed_at' => $row->client_observed_at?->toIso8601String(),
        ])->values()->toArray();

        $gz = gzencode(json_encode($body));

        try {
            $response = Http::withHeaders([
                'X-Device-Id' => Settings::get('device_id'),
                'X-Api-Key' => RegisterDevice::retrieveKey(),
                'Content-Encoding' => 'gzip',
                'Content-Type' => 'application/json',
            ])
                ->withBody($gz, 'application/json')
                ->post(config('mymtgo_api.url').'/api/tournament-observations');

            return $response->status();
        } catch (Throwable $e) {
            Log::warning('ShipTournamentObservations: send failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TournamentObservationQueue>  $rows
     */
    private function markFailure(\Illuminate\Support\Collection $rows, string $error): void
    {
        foreach ($rows as $row) {
            $attempts = $row->attempts + 1;
            $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
            $backoffSeconds = min(300, 5 * (2 ** $attempts));

            $row->update([
                'status' => $status,
                'attempts' => $attempts,
                'next_attempt_at' => now()->addSeconds($backoffSeconds),
                'last_error' => substr($error, 0, 500),
                'updated_at' => now(),
            ]);
        }
    }
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --compact --filter=ShipTournamentObservationsTest
```

Expected: PASS (all five cases).

- [ ] **Step 6: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add app/Jobs/ShipTournamentObservations.php tests/Feature/Jobs/ShipTournamentObservationsTest.php
git commit -m "feat: ShipTournamentObservations job"
```

---

## Task 15: Wire enqueue + ship into the scheduled pipeline

**Files:**
- Modify: `app/Actions/Pipeline/RunPipeline.php`
- Modify: `app/Managers/MtgoManager.php`

- [ ] **Step 1: Add enqueue to RunPipeline**

In `app/Actions/Pipeline/RunPipeline.php`, add the call after `ResolveActiveMatches::run`:

```php
<?php

namespace App\Actions\Pipeline;

use App\Actions\Tournaments\EnqueueTournamentObservations;

class RunPipeline
{
    public static function run(): void
    {
        if (! app('mtgo')->pathsAreValid()) {
            return;
        }

        // Phase 0: Discover game logs
        DiscoverGameLogs::run();

        // Phase 1: Ingest main log
        app('mtgo')->ingestLogs();

        // Phase 2: Process matches
        $processedTokens = ProcessMatchEvents::run();
        ResolveActiveMatches::run($processedTokens);

        // Phase 3: Enqueue tournament observations for shipping.
        // The sender job runs on its own schedule (see MtgoManager::schedule).
        EnqueueTournamentObservations::run();
    }
}
```

- [ ] **Step 2: Add ship job to schedule**

In `app/Managers/MtgoManager.php`, add to `public function schedule(Schedule $schedule): void` after the existing `submit_matches` schedule (around line 225):

```php
$schedule->job(new \App\Jobs\ShipTournamentObservations)
    ->everyThirtySeconds()
    ->name('ship_tournament_observations')
    ->withoutOverlapping(60);
```

- [ ] **Step 3: Verify schedule registration**

```bash
php artisan schedule:list
```

Expected: includes a line like `ShipTournamentObservations  Every 30 seconds  ship_tournament_observations`.

- [ ] **Step 4: Run the full test suite to check for regressions**

```bash
php artisan test --compact
```

Expected: all pass.

- [ ] **Step 5: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Actions/Pipeline/RunPipeline.php app/Managers/MtgoManager.php
git commit -m "feat: schedule tournament observation enqueue + ship"
```

---

## Task 16: Full verification

- [ ] **Step 1: Run full test suite**

```bash
php artisan test --compact
```

Expected: all pass.

- [ ] **Step 2: Verify routes unchanged (no accidental additions)**

```bash
php artisan route:list
```

Expected: no new routes added. This sub-project adds no HTTP endpoints on the client.

- [ ] **Step 3: Verify scheduler**

```bash
php artisan schedule:list
```

Expected: `ship_tournament_observations` listed, every 30s.

- [ ] **Step 4: Verify migrations are green**

```bash
php artisan migrate:status | tail -20
```

Expected: all three new migrations show `Ran`.

- [ ] **Step 5: Verify Pint is clean**

```bash
vendor/bin/pint --test --format agent
```

Expected: `{"result":"pass"}`.

- [ ] **Step 6: Final commit (only if any files remain dirty)**

```bash
git status
# If anything is still uncommitted:
git add -A
git commit -m "chore: final cleanup for tournament observations client"
```

---

## Self-Review Notes

**Spec coverage — maps each spec section to a task:**

| Spec requirement | Task |
| --- | --- |
| Wire contract: observation shape | Task 14 (sender serialises exactly these fields) |
| Event types (7 classified) | Tasks 5–8 |
| Columns added to `matches` | Task 1 (migration) + Task 9 (stamping) |
| Stamping the match on join | Task 9 |
| Phantom-league exclusion | Task 10 |
| Queue table `tournament_observation_queue` | Task 1 (migration) + Task 3 (model) |
| Classifier additions | Tasks 5–8 |
| Enqueueing | Task 13 |
| Sender job with gzip + backoff | Task 14 |
| Reuse `X-Device-Id` / `X-Api-Key` auth | Task 14 |
| Batch size 200, 30s schedule | Tasks 14 + 15 |
| Pipeline wiring | Task 15 |
| Test coverage (classifier, stamping, exclusion, queue, sender) | Tasks 5–14 |

**Placeholder scan:** none found — every step contains actual code or commands.

**Type consistency:**
- `LogEventType::TOURNAMENT_*` cases defined in Task 2, used in Tasks 5–14.
- `LogEventType::tournamentValues()` defined in Task 2, used in Tasks 11 and 13.
- `TournamentObservationQueue` model defined in Task 3, used in Tasks 13 and 14.
- `ExtractTournamentPayload::run(LogEvent)` defined in Task 12, used in Task 13.
- `RegisterDevice::retrieveKey()` already exists on `main`; used by Task 14 sender to match the auth pattern from `SubmitMatchToApi`.

**Open issues (carried from spec):**
- `tournament_match_state_changed` log signature is best-guess. If the regex in Task 8 doesn't match real logs, that event type simply won't be emitted, and the feature still works for the other 6. Revisit when real samples surface.
- `RoundInfo.Matches[].EventID` semantics remain a server-side VERIFY at the start of sub-project 2.
