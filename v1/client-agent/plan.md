# Client Agent Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Format:** ported classes are copied + re-verified against real logs (not reprinted); new code gets complete TDD steps.

**Goal:** Turn the 0.x NativePHP app into a thin ingest agent that tails MTGO logs, compiles each match into a `{match}.json` file, and pushes it to the cloud sink — porting the ingestion core verbatim and deleting the local display/DB layer.

**Architecture:** Port the clean log→data core unchanged. Introduce the missing seam — a `ProjectedMatch` DTO — so match projection *produces the contract JSON* instead of writing Eloquent tables. Compiled matches land in a local `outbox` and are pushed with a Bearer token; a keep-forever gzipped raw archive backs recompilation.

**Tech Stack:** PHP 8.4, Laravel 12, NativePHP 2.0 (Electron), Pest v4, Spatie Laravel Data (DTOs), SQLite.

## Global Constraints

- **Single default connection — SUPERSEDED, see [`../RECONCILIATION.md`](../RECONCILIATION.md).** This repo *is* v1; 0.x is a branch off `main`. There is NO separate `mymtgo.sqlite` / `mymtgo` connection / `DB_MYMTGO_DATABASE` — the ingest schema lives on the app's existing default connection. (The gut + ingest-schema reset were already executed on the v1 branch; Task 1 below is done — read it as history.)
- **Re-port parsers from the `0.x` branch.** The match-building parser island (MetaMessage decode, game/match result, history parsers, deck signature, dek parse, timestamp/util) was removed from the v1 tree during the gut. Tasks 3–9 re-port it verbatim from the `0.x` branch / git history (not from an in-tree file). See [`../RECONCILIATION.md`](../RECONCILIATION.md).
- **`match_key` = MatchToken uuid** — the per-match identity. `mtgo_id` (MatchID int) is an attribute; `league_token` groups league runs, never a key.
- **Target output is the `{match}.json` contract** — see [`../contract/spec.md`](../contract/spec.md). The DTO serializes to exactly that shape.
- **Port ingestion verbatim, don't rewrite** — the log-parsing core carries years of MTGO edge cases; copy it, don't re-author it.
- **Match detection by game-message traffic**, never by state-change lines alone (`GsMessageMessage` carrying `MatchToken`+`MatchID`+`GameID`).
- **A match is valid once it has ≥1 game**; zero-game tokens are dropped, never pushed.
- **Push is never end-gated** — activity/debounce triggered, idempotent whole-file replace, last-write-wins.
- Use **invokable Actions** (single responsibility), not service classes. PHP 8 constructor promotion, explicit return types.
- Run `vendor/bin/pint --dirty --format agent` before finalizing any task.
- Tests: Pest v4, fold into the task whose deliverable needs them. Use the **real fixtures** in `tests/fixtures/` (`mtgo_league_join_drop.log`, `gamelogs/*.dat`).
- **Re-verify ports against real logs.** Ported parsing is copied unchanged, but every port task includes a step that runs it over real captured logs/fixtures and asserts the output — don't blind-trust 0.x.
- **Near-realtime ingestion** — event-driven file watch (Electron `fs.watch` → PHP tick) + ~500ms poll backstop + open read handle. Serves the live overlay; compile→push stays debounced. See [`spec.md`](./spec.md) §5.

---

## File Structure

**New (client agent):**
- `config/database.php` (modify) — add the `mymtgo` SQLite connection.
- `database/migrations/mymtgo/*` — ported ingest tables + new `outbox`, `archetype_catalog`, `app_account`, `raw_archive`.
- `app/Data/ProjectedMatch/*.php` — the DTO tree (Spatie Data) mirroring the `{match}.json` contract.
- `app/Actions/Compile/CompileMatch.php` — orchestrator: `match_key` → `ProjectedMatch` → `{match}.json`.
- `app/Actions/Compile/OutcomeResolvers/*` — ordered outcome-resolver strategies + `ResolveMatchOutcome` action.
- `app/Actions/Outbox/*` — enqueue, push, retry.
- `app/Actions/Archive/WriteRawArchive.php` — gzip raw segment per match.
- `app/Models/Outbox.php`, `app/Models/RawArchive.php`.

**Ported verbatim (copy + re-namespace, logic unchanged):**
- `app/Actions/Logs/{IngestLogInstance,ClassifyLogEvent,DetectLogRotation,RotationResult,SealLogInstance,FindMtgoLogPath,GetLogFilePaths,ConvertMtgoTimestamp}.php`
- `app/Actions/Matches/{DecodeMetaMessageText,ExtractMetaMessageEntries,ExtractGameResults,DetermineMatchResult}.php`
- `app/Actions/Archetypes/ParseDekFile.php`, `app/Actions/Decks/GenerateDeckSignature.php`
- `app/Models/{LogEvent,LogInstance,LogCursor}.php`, `app/Enums/LogEventType.php`

**Refactored (strip Eloquent writes → build DTO):**
- `app/Actions/Matches/{AdvanceMatchState,CreateOrUpdateGames,ResolveMatchFromMetaMessages,DetermineMatchDeck,AssignLeague,LinkMatchToTournament}.php`

**Deleted (display / local-DB layer):**
- `app/Http/Controllers/*` (except any overlay window controllers — see below), `app/Observers/MtgoMatchObserver.php`, `resources/js/pages/*` viewing pages, `app/Models/{MtgoMatch,Game,CardGameStat}.php` + their migrations.
- **KEEP:** `app/Actions/Cards/ComputeDrawOdds.php` + `app/Http/Controllers/Decks/PopoutController.php` (deck-odds overlay — see [`../client-ui/spec.md`](../client-ui/spec.md)). Overlay is decoupled and stays.

---

### Task 1: ported ingest schema — ✅ DONE (as history; details below are superseded)

> **Executed on the v1 branch.** Consolidated `log_instances`/`log_cursors`/`log_events`
> into one migration on the **default** connection (no `mymtgo` connection — see Global
> Constraints + [`../RECONCILIATION.md`](../RECONCILIATION.md)). The `mymtgo.sqlite` /
> `DB_MYMTGO_DATABASE` steps below do **not** apply.

**Files:**
- Modify: `config/database.php` (add `mymtgo` connection)
- Create: `database/migrations/mymtgo/0001_01_01_000001_create_log_tables.php` (port `log_instances`, `log_cursors`, `log_events` from 0.x)
- Modify: `.env.example` (add `DB_MYMTGO_DATABASE=mymtgo.sqlite`)
- Test: `tests/Feature/Database/MymtgoConnectionTest.php`

**Interfaces:**
- Produces: a `mymtgo` DB connection with the three ported ingest tables. Later tasks run all client migrations on this connection.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Database/MymtgoConnectionTest.php
use Illuminate\Support\Facades\Schema;

it('migrates the mymtgo connection with the ported ingest tables', function () {
    expect(Schema::connection('mymtgo')->hasTable('log_instances'))->toBeTrue();
    expect(Schema::connection('mymtgo')->hasTable('log_cursors'))->toBeTrue();
    expect(Schema::connection('mymtgo')->hasTable('log_events'))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=MymtgoConnectionTest`
Expected: FAIL — connection `mymtgo` not configured / tables missing.

- [ ] **Step 3: Add the connection**

In `config/database.php`, under `connections`:

```php
'mymtgo' => [
    'driver' => 'sqlite',
    'database' => env('DB_MYMTGO_DATABASE', database_path('mymtgo.sqlite')),
    'prefix' => '',
    'foreign_key_constraints' => true,
],
```

- [ ] **Step 4: Port the ingest-table migration**

Copy the 0.x `log_instances` / `log_cursors` / `log_events` column definitions verbatim into `database/migrations/mymtgo/0001_01_01_000001_create_log_tables.php`, each using `Schema::connection('mymtgo')`. (These schemas are unchanged from 0.x — do not redesign them.)

- [ ] **Step 5: Point the migration path + run it in tests**

Register the `mymtgo` migration path (service provider `loadMigrationsFrom(database_path('migrations/mymtgo'))`, or a dedicated `--path` in the test bootstrap). Ensure `tests/Pest.php` migrates the `mymtgo` connection for the affected suite.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=MymtgoConnectionTest`
Expected: PASS.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/database.php database/migrations/mymtgo tests/Feature/Database/MymtgoConnectionTest.php .env.example
git commit -m "feat(client): add mymtgo sqlite connection + ported ingest schema"
```

---

### Task 2: Port the log tailing + cursor + classification core

**Files:**
- Create (port verbatim, re-namespace to the `mymtgo` connection models): `app/Actions/Logs/{IngestLogInstance,ClassifyLogEvent,DetectLogRotation,RotationResult,SealLogInstance,FindMtgoLogPath,GetLogFilePaths,ConvertMtgoTimestamp}.php`, `app/Models/{LogInstance,LogCursor,LogEvent}.php`, `app/Enums/LogEventType.php`
- Test (port): `tests/Feature/Actions/Logs/IngestLogInstanceTest.php`, `tests/Feature/Actions/Logs/ClassifyLogEventTest.php`; new `tests/Feature/Actions/Logs/IngestFixtureLogTest.php`

**Interfaces:**
- Consumes: the `mymtgo` connection (Task 1).
- Produces: `IngestLogInstance::run(string $path)` → advances cursor, writes classified `LogEvent` rows. `LogEvent` carries `event_type`, `match_token`, `match_id`, `game_id`, `raw_text`, `timestamp`.

- [ ] **Step 1: Port the classes + models verbatim**

Copy each listed file from its 0.x location to the same path, changing only: (a) model `$connection = 'mymtgo'`, (b) namespace imports if paths moved. **Do not alter parsing logic.**

- [ ] **Step 2: Write the failing end-to-end fixture test**

```php
<?php // tests/Feature/Actions/Logs/IngestFixtureLogTest.php
use App\Actions\Logs\IngestLogInstance;
use App\Models\LogEvent;

it('ingests the real league fixture into classified events', function () {
    $path = base_path('tests/fixtures/mtgo_league_join_drop.log');

    app(IngestLogInstance::class)->run($path);

    $events = LogEvent::on('mymtgo')->get();
    expect($events)->not->toBeEmpty();
    // the league fixture contains a league join → classified as such
    expect($events->pluck('event_type'))->toContain('league_joined');
    // match token is extracted onto the events
    expect($events->whereNotNull('match_token'))->not->toBeEmpty();
});
```

- [ ] **Step 3: Run test to verify it fails, then passes after porting**

Run: `php artisan test --compact --filter=IngestFixtureLogTest`
Expected: PASS once ported (adjust the asserted `event_type` to the exact `LogEventType` value the fixture yields — inspect via a `dd($events->pluck('event_type','raw_text'))` if needed, then assert the real value).

- [ ] **Step 4: Port + run the existing ingest unit tests**

Copy `IngestLogInstanceTest.php` (with its `writeLog()` helper) and `ClassifyLogEventTest.php`, retargeting the `mymtgo` connection.
Run: `php artisan test --compact --filter="IngestLogInstance|ClassifyLogEvent"`
Expected: PASS (rotation, truncation, ctime-forward, stuck-tick cases all green).

- [ ] **Step 5: Re-verify against a fresh real log (don't blind-trust 0.x)**

Capture (or reuse) a real `mtgo.log` segment covering a full league season into `tests/fixtures/`. Assert the distinct classified `event_type`s + distinct `match_token`s match reality (e.g. N matches → N tokens, 1 league token). If the classifier misses or mislabels anything against the real sample, fix the ported classifier before proceeding.

Run: `php artisan test --compact --filter=IngestFixtureLogTest`
Expected: PASS with counts matching the real sample.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Logs app/Models/Log* app/Enums/LogEventType.php tests/Feature/Actions/Logs tests/fixtures
git commit -m "feat(client): port log tailing, cursor + event classification core"
```

---

### Task 2b: Near-realtime file watch (Electron → PHP tick)

**Files:**
- Create/modify: the Electron main-process script (NativePHP `resources/js/electron` or the app's main entry) — add an `fs.watch` on the active MTGO log directory.
- Create: `app/Actions/Logs/RunPipelineTick.php` (thin entry the watcher triggers) + a NativePHP event/route the Node side can invoke.
- Modify: pipeline scheduler — keep a **~500ms poll backstop** (replace the 150ms poll).
- Test: `tests/Feature/Actions/Logs/RunPipelineTickTest.php`

**Interfaces:**
- Produces: `RunPipelineTick::run(): void` — a single ingestion tick (open handle → read from cursor → classify). Callable from both the file-watch signal and the poll backstop. Consumed by Task 13.

- [ ] **Step 1: Write the failing test for a single tick reading only new bytes**

```php
<?php // tests/Feature/Actions/Logs/RunPipelineTickTest.php
use App\Actions\Logs\RunPipelineTick;
use App\Models\LogEvent;

it('reads only bytes appended since the last tick', function () {
    $path = tempLogWith(["16:00:00 [INF] (Leagues|HandleFlsLeagueMatchStarted) LeagueId=1, MatchToken=\"aaa\"\n"]);
    config()->set('mtgo.log_path', $path);

    app(RunPipelineTick::class)->run();
    $first = LogEvent::on('mymtgo')->count();

    appendToLog($path, "16:00:01 [INF] (Leagues|HandleFlsLeagueMatchStarted) LeagueId=1, MatchToken=\"bbb\"\n");
    app(RunPipelineTick::class)->run();

    expect(LogEvent::on('mymtgo')->count())->toBe($first + 1); // only the appended line
});
```

Add `tempLogWith()` / `appendToLog()` helpers to `tests/Support/`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=RunPipelineTickTest`
Expected: FAIL — `RunPipelineTick` not defined.

- [ ] **Step 3: Implement `RunPipelineTick`**

Wrap the ported `IngestLogInstance` in a thin action that resolves the active log path, holds/reuses the cursor offset, and reads incrementally. No file-watch logic here (that's the Electron layer) — this is the pure PHP tick.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=RunPipelineTickTest`
Expected: PASS.

- [ ] **Step 5: Wire the Electron watcher → PHP tick**

In the Electron main process, `fs.watch` the MTGO log directory; on a change event for the active log, invoke `RunPipelineTick` (via the NativePHP event/child-process bridge). Add a **~500ms poll backstop** that also calls `RunPipelineTick` (covers coalesced/missed `fs.watch` events). This is manual/observed verification on Windows — note in the commit that the watcher path is exercised in-app, not by the PHP test suite.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Logs/RunPipelineTick.php resources/js tests/Feature/Actions/Logs/RunPipelineTickTest.php tests/Support
git commit -m "feat(client): event-driven log watch + poll backstop for near-realtime ingest"
```

---

### Task 3: Port MetaMessage decoding + game-result extraction

**Files:**
- Create (port verbatim): `app/Actions/Matches/{DecodeMetaMessageText,ExtractMetaMessageEntries,ExtractGameResults,DetermineMatchResult}.php`
- Test (port + fixtures): `tests/Unit/Actions/Matches/DecodeMetaMessageTextTest.php`, `tests/Unit/Actions/Matches/ExtractGameResultsTest.php`, `tests/Unit/Actions/Matches/DetermineMatchResultTest.php`

**Interfaces:**
- Consumes: `LogEvent` rows (Task 2), the `gamelogs/*.dat` fixtures.
- Produces: `ExtractGameResults::run(array $entries)` → `{ games[], players[], match_score, match_decided, starting_hands }`; `DetermineMatchResult::run(...)` → `{ wins, losses, decided }`. Pure, no DB.

- [ ] **Step 1: Port the four pure actions verbatim** (no DB access — logic unchanged).

- [ ] **Step 2: Write the failing fixture-driven test**

```php
<?php // tests/Unit/Actions/Matches/ExtractGameResultsTest.php
use App\Actions\Matches\ExtractGameResults;

function decodedEntriesFromFixture(string $name): array {
    // .dat holds the raw MetaMessage byte stream for a game; decode via the
    // same path production uses (ExtractMetaMessageEntries + DecodeMetaMessageText).
    return require_fixture_entries($name); // helper added in Step 3
}

it('extracts a clean 2-0 win from the fixture', function () {
    $result = app(ExtractGameResults::class)->run(decodedEntriesFromFixture('clean_2_0_win'));

    expect($result['match_score'])->toBe(['wins' => 2, 'losses' => 0]);
    expect($result['games'])->toHaveCount(2);
    expect($result['match_decided'])->toBeTrue();
});

it('extracts a concession', function () {
    $result = app(ExtractGameResults::class)->run(decodedEntriesFromFixture('instant_concede'));
    expect($result['match_decided'])->toBeTrue();
});
```

- [ ] **Step 3: Add the fixture-decode helper**

In `tests/Pest.php` (or a `tests/Support/` helper), add `require_fixture_entries($name)` that reads `tests/fixtures/gamelogs/{$name}.dat` and runs it through `ExtractMetaMessageEntries` + `DecodeMetaMessageText` exactly as production does.

- [ ] **Step 4: Run the ported + new tests**

Run: `php artisan test --compact --filter="DecodeMetaMessageText|ExtractGameResults|DetermineMatchResult"`
Expected: PASS across `clean_2_0_win`, `clean_2_1_loss`, `clean_2_1_win`, `concede_2_0`, `disconnect_game1`, `instant_concede`, `large_2_0_win`, `multi_game_partial_on_play`.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Matches/{DecodeMetaMessageText,ExtractMetaMessageEntries,ExtractGameResults,DetermineMatchResult}.php tests/Unit/Actions/Matches
git commit -m "feat(client): port MetaMessage decode + game-result extraction"
```

---

### Task 4: The `ProjectedMatch` DTO (contract shape)

**Files:**
- Create: `app/Data/ProjectedMatch/ProjectedMatchData.php`, `MatchData.php`, `GameData.php`, `CardStatData.php`, `DeckData.php`, `OpponentData.php`, `LeagueData.php`, `TournamentData.php`, `TimelineEntryData.php`
- Test: `tests/Unit/Data/ProjectedMatchDataTest.php`

**Interfaces:**
- Produces: `ProjectedMatchData` (Spatie `Data`) whose `->toArray()` / JSON matches [`../contract/spec.md`](../contract/spec.md) exactly, incl. envelope (`schema_version`, `client_version`, `source` (= `'mtgo'` — the Arena seam, see `../RECONCILIATION.md`), `match_key`, `file_version`, `imported`, `mtgo_username`, `mtgo_player_id`) + `match{}`. Consumed by Task 6 (projection builds it) and Task 9 (compiler stamps `source`/envelope + serializes it).

- [ ] **Step 1: Write the failing test asserting contract shape**

```php
<?php // tests/Unit/Data/ProjectedMatchDataTest.php
use App\Data\ProjectedMatch\ProjectedMatchData;

it('serializes to the {match}.json envelope + match shape', function () {
    $dto = ProjectedMatchData::from([
        'schema_version' => 1,
        'client_version' => '1.0.0',
        'match_key' => '95f4d09f-7d8f-4e14-aafd-1abed0415ea8',
        'compiled_at' => '2026-07-01T00:00:00Z',
        'file_version' => 1,
        'imported' => false,
        'mtgo_username' => 'Pro_MTG',
        'mtgo_player_id' => 147160,
        'match' => [
            'token' => '95f4d09f-7d8f-4e14-aafd-1abed0415ea8',
            'mtgo_id' => 285753048,
            'format' => 'CModern',
            'match_type' => 'League',
            'outcome' => 'Win',
            'outcome_source' => 'resolved',
            'state' => 'Complete',
            'started_at' => '2026-07-01T00:00:00Z',
            'ended_at' => '2026-07-01T00:10:00Z',
            'opponent' => ['mtgo_player_id' => 3022021, 'username' => 'anticloser'],
            'games' => [],
        ],
    ]);

    $json = $dto->toArray();
    expect($json['match_key'])->toBe($json['match']['token']);   // key == token
    expect($json['match']['outcome_source'])->toBe('resolved');
    expect($json['match']['opponent']['mtgo_player_id'])->toBe(3022021);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ProjectedMatchDataTest`
Expected: FAIL — DTO classes not defined.

- [ ] **Step 3: Implement the DTO tree**

Create the `Data` classes with typed, promoted properties matching the contract (nullable where the contract shows `|null`; nested `GameData`/`CardStatData`/`TimelineEntryData` collections via `#[DataCollectionOf]`). Enums for `outcome` (`Win|Loss|Draw|Unknown`) and `outcome_source` (`resolved|manual|unknown`).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ProjectedMatchDataTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/ProjectedMatch tests/Unit/Data/ProjectedMatchDataTest.php
git commit -m "feat(client): add ProjectedMatch DTO matching {match}.json contract"
```

---

### Task 5: Refactor projection → build the `ProjectedMatch` DTO (no Eloquent writes)

**Files:**
- Create: `app/Actions/Compile/ProjectMatch.php`
- Refactor (extract pure logic from, strip Eloquent writes): `app/Actions/Matches/{AdvanceMatchState,CreateOrUpdateGames,ResolveMatchFromMetaMessages,DetermineMatchDeck,AssignLeague,LinkMatchToTournament}.php`
- Test: `tests/Feature/Compile/ProjectMatchTest.php`

**Interfaces:**
- Consumes: `LogEvent` rows (Task 2), `ExtractGameResults`/`DetermineMatchResult` (Task 3), `GenerateDeckSignature` (Task 4 sibling port), `ProjectedMatchData` (Task 4).
- Produces: `ProjectMatch::run(string $matchKey): ProjectedMatchData` — pure projection from `LogEvent` rows, **zero table writes**. Consumed by Task 9.

- [ ] **Step 1: Write the failing test (fixture events → DTO, no writes)**

```php
<?php // tests/Feature/Compile/ProjectMatchTest.php
use App\Actions\Compile\ProjectMatch;
use App\Data\ProjectedMatch\ProjectedMatchData;

it('projects a league match from ingested events into the DTO without writing match tables', function () {
    // ingest a real league fixture so LogEvents exist for the token
    seedEventsFromFixture('mtgo_league_join_drop.log');
    $token = firstMatchTokenFromFixture('mtgo_league_join_drop.log');

    $dto = app(ProjectMatch::class)->run($token);

    expect($dto)->toBeInstanceOf(ProjectedMatchData::class);
    expect($dto->match->token)->toBe($token);
    expect($dto->match->format)->not->toBeNull();
    // projection is pure — no display tables exist / are written
    expect(Schema::connection('mymtgo')->hasTable('matches'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ProjectMatchTest`
Expected: FAIL — `ProjectMatch` not defined.

- [ ] **Step 3: Extract the pure state machine**

Move the state-transition + metadata-extraction logic out of `AdvanceMatchState` into methods that **return values** instead of calling `->save()`. `ProjectMatch` orchestrates them: gather the token's `LogEvent`s → derive match metadata (format, type, league/tournament, opponent `mtgo_player_id`+username, deck signature) → build `GameData[]` via `CreateOrUpdateGames`' extracted grouping → assemble `ProjectedMatchData`. Delete the `->save()` / event-dispatch lines (those belonged to the display layer).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ProjectMatchTest`
Expected: PASS.

- [ ] **Step 5: Port the existing match-pipeline tests as projection assertions**

Adapt `MatchStatePipelineTest` cases to assert on the returned DTO (state, games count, deck signature) rather than DB rows.
Run: `php artisan test --compact --filter=ProjectMatch`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Compile/ProjectMatch.php app/Actions/Matches tests/Feature/Compile/ProjectMatchTest.php
git commit -m "feat(client): project matches into ProjectedMatch DTO (no table writes)"
```

---

### Task 6: Match-detection filter + ≥1-game validity

**Files:**
- Create: `app/Actions/Compile/IsOurMatch.php`
- Test: `tests/Unit/Actions/Compile/IsOurMatchTest.php`

**Interfaces:**
- Consumes: `LogEvent` rows (Task 2).
- Produces: `IsOurMatch::run(string $matchKey): bool` — true only when the token has real `GsMessageMessage` game traffic **and** ≥1 game. Gates Task 9.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Unit/Actions/Compile/IsOurMatchTest.php
use App\Actions\Compile\IsOurMatch;
use App\Models\LogEvent;

it('rejects a token with only state-change lines (observed/challenge, no game traffic)', function () {
    LogEvent::on('mymtgo')->create([
        'event_type' => 'match_state_changed', 'match_token' => 'observed-tok', 'raw_text' => 'Match State Changed for observed-tok ...',
    ]);
    expect(app(IsOurMatch::class)->run('observed-tok'))->toBeFalse();
});

it('accepts a token with GsMessage game traffic', function () {
    LogEvent::on('mymtgo')->create([
        'event_type' => 'game_management_json', 'match_token' => 'played-tok', 'game_id' => '954965154',
        'raw_text' => '... GsMessageMessage ... {"MatchToken":"played-tok","MatchID":1,"GameID":954965154, ...}',
    ]);
    expect(app(IsOurMatch::class)->run('played-tok'))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=IsOurMatchTest`
Expected: FAIL — `IsOurMatch` not defined.

- [ ] **Step 3: Implement**

```php
<?php // app/Actions/Compile/IsOurMatch.php
namespace App\Actions\Compile;

use App\Models\LogEvent;

final class IsOurMatch
{
    public function run(string $matchKey): bool
    {
        return LogEvent::on('mymtgo')
            ->where('match_token', $matchKey)
            ->where('event_type', 'game_management_json')
            ->whereNotNull('game_id')
            ->exists();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=IsOurMatchTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Compile/IsOurMatch.php tests/Unit/Actions/Compile/IsOurMatchTest.php
git commit -m "feat(client): match-detection filter (game-traffic + >=1 game)"
```

---

### Task 7: Outcome-resolver pipeline

**Files:**
- Create: `app/Actions/Compile/OutcomeResolvers/OutcomeResolver.php` (contract), `ExplicitResultResolver.php`, `GameTallyResolver.php`, `ConcessionResolver.php`, `DisconnectResolver.php`; `app/Actions/Compile/ResolveMatchOutcome.php`
- Test: `tests/Unit/Actions/Compile/ResolveMatchOutcomeTest.php`

**Interfaces:**
- Consumes: `ProjectedMatchData` (Task 4/5), `DetermineMatchResult` (Task 3).
- Produces: `ResolveMatchOutcome::run(ProjectedMatchData $m): array{outcome: string, outcome_source: string}` — ordered strategies; first confident wins; `{outcome:'Unknown', outcome_source:'unknown'}` if none. Feeds Task 9.

- [ ] **Step 1: Write the failing test (fixtures + unresolved case)**

```php
<?php // tests/Unit/Actions/Compile/ResolveMatchOutcomeTest.php
use App\Actions\Compile\ResolveMatchOutcome;

it('resolves a clean 2-0 win via the game tally', function () {
    $dto = projectedMatchFromFixture('clean_2_0_win'); // helper: build DTO from a gamelog fixture
    $res = app(ResolveMatchOutcome::class)->run($dto);
    expect($res)->toBe(['outcome' => 'Win', 'outcome_source' => 'resolved']);
});

it('returns Unknown when no strategy is confident', function () {
    $dto = projectedMatchWithNoResultSignals(); // helper: games present, no decisive signal
    $res = app(ResolveMatchOutcome::class)->run($dto);
    expect($res)->toBe(['outcome' => 'Unknown', 'outcome_source' => 'unknown']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ResolveMatchOutcomeTest`
Expected: FAIL — classes not defined.

- [ ] **Step 3: Implement the contract + strategies + orchestrator**

```php
<?php // app/Actions/Compile/OutcomeResolvers/OutcomeResolver.php
namespace App\Actions\Compile\OutcomeResolvers;

use App\Data\ProjectedMatch\ProjectedMatchData;

interface OutcomeResolver
{
    /** @return array{outcome: string}|null null = not confident, defer to next */
    public function attempt(ProjectedMatchData $match): ?array;
}
```

```php
<?php // app/Actions/Compile/ResolveMatchOutcome.php
namespace App\Actions\Compile;

use App\Actions\Compile\OutcomeResolvers\{ExplicitResultResolver, GameTallyResolver, ConcessionResolver, DisconnectResolver};
use App\Data\ProjectedMatch\ProjectedMatchData;

final class ResolveMatchOutcome
{
    /** Order matters: most authoritative first. */
    private const RESOLVERS = [
        ExplicitResultResolver::class,  // MTGO "wins the match X-Y" line
        GameTallyResolver::class,       // count game wins/losses
        ConcessionResolver::class,      // ConcedeReqState → NotJoined
        DisconnectResolver::class,      // opponent drop / trailing forfeit
    ];

    /** @return array{outcome: string, outcome_source: string} */
    public function run(ProjectedMatchData $match): array
    {
        foreach (self::RESOLVERS as $class) {
            if ($hit = app($class)->attempt($match)) {
                return ['outcome' => $hit['outcome'], 'outcome_source' => 'resolved'];
            }
        }

        return ['outcome' => 'Unknown', 'outcome_source' => 'unknown'];
    }
}
```

Implement each resolver's `attempt()` by delegating to the ported pure logic (`DetermineMatchResult` for the tally; concession/disconnect strategies read the decoded timeline).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ResolveMatchOutcomeTest`
Expected: PASS across the win/loss/concede/disconnect fixtures + the Unknown case.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Compile/OutcomeResolvers app/Actions/Compile/ResolveMatchOutcome.php tests/Unit/Actions/Compile/ResolveMatchOutcomeTest.php
git commit -m "feat(client): ordered outcome-resolver pipeline (Unknown when unresolved)"
```

---

### Task 8: `app_account` binding + username-mismatch guard

**Files:**
- Create: `database/migrations/mymtgo/..._create_app_account_table.php`, `app/Models/AppAccount.php`, `app/Actions/Auth/ResolveLocalIdentity.php`, `app/Data/LocalIdentity.php`
- Test: `tests/Feature/Auth/ResolveLocalIdentityTest.php`

**Interfaces:**
- Consumes: the bound `AppAccount` (written by [`../client-auth/spec.md`](../client-auth/spec.md) flow), the log's `PlayerIds`/username read.
- Produces: `ResolveLocalIdentity::run(): ?LocalIdentity` — `{mtgo_player_id, mtgo_username}` when the logged-in username matches the binding; **null** when unresolved or mismatched (caller holds the push, logs nothing). Consumed by Tasks 9 + 12.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Auth/ResolveLocalIdentityTest.php
use App\Actions\Auth\ResolveLocalIdentity;
use App\Models\AppAccount;

it('returns null when the log username differs from the bound account (mismatch guard)', function () {
    AppAccount::on('mymtgo')->create(['user_id' => 1, 'mtgo_player_id' => 147160, 'mtgo_username' => 'Pro_MTG', 'active' => true]);
    fakeLogUsername('SomeoneElse'); // helper stubs the username read

    expect(app(ResolveLocalIdentity::class)->run())->toBeNull();
});

it('resolves identity when the log username matches the binding', function () {
    AppAccount::on('mymtgo')->create(['user_id' => 1, 'mtgo_player_id' => 147160, 'mtgo_username' => 'Pro_MTG', 'active' => true]);
    fakeLogUsername('Pro_MTG');

    $id = app(ResolveLocalIdentity::class)->run();
    expect($id->mtgoPlayerId)->toBe(147160);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ResolveLocalIdentityTest`
Expected: FAIL — classes/migration missing.

- [ ] **Step 3: Implement migration, model, DTO, and the guard**

`app_account` columns: `user_id`, `mtgo_player_id`, `mtgo_username`, OAuth token refs (see [`../client-auth/spec.md`](../client-auth/spec.md)), `active`. `ResolveLocalIdentity` reads the bound account + the flaky username read: if the username is empty/garbage → null; if it differs from the binding → null (mismatch guard); else return `LocalIdentity`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ResolveLocalIdentityTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/mymtgo app/Models/AppAccount.php app/Actions/Auth/ResolveLocalIdentity.php app/Data/LocalIdentity.php tests/Feature/Auth/ResolveLocalIdentityTest.php
git commit -m "feat(client): local identity resolver + username-mismatch guard"
```

---

### Task 9: `CompileMatch` orchestrator

**Files:**
- Create: `app/Actions/Compile/CompileMatch.php`
- Test: `tests/Feature/Compile/CompileMatchTest.php`

**Interfaces:**
- Consumes: `IsOurMatch` (6), `ProjectMatch` (5), `ResolveMatchOutcome` (7), `ResolveLocalIdentity` (8).
- Produces: `CompileMatch::run(string $matchKey): ?ProjectedMatchData` — gate → project → resolve outcome → stamp envelope (`schema_version`, `client_version`, `compiled_at`, `mtgo_username`, `mtgo_player_id`). Null if not ours or identity unresolved. Consumed by Tasks 10, 11, 13.

- [ ] **Step 1: Write the failing test (fixture → full contract JSON)**

```php
<?php // tests/Feature/Compile/CompileMatchTest.php
use App\Actions\Compile\CompileMatch;

it('compiles a fixture match to the full {match}.json contract', function () {
    seedEventsFromFixture('mtgo_league_join_drop.log');
    fakeLogUsername('Pro_MTG');
    bindAccount(mtgoPlayerId: 147160, username: 'Pro_MTG');
    $token = firstMatchTokenFromFixture('mtgo_league_join_drop.log');

    $dto = app(CompileMatch::class)->run($token);
    $json = $dto->toArray();

    expect($json['schema_version'])->toBe(1);
    expect($json['match_key'])->toBe($json['match']['token']);
    expect($json['mtgo_player_id'])->toBe(147160);
    expect($json['match']['outcome_source'])->toBeIn(['resolved', 'unknown']);
});

it('returns null for a token that is not ours', function () {
    expect(app(CompileMatch::class)->run('never-seen'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=CompileMatchTest`
Expected: FAIL — `CompileMatch` not defined.

- [ ] **Step 3: Implement the orchestrator**

```php
<?php // app/Actions/Compile/CompileMatch.php
namespace App\Actions\Compile;

use App\Actions\Auth\ResolveLocalIdentity;
use App\Data\ProjectedMatch\ProjectedMatchData;

final class CompileMatch
{
    public function __construct(
        private IsOurMatch $isOurMatch,
        private ProjectMatch $project,
        private ResolveMatchOutcome $resolveOutcome,
        private ResolveLocalIdentity $identity,
    ) {}

    public function run(string $matchKey): ?ProjectedMatchData
    {
        if (! $this->isOurMatch->run($matchKey)) {
            return null;
        }

        $identity = $this->identity->run();
        if ($identity === null) {
            return null; // unresolved / mismatched → hold, log nothing
        }

        $dto = $this->project->run($matchKey);
        $outcome = $this->resolveOutcome->run($dto);

        return $dto->withEnvelope($identity, $outcome); // stamps schema_version, client_version, compiled_at, identity, outcome
    }
}
```

Add `ProjectedMatchData::withEnvelope()` (returns a copy with envelope + outcome fields set).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=CompileMatchTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Compile/CompileMatch.php app/Data/ProjectedMatch tests/Feature/Compile/CompileMatchTest.php
git commit -m "feat(client): CompileMatch orchestrator (gate -> project -> resolve -> envelope)"
```

---

### Task 10: Raw archive writer (keep-forever)

**Files:**
- Create: `database/migrations/mymtgo/..._create_raw_archive_table.php`, `app/Models/RawArchive.php`, `app/Actions/Archive/WriteRawArchive.php`
- Test: `tests/Feature/Archive/WriteRawArchiveTest.php`

**Interfaces:**
- Produces: `WriteRawArchive::run(string $matchKey, string $rawSegment): void` — gzip the segment to disk (outside SQLite) + write a `raw_archive` index row (`match_key`, `path`, `captured_at`, `byte_len`). Called in the same pass as compile (Task 13). Kept forever (no prune).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Archive/WriteRawArchiveTest.php
use App\Actions\Archive\WriteRawArchive;
use App\Models\RawArchive;
use Illuminate\Support\Facades\Storage;

it('gzips the raw segment to disk and indexes it', function () {
    Storage::fake('archive');
    app(WriteRawArchive::class)->run('tok-1', "16:00:00 [INF] line one\n16:00:01 [INF] line two\n");

    $row = RawArchive::on('mymtgo')->where('match_key', 'tok-1')->firstOrFail();
    Storage::disk('archive')->assertExists($row->path);
    expect(gzdecode(Storage::disk('archive')->get($row->path)))->toContain('line two');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=WriteRawArchiveTest`
Expected: FAIL — class/migration missing.

- [ ] **Step 3: Implement migration, model, and gzip writer** (`gzencode` the segment, store on the `archive` disk under `{matchKey}/{captured_at}.log.gz`, upsert the index row).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=WriteRawArchiveTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/mymtgo app/Models/RawArchive.php app/Actions/Archive/WriteRawArchive.php tests/Feature/Archive/WriteRawArchiveTest.php
git commit -m "feat(client): keep-forever gzipped raw archive + index"
```

---

### Task 11: Outbox — table, model, enqueue

**Files:**
- Create: `database/migrations/mymtgo/..._create_outbox_table.php`, `app/Models/Outbox.php`, `app/Actions/Outbox/EnqueueMatch.php`
- Test: `tests/Feature/Outbox/EnqueueMatchTest.php`

**Interfaces:**
- Consumes: `ProjectedMatchData` (Task 9 output).
- Produces: `EnqueueMatch::run(ProjectedMatchData $m): Outbox` — upsert on `match_key`; bump monotonic `file_version` on change; status `pending`; store the JSON payload (or path). Consumed by Task 12.

- [ ] **Step 1: Write the failing test (upsert + monotonic version)**

```php
<?php // tests/Feature/Outbox/EnqueueMatchTest.php
use App\Actions\Outbox\EnqueueMatch;
use App\Models\Outbox;

it('upserts by match_key and bumps file_version on re-enqueue', function () {
    $a = app(EnqueueMatch::class)->run(compiledFixture('mtgo_league_join_drop.log'));
    expect($a->file_version)->toBe(1)->and($a->status)->toBe('pending');

    $b = app(EnqueueMatch::class)->run(compiledFixture('mtgo_league_join_drop.log')); // recompiled, changed
    expect(Outbox::on('mymtgo')->where('match_key', $a->match_key)->count())->toBe(1); // still one row
    expect($b->file_version)->toBe(2);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=EnqueueMatchTest`
Expected: FAIL.

- [ ] **Step 3: Implement** — `outbox` columns per [`spec.md`](./spec.md) §4 (`match_key`, `payload`, `file_version`, `status`, `attempts`, `last_attempt_at`, `last_error`, `synced_version`); `UNIQUE(match_key)`. `EnqueueMatch` upserts and increments `file_version` only when the payload differs.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=EnqueueMatchTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/mymtgo app/Models/Outbox.php app/Actions/Outbox/EnqueueMatch.php tests/Feature/Outbox/EnqueueMatchTest.php
git commit -m "feat(client): outbox table + idempotent enqueue with monotonic version"
```

---

### Task 12: Push client

**Files:**
- Create: `app/Actions/Outbox/PushMatch.php`, `app/Jobs/PushOutboxJob.php`
- Test: `tests/Feature/Outbox/PushMatchTest.php`

**Interfaces:**
- Consumes: an `Outbox` row (11), the Bearer token from `AppAccount` (8 / [`../client-auth/spec.md`](../client-auth/spec.md)).
- Produces: `PushMatch::run(Outbox $row): void` — `Authorization: Bearer` POST of the `{match}.json` to the sink; **200 →** `status=synced`, `synced_version=file_version`; **failure →** increment `attempts`, set `last_error`, backoff, `failed` after N; **holds** (no send) if identity unresolved. Cross-ref [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md) (sink endpoint).

- [ ] **Step 1: Write the failing test (`Http::fake`)**

```php
<?php // tests/Feature/Outbox/PushMatchTest.php
use App\Actions\Outbox\PushMatch;
use App\Models\Outbox;
use Illuminate\Support\Facades\Http;

it('marks synced on a 200 from the sink', function () {
    Http::fake([config('mtgo.sink_url').'*' => Http::response(status: 200)]);
    $row = pendingOutboxRow(fileVersion: 3);

    app(PushMatch::class)->run($row->fresh());

    expect($row->fresh()->status)->toBe('synced');
    expect($row->fresh()->synced_version)->toBe(3);
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization') && str_contains($r->url(), 'match'));
});

it('records the error and does not mark synced on a 500', function () {
    Http::fake([config('mtgo.sink_url').'*' => Http::response(status: 500)]);
    $row = pendingOutboxRow();

    app(PushMatch::class)->run($row->fresh());

    expect($row->fresh()->status)->not->toBe('synced');
    expect($row->fresh()->attempts)->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PushMatchTest`
Expected: FAIL — class not defined.

- [ ] **Step 3: Implement `PushMatch` + `PushOutboxJob`** — resolve identity/token; skip (hold) if unresolved; POST the payload with the Bearer header; update status/attempts/error per response. `PushOutboxJob` iterates pending rows with backoff.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=PushMatchTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Outbox/PushMatch.php app/Jobs/PushOutboxJob.php tests/Feature/Outbox/PushMatchTest.php
git commit -m "feat(client): push outbox to sink with Bearer auth, retry + hold-on-unresolved"
```

---

### Task 13: Push triggers (wire compile → enqueue → push)

**Files:**
- Create: `app/Actions/Compile/PushTriggers.php`
- Modify: the pipeline tick (`RunPipelineTick` from Task 2b) to invoke triggers
- Test: `tests/Feature/Compile/PushTriggersTest.php`

**Interfaces:**
- Consumes: `RunPipelineTick` (2b), `CompileMatch` (9), `WriteRawArchive` (10), `EnqueueMatch` (11), `PushOutboxJob` (12).
- Produces: on each tick, evaluate triggers — **debounced inactivity** (no events for match_token in N sec), **new match_token started**, **app-close flush**, **periodic flush** — and for each triggered token: `CompileMatch` → (if non-null) `WriteRawArchive` + `EnqueueMatch` → dispatch `PushOutboxJob`. **Never end-gated.**

- [ ] **Step 1: Write the failing test (debounce fires compile+enqueue)**

```php
<?php // tests/Feature/Compile/PushTriggersTest.php
use App\Actions\Compile\PushTriggers;
use App\Models\Outbox;

it('compiles and enqueues a match after the inactivity debounce', function () {
    seedEventsFromFixture('mtgo_league_join_drop.log');
    bindAccount(mtgoPlayerId: 147160, username: 'Pro_MTG');
    fakeLogUsername('Pro_MTG');
    travelBeyondDebounce(); // no new events for > N sec

    app(PushTriggers::class)->run();

    expect(Outbox::on('mymtgo')->where('status', 'pending')->count())->toBe(1);
});

it('does not enqueue a zero-game observed token', function () {
    seedObservedTokenEventsOnly(); // state-changes only, no game traffic
    app(PushTriggers::class)->run();
    expect(Outbox::on('mymtgo')->count())->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PushTriggersTest`
Expected: FAIL — `PushTriggers` not defined.

- [ ] **Step 3: Implement triggers** — track last-event-time per open match_token; on debounce/new-match/close/periodic, run `CompileMatch`; if it returns a DTO, `WriteRawArchive` + `EnqueueMatch` + dispatch `PushOutboxJob`. Null (not ours / unresolved / zero-game) → skip silently.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=PushTriggersTest`
Expected: PASS.

- [ ] **Step 5: Wire into the tick + commit**

Call `PushTriggers::run()` at the end of `RunPipelineTick` (Task 2b). App-close flush hooks the Electron `before-quit` → one final `PushTriggers::run()`.

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Compile/PushTriggers.php app/Actions/Logs/RunPipelineTick.php tests/Feature/Compile/PushTriggersTest.php
git commit -m "feat(client): activity-based push triggers (debounce/new-match/close/periodic)"
```

---

## Self-Review checklist (run after fleshing 5–13)

1. **Spec coverage** — every bullet in [`spec.md`](./spec.md) maps to a task (live overlay = client-ui; §2 compiler = Tasks 2–9,13; §3 raw archive = Task 10; §4 local schema = Tasks 1,8,10,11).
2. **Placeholder scan** — no "TBD"/"handle edge cases"/"similar to Task N".
3. **Type consistency** — `ProjectedMatchData` property + method names identical across Tasks 4/5/7/9/11/12.
