# Migration Import Implementation Plan (0.x → `{match}.json`)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Format:** each new class gets complete TDD steps (failing test → run-fail → implement → run-pass → Pint → commit). The import **reuses the client-agent push path verbatim** — `ProjectedMatchData` (client-agent Task 4), `EnqueueMatch`/`Outbox` (client-agent Task 11), `PushMatch`/`PushOutboxJob` (client-agent Task 12), `AppAccount`/`ResolveLocalIdentity` (client-agent Task 8). This plan does **not** rebuild any of them; it only adds the read-mapper and the import driver.

**Goal:** Ship an **opt-in button** that reads the untouched 0.x `nativephp.sqlite`, maps each old match row (+ its games/stats/deck/opponent/league/tournament) into the same `ProjectedMatchData` (contract [`../contract/spec.md`](../contract/spec.md)) with `imported: true`, sparse where 0.x lacks data, then pushes each through the **normal outbox → sink → worker** pipeline. Zero bespoke server-side code; idempotent `UNIQUE(user_id, match_key)` upsert makes re-import safe.

**Architecture:** A read-only `legacy` SQLite connection points at the old DB. `MapLegacyMatch` turns one old match row into a `ProjectedMatchData`. `ImportLegacyData` iterates old matches → map → `EnqueueMatch` (client-agent) → dispatch `PushOutboxJob` (client-agent). Progress is read from outbox synced-counts; a bad old match isolates in the outbox `failed` state without stalling the batch. Re-running the import just re-upserts.

**Tech Stack:** PHP 8.4, Laravel 12, NativePHP 2.0 (Electron), Pest v4, Spatie Laravel Data (DTOs), SQLite.

## Global Constraints

- **The 0.x DB `nativephp.sqlite` is READ-ONLY.** The `legacy` connection is opened `mode=ro`; nothing in this plan writes, migrates, or alters it. The v1 app runs on the separate `mymtgo` connection (client-agent Task 1).
- **`match_key` = the 0.x `matches.token` column** — verified against the live DB: `matches.token` holds the MatchToken UUID (e.g. `60a1a645-5b2e-42bd-8604-1258e66e2ed7`), and `matches.mtgo_id` holds the int MatchID. **No fallback needed** — the MatchToken is stored directly. (Documented in Task 2.)
- **Every mapped DTO has `imported: true`.** The worker builds a match-only / sparse record for these.
- **Sparse where 0.x lacks data** — 0.x has no `mtgo_player_id` for the local player (`accounts` has only `username`) nor for opponents (`opponents` has only `id`+`username`), and `game_timelines` stores per-turn JSON snapshots, not structured `{action, timestamp, player, context}` events. So: `mtgo_player_id` → null, `opponent.mtgo_player_id` → null (`username` carried), `timeline` → `[]`. Per-game `card_stats` and per-game fields DO exist in 0.x and ARE mapped.
- **Reuse, don't rebuild** the client-agent enqueue/push path. `MapLegacyMatch` produces a `ProjectedMatchData`; `ImportLegacyData` hands it to `EnqueueMatch::run()` and dispatches `PushOutboxJob` — exactly as the live compiler does.
- **Idempotent + safe re-run** — `EnqueueMatch` upserts on `match_key`; re-import re-upserts (bumps `file_version` only if the payload changed). Already-synced rows are skipped by the driver.
- **Opt-in only** — a button in the UI POSTs to a single-action controller that dispatches `ImportLegacyDataJob`. Never runs at boot.
- Use **invokable Actions** (single responsibility), not service classes. PHP 8 constructor promotion, explicit return types, curly braces on all control structures.
- Run `vendor/bin/pint --dirty --format agent` before finalizing any task.
- Tests: Pest v4. The mapper is tested by **constructing legacy rows in an in-memory `legacy` connection** and asserting the produced DTO / JSON — never by touching the real `nativephp.sqlite`.

---

## File Structure

**New (this plan):**
- `config/database.php` (modify) — add the read-only `legacy` SQLite connection.
- `config/mtgo.php` (modify) — add `legacy_database` path key.
- `.env.example` (modify) — add `DB_LEGACY_DATABASE=nativephp.sqlite`.
- `app/Models/Legacy/LegacyMatch.php`, `LegacyGame.php`, `LegacyCardGameStat.php`, `LegacyGameDeck.php`, `LegacyDeckVersion.php`, `LegacyDeck.php`, `LegacyOpponent.php`, `LegacyLeague.php`, `LegacyTournament.php` — Eloquent models bound to `$connection = 'legacy'`, read-only shims over the old tables.
- `app/Actions/Migration/MapLegacyMatch.php` — one `LegacyMatch` → `ProjectedMatchData` (`imported: true`, sparse).
- `app/Actions/Migration/ImportLegacyData.php` — iterate legacy matches → map → `EnqueueMatch` → dispatch push; skip already-synced; isolate per-match failures.
- `app/Actions/Migration/LegacyImportProgress.php` — read outbox synced/failed/total counts for the imported set.
- `app/Jobs/ImportLegacyDataJob.php` — queued driver wrapper (opt-in trigger runs it off the request).
- `app/Http/Controllers/Migration/ImportLegacyDataController.php` — single-action controller behind the button.
- `resources/js/pages/settings/partials/LegacyImportCard.vue` (or nearest existing settings partial) — the opt-in button + progress readout.
- `routes/web.php` (modify) — `migration.import` POST route.

**Reused verbatim (client-agent — do NOT reimplement):**
- `app/Data/ProjectedMatch/ProjectedMatchData.php` + tree (client-agent Task 4) — the DTO target.
- `app/Actions/Outbox/EnqueueMatch.php`, `app/Models/Outbox.php` (client-agent Task 11) — upsert on `match_key`, monotonic `file_version`.
- `app/Actions/Outbox/PushMatch.php`, `app/Jobs/PushOutboxJob.php` (client-agent Task 12) — Bearer POST to sink, retry, `failed` after N.
- `app/Models/AppAccount.php`, `app/Actions/Auth/ResolveLocalIdentity.php` (client-agent Task 8) — local identity (username; `mtgo_player_id` is null for 0.x-origin data).
- `app/Actions/Decks/GenerateDeckSignature.php` — `run(Collection $cards)` over `{mtgo_id, quantity, sideboard}` → base64 signature. `game_decks.deck_json` is exactly that shape, so per-game deck signatures reuse this.

---

### Task 1: Read-only `legacy` connection to `nativephp.sqlite`

**Files:**
- Modify: `config/database.php` (add `legacy` connection)
- Modify: `config/mtgo.php` (add `legacy_database`)
- Modify: `.env.example` (add `DB_LEGACY_DATABASE=nativephp.sqlite`)
- Test: `tests/Feature/Migration/LegacyConnectionTest.php`

**Interfaces:**
- Produces: a `legacy` DB connection, read-only against the old file. Later tasks read old tables via `->on('legacy')` / `$connection = 'legacy'`. **Never migrated, never written.**

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Migration/LegacyConnectionTest.php

use Illuminate\Support\Facades\DB;

it('exposes a legacy connection configured for the old nativephp db', function () {
    $config = config('database.connections.legacy');

    expect($config)->not->toBeNull();
    expect($config['driver'])->toBe('sqlite');
    // read-only: the DSN carries mode=ro so the old file is never mutated
    expect($config['database'])->toBe(config('mtgo.legacy_database'));
});

it('can open the legacy connection against an in-memory old-schema db', function () {
    // point the legacy connection at an in-memory db for the test, then build the old shape
    config()->set('database.connections.legacy.database', ':memory:');
    DB::purge('legacy');

    DB::connection('legacy')->getSchemaBuilder()->create('matches', function ($t) {
        $t->id();
        $t->string('token');
    });

    expect(DB::connection('legacy')->getSchemaBuilder()->hasTable('matches'))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=LegacyConnectionTest`
Expected: FAIL — connection `legacy` / `mtgo.legacy_database` not configured.

- [ ] **Step 3: Add the connection + config**

In `config/database.php`, under `connections`:

```php
'legacy' => [
    'driver' => 'sqlite',
    'url' => null,
    'database' => env('DB_LEGACY_DATABASE', config('mtgo.legacy_database')),
    'prefix' => '',
    // open read-only so the 0.x file is never mutated by the import
    'options' => [
        \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
    ],
    'foreign_key_constraints' => false,
],
```

In `config/mtgo.php`, add to the returned array:

```php
'legacy_database' => env('DB_LEGACY_DATABASE', database_path('nativephp.sqlite')),
```

In `.env.example`, add:

```
DB_LEGACY_DATABASE=nativephp.sqlite
```

> Note: `SQLITE_OPEN_READONLY` guarantees the mapper cannot write the old file even by mistake. In tests we override `database.connections.legacy.database` to `:memory:` and (because the readonly flag blocks table creation) also clear `options` — see Step 4.

- [ ] **Step 4: Add a test helper that gives an in-memory writable legacy connection**

Add to `tests/Pest.php` (or `tests/Support/LegacyFixtures.php`) a helper `bootLegacySchema()` that:
- sets `database.connections.legacy.database` to `:memory:`,
- sets `database.connections.legacy.options` to `[]` (drop the readonly flag so the *test* can seed rows),
- `DB::purge('legacy')`,
- creates the minimal old-schema tables the mapper reads (`matches`, `games`, `card_game_stats`, `game_decks`, `deck_versions`, `decks`, `opponents`, `leagues`, `tournaments`) with the exact columns documented in Task 2.

This keeps the real `nativephp.sqlite` untouched in the suite.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=LegacyConnectionTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/database.php config/mtgo.php .env.example tests/Feature/Migration/LegacyConnectionTest.php tests/Pest.php tests/Support
git commit -m "feat(migration): read-only legacy sqlite connection to 0.x nativephp.sqlite"
```

---

### Task 2: Legacy Eloquent read-models (0.x shape)

**Files:**
- Create: `app/Models/Legacy/LegacyMatch.php`, `LegacyGame.php`, `LegacyCardGameStat.php`, `LegacyGameDeck.php`, `LegacyDeckVersion.php`, `LegacyDeck.php`, `LegacyOpponent.php`, `LegacyLeague.php`, `LegacyTournament.php`
- Test: `tests/Feature/Migration/LegacyModelsTest.php`

**Interfaces:**
- Consumes: the `legacy` connection (Task 1).
- Produces: read-only Eloquent models over the old tables so `MapLegacyMatch` can traverse relationships (`$match->games`, `$game->cardGameStats`, `$game->decks`, `$match->opponent`, `$match->deckVersion`, `$match->league`, `$match->tournament`). Consumed by Task 3.

**Verified 0.x schema (live `nativephp.sqlite`, read-only inspection):**

- `matches`: `id`, **`token` (MatchToken uuid — the match_key)**, `mtgo_id` (MatchID int, string col), `deck_version_id`, `format` (e.g. `CMODERN`), `match_type` (e.g. `Modern`, `League`), `result`, `games_won`, `games_lost`, `started_at`, `ended_at` (nullable), `state` (`started|in_progress|ended|complete|abandoned`), `outcome` (`win|loss|draw|unknown`), `notes`, `imported`, `league_id`, `tournament_id`, `tournament_event_id`, `tournament_round`, `tournament_token`, `account_id`, `opponent_id`.
- `games`: `id`, `match_id`, `mtgo_id` (GameID string), `won` (nullable bool), `started_at`, `ended_at` (nullable), `turn_count`, `local_on_play`, `local_mulligans`, `opp_mulligans`, `local_dice`, `opp_dice`, `local_instance`, `opp_instance`. **(Denormalized — the old `players`/`game_player` tables were dropped; these columns carry per-game data directly.)**
- `card_game_stats`: `id`, `oracle_id`, `game_id`, `deck_version_id`, `quantity`, `kept`, `seen`, `won`, `is_postboard`, `sided_out`, `cast` (dropped in contract), `sided_in`, `played`, `kicked`, `flashback`, `madness`, `evoked`, `activated`, `pregame_revealed`, `pregame_played`, `opponent`.
- `game_decks`: `id`, `game_id`, `is_opponent`, `deck_json` (JSON array of `{mtgo_id, quantity, sideboard}` — feeds `GenerateDeckSignature`).
- `deck_versions`: `id`, `deck_id`, `signature` (base64 cardlist), `modified_at`.
- `decks`: `id`, `mtgo_id` (NetDeckId), `name`, `format`, `color_identity`.
- `opponents`: `id`, `username`. **No `mtgo_player_id`.**
- `leagues`: `id`, `token` (per-season league token), `name`, `format`, `joined_at`, `dropped_at`.
- `tournaments`: `id`, `mtgo_event_id`, `token`, `name`, `format`.

> **match_key confirmation (load-bearing):** `matches.token` **is** the MatchToken uuid — verified in the live DB. It is the correct `match_key`. `matches.mtgo_id` is the int MatchID (attribute only). No int-only fallback is required.

- [ ] **Step 1: Write the failing test (traverse the old shape)**

```php
<?php // tests/Feature/Migration/LegacyModelsTest.php

use App\Models\Legacy\LegacyMatch;

beforeEach(fn () => bootLegacySchema()); // Task 1 helper

it('reads a legacy match with its games, opponent, deck and league', function () {
    seedLegacyMatch([
        'token' => '60a1a645-5b2e-42bd-8604-1258e66e2ed7',
        'mtgo_id' => '286469301',
        'format' => 'CMODERN',
        'match_type' => 'League',
        'state' => 'complete',
        'outcome' => 'win',
        'opponent_username' => 'optivial',
        'league' => ['token' => 'season-tok', 'name' => 'Modern League', 'format' => 'CMODERN'],
        'games' => [
            ['mtgo_id' => '954965154', 'won' => true, 'turn_count' => 9, 'local_on_play' => true],
        ],
    ]); // helper builds rows across the legacy tables

    $match = LegacyMatch::on('legacy')->with(['games', 'opponent', 'league'])->firstOrFail();

    expect($match->token)->toBe('60a1a645-5b2e-42bd-8604-1258e66e2ed7');
    expect($match->games)->toHaveCount(1);
    expect($match->opponent->username)->toBe('optivial');
    expect($match->league->name)->toBe('Modern League');
});
```

Add `seedLegacyMatch(array $spec): void` to `tests/Support/LegacyFixtures.php` (inserts into the in-memory legacy tables).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=LegacyModelsTest`
Expected: FAIL — `LegacyMatch` not defined.

- [ ] **Step 3: Implement the legacy models**

Each model sets the connection and table, `$guarded = []`, and read-oriented casts. Example:

```php
<?php // app/Models/Legacy/LegacyMatch.php

namespace App\Models\Legacy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegacyMatch extends Model
{
    protected $connection = 'legacy';

    protected $table = 'matches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'imported' => 'boolean',
        ];
    }

    /** @return HasMany<LegacyGame, $this> */
    public function games(): HasMany
    {
        return $this->hasMany(LegacyGame::class, 'match_id');
    }

    /** @return BelongsTo<LegacyOpponent, $this> */
    public function opponent(): BelongsTo
    {
        return $this->belongsTo(LegacyOpponent::class, 'opponent_id');
    }

    /** @return BelongsTo<LegacyDeckVersion, $this> */
    public function deckVersion(): BelongsTo
    {
        return $this->belongsTo(LegacyDeckVersion::class, 'deck_version_id');
    }

    /** @return BelongsTo<LegacyLeague, $this> */
    public function league(): BelongsTo
    {
        return $this->belongsTo(LegacyLeague::class, 'league_id');
    }

    /** @return BelongsTo<LegacyTournament, $this> */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(LegacyTournament::class, 'tournament_id');
    }
}
```

`LegacyGame` → `hasMany(LegacyCardGameStat::class, 'game_id')` (`cardGameStats`) + `hasMany(LegacyGameDeck::class, 'game_id')` (`decks`), casts `won`/`local_on_play` bool, `turn_count`/dice/mulligans/instance int. `LegacyGameDeck` casts `is_opponent` bool + `deck_json` `array`. `LegacyDeckVersion` → `belongsTo(LegacyDeck::class, 'deck_id')`, casts `modified_at` datetime. `LegacyCardGameStat` casts the numeric/bool columns like the 0.x `CardGameStat`. `LegacyOpponent`/`LegacyLeague`/`LegacyTournament`/`LegacyDeck` are thin models (table + connection + guarded). **All set `$connection = 'legacy'`.**

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=LegacyModelsTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Legacy tests/Feature/Migration/LegacyModelsTest.php tests/Support
git commit -m "feat(migration): read-only legacy Eloquent models over 0.x tables"
```

---

### Task 3: `MapLegacyMatch` — one 0.x match → `ProjectedMatchData` (imported, sparse)

**Files:**
- Create: `app/Actions/Migration/MapLegacyMatch.php`
- Test: `tests/Feature/Migration/MapLegacyMatchTest.php`

**Interfaces:**
- Consumes: a `LegacyMatch` (Task 2), `GenerateDeckSignature`, the resolved local identity (client-agent Task 8; may carry only `mtgo_username`).
- Produces: `MapLegacyMatch::run(LegacyMatch $match): ProjectedMatchData` — envelope (`schema_version: 1`, `client_version`, `match_key` = `$match->token`, `compiled_at` = now, `file_version: 1`, **`imported: true`**, `mtgo_username`, `mtgo_player_id: null`) + full `match{}` mapped from the old rows, sparse where 0.x lacks data. Consumed by Task 4.

**Mapping table (0.x column → contract field):**

| Contract | 0.x source | Notes |
|---|---|---|
| `match_key` / `match.token` | `matches.token` | MatchToken uuid (verified) |
| `match.mtgo_id` | `matches.mtgo_id` | int (string col → cast) |
| `match.format` | `matches.format` | e.g. `CMODERN` |
| `match.match_type` | `matches.match_type` | e.g. `League` |
| `match.outcome` | `matches.outcome` | `win→Win`, `loss→Loss`, `draw→Draw`, else `Unknown` (TitleCase) |
| `match.outcome_source` | — | `'resolved'` when outcome known, else `'unknown'`. (0.x has no manual flag → never `'manual'`.) |
| `match.state` | `matches.state` | `complete→Complete`, `ended→Ended`, `in_progress→InProgress`, `started→Started`, `abandoned→Abandoned` |
| `match.started_at` / `ended_at` | `matches.started_at` / `ended_at` | ISO8601; `ended_at` nullable |
| `match.notes` | `matches.notes` | pass through (nullable) |
| `match.opponent.mtgo_player_id` | — | **null** (0.x `opponents` has no player id) |
| `match.opponent.username` | `opponents.username` via `matches.opponent_id` | display only |
| `match.deck` | `matches.deck_version_id` → `deck_versions` + `decks` | `mtgo_id`=`decks.mtgo_id`, `name`, `format`, `color_identity`, `modified_at`=`deck_versions.modified_at`, `signature`=`deck_versions.signature` (verbatim). **null when `deck_version_id` is null** (common for league opponent-only rows). |
| `match.league` | `matches.league_id` → `leagues` | `{token, name, format, joined_at, dropped_at}` or null |
| `match.tournament` | `matches.tournament_*` / `tournaments` | `{mtgo_event_id, round, name}` or null |
| `match.games[]` | `games` | see below |
| `match.opponent_archetype` | — | **null** (worker re-derives; local guess not carried for imports) |

**Per-game mapping (`games` row → `GameData`):**

| Contract | 0.x source |
|---|---|
| `mtgo_id` | `games.mtgo_id` |
| `won` | `games.won` (nullable → `null` = unknown) |
| `started_at` / `ended_at` | `games.started_at` / `ended_at` |
| `turn_count` | `games.turn_count` |
| `local_on_play` | `games.local_on_play` |
| `local_mulligans` / `opp_mulligans` | `games.local_mulligans` / `opp_mulligans` |
| `local_dice` / `opp_dice` | `games.local_dice` / `opp_dice` |
| `local_instance` / `opp_instance` | `games.local_instance` / `opp_instance` |
| `local_deck.signature` | `game_decks` where `is_opponent=0` → `GenerateDeckSignature::run(collect($deck_json))`; null if no row |
| `opponent_deck.signature` | `game_decks` where `is_opponent=1` → same; null if no row |
| `card_stats[]` | `card_game_stats` for the game — map every contract column; **drop `cast` and `sided_in`** (contract drops `cast`; `sided_in` has no contract slot) |
| `timeline[]` | **`[]`** — 0.x `game_timelines.content` is a per-turn JSON board snapshot, not `{action, timestamp, player, context}`; not convertible, left sparse |

- [ ] **Step 1: Write the failing test (constructed legacy rows → DTO assertions)**

```php
<?php // tests/Feature/Migration/MapLegacyMatchTest.php

use App\Actions\Migration\MapLegacyMatch;
use App\Data\ProjectedMatch\ProjectedMatchData;
use App\Models\Legacy\LegacyMatch;

beforeEach(function () {
    bootLegacySchema();       // Task 1 helper
    fakeLogUsername('Sharkcaster_Mage'); // client-agent identity helper (Task 8)
});

it('maps a complete league win into the imported, contract-shaped DTO', function () {
    seedLegacyMatch([
        'token' => '60a1a645-5b2e-42bd-8604-1258e66e2ed7',
        'mtgo_id' => '286469301',
        'format' => 'CMODERN',
        'match_type' => 'League',
        'state' => 'complete',
        'outcome' => 'win',
        'started_at' => '2026-06-26 10:00:00',
        'ended_at' => '2026-06-26 10:12:00',
        'opponent_username' => 'optivial',
        'league' => ['token' => 'season-tok', 'name' => 'Modern League', 'format' => 'CMODERN'],
        'games' => [[
            'mtgo_id' => '954965154', 'won' => true, 'turn_count' => 9,
            'local_on_play' => true, 'local_mulligans' => 1, 'opp_mulligans' => 0,
            'local_dice' => 6, 'opp_dice' => 3, 'local_instance' => 111, 'opp_instance' => 222,
            'local_deck_json' => [['mtgo_id' => 26121, 'quantity' => 4, 'sideboard' => false]],
            'opponent_deck_json' => [['mtgo_id' => 20700, 'quantity' => 3, 'sideboard' => false]],
            'card_stats' => [[
                'oracle_id' => 'abc', 'opponent' => false, 'quantity' => 4, 'kept' => 1, 'seen' => 2,
                'played' => 1, 'won' => true, 'is_postboard' => false, 'sided_out' => false,
                'pregame_revealed' => false, 'pregame_played' => false,
                'kicked' => 0, 'flashback' => 0, 'madness' => 0, 'evoked' => 0, 'activated' => 0,
            ]],
        ]],
    ]);

    $dto = app(MapLegacyMatch::class)->run(LegacyMatch::on('legacy')->firstOrFail());
    $json = $dto->toArray();

    expect($dto)->toBeInstanceOf(ProjectedMatchData::class);
    expect($json['imported'])->toBeTrue();
    expect($json['schema_version'])->toBe(1);
    expect($json['match_key'])->toBe('60a1a645-5b2e-42bd-8604-1258e66e2ed7');
    expect($json['match_key'])->toBe($json['match']['token']);       // key == token
    expect($json['mtgo_player_id'])->toBeNull();                     // sparse: 0.x has no local player id
    expect($json['match']['mtgo_id'])->toBe('286469301');            // MatchID attribute
    expect($json['match']['outcome'])->toBe('Win');
    expect($json['match']['outcome_source'])->toBe('resolved');
    expect($json['match']['state'])->toBe('Complete');
    expect($json['match']['opponent']['mtgo_player_id'])->toBeNull(); // sparse
    expect($json['match']['opponent']['username'])->toBe('optivial');
    expect($json['match']['league']['token'])->toBe('season-tok');
    expect($json['match']['tournament'])->toBeNull();

    $game = $json['match']['games'][0];
    expect($game['won'])->toBeTrue();
    expect($game['turn_count'])->toBe(9);
    expect($game['local_on_play'])->toBeTrue();
    expect($game['local_deck']['signature'])->not->toBeNull();       // re-encoded via GenerateDeckSignature
    expect($game['opponent_deck']['signature'])->not->toBeNull();
    expect($game['card_stats'][0]['oracle_id'])->toBe('abc');
    expect($game['card_stats'][0])->not->toHaveKey('cast');          // contract drops cast
    expect($game['timeline'])->toBe([]);                             // sparse — no structured timeline in 0.x
});

it('maps an unknown-outcome match with a null deck (opponent-only league row)', function () {
    seedLegacyMatch([
        'token' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'mtgo_id' => '999', 'format' => 'CMODERN', 'match_type' => 'League',
        'state' => 'ended', 'outcome' => null, 'deck_version_id' => null,
        'opponent_username' => 'ghost', 'games' => [],
    ]);

    $json = app(MapLegacyMatch::class)->run(LegacyMatch::on('legacy')->firstOrFail())->toArray();

    expect($json['imported'])->toBeTrue();
    expect($json['match']['outcome'])->toBe('Unknown');
    expect($json['match']['outcome_source'])->toBe('unknown');
    expect($json['match']['deck'])->toBeNull();
    expect($json['match']['games'])->toBe([]);                       // sparse variant
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=MapLegacyMatchTest`
Expected: FAIL — `MapLegacyMatch` not defined.

- [ ] **Step 3: Implement `MapLegacyMatch`**

```php
<?php // app/Actions/Migration/MapLegacyMatch.php

namespace App\Actions\Migration;

use App\Actions\Auth\ResolveLocalIdentity;
use App\Actions\Decks\GenerateDeckSignature;
use App\Data\ProjectedMatch\ProjectedMatchData;
use App\Models\Legacy\LegacyGame;
use App\Models\Legacy\LegacyGameDeck;
use App\Models\Legacy\LegacyMatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class MapLegacyMatch
{
    private const STATE_MAP = [
        'started' => 'Started', 'in_progress' => 'InProgress',
        'ended' => 'Ended', 'complete' => 'Complete', 'abandoned' => 'Abandoned',
    ];

    private const OUTCOME_MAP = ['win' => 'Win', 'loss' => 'Loss', 'draw' => 'Draw'];

    public function __construct(private ResolveLocalIdentity $identity) {}

    public function run(LegacyMatch $match): ProjectedMatchData
    {
        $match->loadMissing([
            'opponent', 'league', 'tournament',
            'deckVersion.deck',
            'games.cardGameStats', 'games.decks',
        ]);

        $identity = $this->identity->run(); // may be null; import still records username if present
        $outcome = self::OUTCOME_MAP[$match->outcome] ?? 'Unknown';

        return ProjectedMatchData::from([
            'schema_version' => 1,
            'client_version' => config('mtgo.client_version'),
            'match_key' => $match->token,               // MatchToken uuid — verified
            'compiled_at' => now()->toIso8601String(),
            'file_version' => 1,
            'imported' => true,
            'mtgo_username' => $identity?->mtgoUsername,
            'mtgo_player_id' => null,                   // sparse: 0.x has no local player id
            'match' => [
                'token' => $match->token,
                'mtgo_id' => $match->mtgo_id,
                'format' => $match->format,
                'match_type' => $match->match_type,
                'outcome' => $outcome,
                'outcome_source' => $outcome === 'Unknown' ? 'unknown' : 'resolved',
                'state' => self::STATE_MAP[$match->state] ?? 'Ended',
                'started_at' => $match->started_at?->toIso8601String(),
                'ended_at' => $match->ended_at?->toIso8601String(),
                'notes' => $match->notes,
                'opponent' => [
                    'mtgo_player_id' => null,           // sparse
                    'username' => $match->opponent?->username,
                ],
                'deck' => $this->deck($match),
                'league' => $this->league($match),
                'tournament' => $this->tournament($match),
                'games' => $match->games->map(fn (LegacyGame $g) => $this->game($g))->all(),
                'opponent_archetype' => null,           // worker re-derives
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function deck(LegacyMatch $match): ?array
    {
        $version = $match->deckVersion;
        if ($version === null) {
            return null;
        }

        return [
            'mtgo_id' => $version->deck?->mtgo_id,
            'name' => $version->deck?->name,
            'format' => $version->deck?->format,
            'color_identity' => $version->deck?->color_identity,
            'modified_at' => $version->modified_at?->toIso8601String(),
            'signature' => $version->signature,          // base64 cardlist, verbatim
        ];
    }

    /** @return array<string, mixed> */
    private function game(LegacyGame $game): array
    {
        return [
            'mtgo_id' => $game->mtgo_id,
            'won' => $game->won,                         // nullable = unknown
            'started_at' => $game->started_at?->toIso8601String(),
            'ended_at' => $game->ended_at?->toIso8601String(),
            'turn_count' => $game->turn_count,
            'local_on_play' => $game->local_on_play,
            'local_mulligans' => $game->local_mulligans,
            'opp_mulligans' => $game->opp_mulligans,
            'local_dice' => $game->local_dice,
            'opp_dice' => $game->opp_dice,
            'local_instance' => $game->local_instance,
            'opp_instance' => $game->opp_instance,
            'local_deck' => $this->gameDeckSignature($game, isOpponent: false),
            'opponent_deck' => $this->gameDeckSignature($game, isOpponent: true),
            'card_stats' => $game->cardGameStats->map(fn ($s) => $this->cardStat($s))->all(),
            'timeline' => [],                            // sparse — no structured timeline in 0.x
        ];
    }

    /** @return array{signature: string}|null */
    private function gameDeckSignature(LegacyGame $game, bool $isOpponent): ?array
    {
        /** @var LegacyGameDeck|null $deck */
        $deck = $game->decks->firstWhere('is_opponent', $isOpponent);
        if ($deck === null || empty($deck->deck_json)) {
            return null;
        }

        return ['signature' => GenerateDeckSignature::run(new Collection($deck->deck_json))];
    }

    /** @return array<string, mixed> */
    private function cardStat($s): array
    {
        // contract drops `cast` (duplicates played) and has no slot for 0.x `sided_in`
        return [
            'oracle_id' => $s->oracle_id,
            'opponent' => $s->opponent,
            'quantity' => $s->quantity,
            'kept' => $s->kept,
            'seen' => $s->seen,
            'played' => $s->played,
            'won' => $s->won,
            'is_postboard' => $s->is_postboard,
            'sided_out' => $s->sided_out,
            'pregame_revealed' => $s->pregame_revealed,
            'pregame_played' => $s->pregame_played,
            'kicked' => $s->kicked,
            'flashback' => $s->flashback,
            'madness' => $s->madness,
            'evoked' => $s->evoked,
            'activated' => $s->activated,
        ];
    }

    /** @return array<string, mixed>|null */
    private function league(LegacyMatch $match): ?array
    {
        $league = $match->league;
        if ($league === null) {
            return null;
        }

        return [
            'token' => $league->token,
            'name' => $league->name,
            'format' => $league->format,
            'joined_at' => $league->joined_at ? Carbon::parse($league->joined_at)->toIso8601String() : null,
            'dropped_at' => $league->dropped_at ? Carbon::parse($league->dropped_at)->toIso8601String() : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function tournament(LegacyMatch $match): ?array
    {
        $tournament = $match->tournament;
        if ($tournament === null && $match->tournament_event_id === null) {
            return null;
        }

        return [
            'mtgo_event_id' => $tournament?->mtgo_event_id ?? $match->tournament_event_id,
            'round' => $match->tournament_round,
            'name' => $tournament?->name,
        ];
    }
}
```

> If `GenerateDeckSignature::run()` calls `CreateMissingCards` (writes to the `mymtgo` card catalog), that write targets `mymtgo`, never `legacy` — the old DB stays read-only. Verify in Step 4 that the test passes without touching `legacy`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=MapLegacyMatchTest`
Expected: PASS (both the full-win case and the sparse unknown/null-deck case).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Migration/MapLegacyMatch.php tests/Feature/Migration/MapLegacyMatchTest.php tests/Support
git commit -m "feat(migration): map 0.x match row into imported ProjectedMatchData (sparse)"
```

---

### Task 4: `ImportLegacyData` — iterate → map → enqueue → push

**Files:**
- Create: `app/Actions/Migration/ImportLegacyData.php`
- Test: `tests/Feature/Migration/ImportLegacyDataTest.php`

**Interfaces:**
- Consumes: `LegacyMatch` (Task 2), `MapLegacyMatch` (Task 3), `EnqueueMatch` (client-agent Task 11), `PushOutboxJob` (client-agent Task 12), the `Outbox` model.
- Produces: `ImportLegacyData::run(): void` — chunk over `LegacyMatch::on('legacy')`, map each → `EnqueueMatch::run($dto)` → dispatch `PushOutboxJob`. **Skips a match already `synced` in the outbox** (by `match_key`). Consumed by Task 5 (job) + Task 6 (progress).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Migration/ImportLegacyDataTest.php

use App\Actions\Migration\ImportLegacyData;
use App\Jobs\PushOutboxJob;
use App\Models\Outbox;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    bootLegacySchema();
    fakeLogUsername('Sharkcaster_Mage');
});

it('enqueues one outbox row per legacy match and dispatches the push', function () {
    Queue::fake();
    seedLegacyMatch(['token' => 'tok-a', 'mtgo_id' => '1', 'match_type' => 'League', 'state' => 'complete', 'outcome' => 'win',
        'games' => [['mtgo_id' => 'g1', 'won' => true]]]);
    seedLegacyMatch(['token' => 'tok-b', 'mtgo_id' => '2', 'match_type' => 'League', 'state' => 'complete', 'outcome' => 'loss',
        'games' => [['mtgo_id' => 'g2', 'won' => false]]]);

    app(ImportLegacyData::class)->run();

    expect(Outbox::on('mymtgo')->count())->toBe(2);
    expect(Outbox::on('mymtgo')->whereIn('match_key', ['tok-a', 'tok-b'])->count())->toBe(2);
    Queue::assertPushed(PushOutboxJob::class);
});

it('skips a match already synced in the outbox', function () {
    Queue::fake();
    seedLegacyMatch(['token' => 'tok-a', 'mtgo_id' => '1', 'match_type' => 'League', 'state' => 'complete', 'outcome' => 'win',
        'games' => [['mtgo_id' => 'g1', 'won' => true]]]);

    Outbox::on('mymtgo')->create([
        'match_key' => 'tok-a', 'payload' => '{}', 'file_version' => 5,
        'status' => 'synced', 'synced_version' => 5, 'attempts' => 0,
    ]);

    app(ImportLegacyData::class)->run();

    // still one row, still synced (not re-enqueued / not reset to pending)
    expect(Outbox::on('mymtgo')->where('match_key', 'tok-a')->count())->toBe(1);
    expect(Outbox::on('mymtgo')->where('match_key', 'tok-a')->first()->status)->toBe('synced');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ImportLegacyDataTest`
Expected: FAIL — `ImportLegacyData` not defined.

- [ ] **Step 3: Implement `ImportLegacyData`**

```php
<?php // app/Actions/Migration/ImportLegacyData.php

namespace App\Actions\Migration;

use App\Actions\Outbox\EnqueueMatch;
use App\Jobs\PushOutboxJob;
use App\Models\Legacy\LegacyMatch;
use App\Models\Outbox;
use Throwable;

final class ImportLegacyData
{
    public function __construct(
        private MapLegacyMatch $map,
        private EnqueueMatch $enqueue,
    ) {}

    public function run(): void
    {
        $syncedKeys = Outbox::on('mymtgo')
            ->where('status', 'synced')
            ->pluck('match_key')
            ->all();

        LegacyMatch::on('legacy')
            ->orderBy('id')
            ->chunkById(100, function ($matches) use ($syncedKeys) {
                foreach ($matches as $match) {
                    if (in_array($match->token, $syncedKeys, strict: true)) {
                        continue; // already synced — idempotent skip
                    }

                    try {
                        $dto = $this->map->run($match);
                        $this->enqueue->run($dto); // upsert on match_key, monotonic file_version
                    } catch (Throwable $e) {
                        // isolate a bad legacy row — never stall the batch (Task 7)
                        report($e);

                        continue;
                    }
                }
            });

        // one push pass drains all pending rows with per-row failure isolation
        PushOutboxJob::dispatch();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ImportLegacyDataTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Migration/ImportLegacyData.php tests/Feature/Migration/ImportLegacyDataTest.php
git commit -m "feat(migration): iterate legacy matches -> map -> enqueue -> push (skip synced)"
```

---

### Task 5: Opt-in trigger — job, controller, route, button

**Files:**
- Create: `app/Jobs/ImportLegacyDataJob.php`
- Create: `app/Http/Controllers/Migration/ImportLegacyDataController.php`
- Modify: `routes/web.php` (add `migration.import` POST route)
- Create/modify: `resources/js/pages/settings/partials/LegacyImportCard.vue` (button; nearest existing settings page if the path differs)
- Test: `tests/Feature/Migration/ImportLegacyDataControllerTest.php`

**Interfaces:**
- Produces: a POST endpoint (Wayfinder-generated action import on the frontend) that dispatches `ImportLegacyDataJob`, which calls `ImportLegacyData::run()` off the request thread. Button is the sole entry point — never auto-runs.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Migration/ImportLegacyDataControllerTest.php

use App\Jobs\ImportLegacyDataJob;
use Illuminate\Support\Facades\Queue;

it('dispatches the import job when the button is pressed', function () {
    Queue::fake();

    $this->post(route('migration.import'))->assertRedirect();

    Queue::assertPushed(ImportLegacyDataJob::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ImportLegacyDataControllerTest`
Expected: FAIL — route/controller/job missing.

- [ ] **Step 3: Implement job, controller, route**

```php
<?php // app/Jobs/ImportLegacyDataJob.php

namespace App\Jobs;

use App\Actions\Migration\ImportLegacyData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportLegacyDataJob implements ShouldQueue
{
    use Queueable;

    public function handle(ImportLegacyData $import): void
    {
        $import->run();
    }
}
```

```php
<?php // app/Http/Controllers/Migration/ImportLegacyDataController.php

namespace App\Http\Controllers\Migration;

use App\Jobs\ImportLegacyDataJob;
use Illuminate\Http\RedirectResponse;

class ImportLegacyDataController
{
    public function __invoke(): RedirectResponse
    {
        ImportLegacyDataJob::dispatch();

        return back()->with('status', 'Import started');
    }
}
```

In `routes/web.php` (match the existing settings route group / middleware):

```php
use App\Http\Controllers\Migration\ImportLegacyDataController;

Route::post('/settings/import-legacy', ImportLegacyDataController::class)->name('migration.import');
```

- [ ] **Step 4: Frontend button (Wayfinder action)**

In the settings partial, add a button that submits the Wayfinder action import for `ImportLegacyDataController` (from `@/actions/...`) via the Inertia `<Form>` component. Follow the existing settings-page form conventions; show a confirm + disabled/spinner state while in flight. Read progress via Task 6.

Run `npm run build` (or note that `composer run dev` regenerates Wayfinder actions) so `@/actions/...ImportLegacyDataController` resolves.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=ImportLegacyDataControllerTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Jobs/ImportLegacyDataJob.php app/Http/Controllers/Migration routes/web.php resources/js/pages/settings tests/Feature/Migration/ImportLegacyDataControllerTest.php
git commit -m "feat(migration): opt-in import button -> queued ImportLegacyDataJob"
```

---

### Task 6: Progress reporting from outbox counts

**Files:**
- Create: `app/Actions/Migration/LegacyImportProgress.php`
- Modify: the settings controller that renders the import partial (Inertia prop) — or a lightweight `GET` status endpoint the button polls
- Test: `tests/Feature/Migration/LegacyImportProgressTest.php`

**Interfaces:**
- Consumes: `Outbox` (client-agent) + `LegacyMatch` count (the denominator).
- Produces: `LegacyImportProgress::run(): array{total:int, synced:int, failed:int, pending:int}` — `total` = legacy match count; the rest read from outbox statuses. The Vue partial renders `synced/total` and a failed badge. Poll via Inertia (v2) polling or a `GET` refetch.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Migration/LegacyImportProgressTest.php

use App\Actions\Migration\LegacyImportProgress;
use App\Models\Outbox;

beforeEach(fn () => bootLegacySchema());

it('reports total from legacy matches and synced/failed/pending from the outbox', function () {
    seedLegacyMatch(['token' => 'a', 'mtgo_id' => '1']);
    seedLegacyMatch(['token' => 'b', 'mtgo_id' => '2']);
    seedLegacyMatch(['token' => 'c', 'mtgo_id' => '3']);

    Outbox::on('mymtgo')->create(['match_key' => 'a', 'payload' => '{}', 'file_version' => 1, 'status' => 'synced', 'synced_version' => 1, 'attempts' => 0]);
    Outbox::on('mymtgo')->create(['match_key' => 'b', 'payload' => '{}', 'file_version' => 1, 'status' => 'failed', 'attempts' => 5]);
    Outbox::on('mymtgo')->create(['match_key' => 'c', 'payload' => '{}', 'file_version' => 1, 'status' => 'pending', 'attempts' => 1]);

    $progress = app(LegacyImportProgress::class)->run();

    expect($progress)->toBe(['total' => 3, 'synced' => 1, 'failed' => 1, 'pending' => 1]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=LegacyImportProgressTest`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement `LegacyImportProgress`**

```php
<?php // app/Actions/Migration/LegacyImportProgress.php

namespace App\Actions\Migration;

use App\Models\Legacy\LegacyMatch;
use App\Models\Outbox;

final class LegacyImportProgress
{
    /** @return array{total:int, synced:int, failed:int, pending:int} */
    public function run(): array
    {
        $counts = Outbox::on('mymtgo')
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'total' => LegacyMatch::on('legacy')->count(),
            'synced' => (int) $counts->get('synced', 0),
            'failed' => (int) $counts->get('failed', 0),
            'pending' => (int) $counts->get('pending', 0),
        ];
    }
}
```

Wire `LegacyImportProgress::run()` into the settings page Inertia props (or a `GET /settings/import-legacy/progress` endpoint) so the Vue partial can render `synced/total` and poll while `pending > 0`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=LegacyImportProgressTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Migration/LegacyImportProgress.php tests/Feature/Migration/LegacyImportProgressTest.php app/Http/Controllers resources/js/pages/settings
git commit -m "feat(migration): import progress from outbox synced/failed/pending counts"
```

---

### Task 7: Partial-failure isolation + safe re-run

**Files:**
- Modify (if needed): `app/Actions/Migration/ImportLegacyData.php` (confirm try/catch isolation from Task 4)
- Test: `tests/Feature/Migration/ImportLegacyDataResilienceTest.php`

**Interfaces:**
- Guarantees: (a) a single un-mappable legacy match does not abort the batch — it is `report()`ed and skipped, other matches still enqueue; (b) a push failure isolates in the outbox `failed` status (client-agent `PushMatch`), never blocking siblings; (c) re-running the whole import re-upserts (idempotent) and skips already-`synced` rows.

- [ ] **Step 1: Write the failing test (bad row isolation + re-run idempotency)**

```php
<?php // tests/Feature/Migration/ImportLegacyDataResilienceTest.php

use App\Actions\Migration\ImportLegacyData;
use App\Actions\Migration\MapLegacyMatch;
use App\Data\ProjectedMatch\ProjectedMatchData;
use App\Models\Legacy\LegacyMatch;
use App\Models\Outbox;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    bootLegacySchema();
    fakeLogUsername('Sharkcaster_Mage');
    Queue::fake();
});

it('isolates a match that fails to map and still enqueues the good ones', function () {
    seedLegacyMatch(['token' => 'good-1', 'mtgo_id' => '1', 'match_type' => 'League', 'state' => 'complete', 'outcome' => 'win', 'games' => [['mtgo_id' => 'g1', 'won' => true]]]);
    seedLegacyMatch(['token' => 'bad-1', 'mtgo_id' => '2', 'match_type' => 'League', 'state' => 'complete', 'outcome' => 'win', 'games' => [['mtgo_id' => 'g2', 'won' => true]]]);
    seedLegacyMatch(['token' => 'good-2', 'mtgo_id' => '3', 'match_type' => 'League', 'state' => 'complete', 'outcome' => 'loss', 'games' => [['mtgo_id' => 'g3', 'won' => false]]]);

    // make the mapper throw for exactly the 'bad-1' token
    $this->mock(MapLegacyMatch::class, function ($mock) {
        $mock->shouldReceive('run')->andReturnUsing(function (LegacyMatch $m) {
            if ($m->token === 'bad-1') {
                throw new RuntimeException('unmappable legacy row');
            }

            return ProjectedMatchData::from([
                'schema_version' => 1, 'client_version' => '1.0.0', 'match_key' => $m->token,
                'compiled_at' => now()->toIso8601String(), 'file_version' => 1, 'imported' => true,
                'mtgo_username' => 'Sharkcaster_Mage', 'mtgo_player_id' => null,
                'match' => ['token' => $m->token, 'mtgo_id' => $m->mtgo_id, 'format' => 'CMODERN',
                    'match_type' => 'League', 'outcome' => 'Win', 'outcome_source' => 'resolved',
                    'state' => 'Complete', 'started_at' => now()->toIso8601String(), 'ended_at' => null,
                    'opponent' => ['mtgo_player_id' => null, 'username' => null], 'games' => []],
            ]);
        });
    });

    app(ImportLegacyData::class)->run();

    // the two good matches enqueued; the bad one did not stall the batch
    expect(Outbox::on('mymtgo')->pluck('match_key')->sort()->values()->all())->toBe(['good-1', 'good-2']);
});

it('is safe to re-run: re-import re-upserts and skips synced rows', function () {
    seedLegacyMatch(['token' => 'r-1', 'mtgo_id' => '1', 'match_type' => 'League', 'state' => 'complete', 'outcome' => 'win', 'games' => [['mtgo_id' => 'g1', 'won' => true]]]);

    app(ImportLegacyData::class)->run();
    $first = Outbox::on('mymtgo')->where('match_key', 'r-1')->firstOrFail();

    // simulate it having synced
    $first->update(['status' => 'synced', 'synced_version' => $first->file_version]);

    app(ImportLegacyData::class)->run(); // second run

    expect(Outbox::on('mymtgo')->where('match_key', 'r-1')->count())->toBe(1); // still one row
    expect(Outbox::on('mymtgo')->where('match_key', 'r-1')->first()->status)->toBe('synced'); // untouched
});
```

- [ ] **Step 2: Run test to verify it fails / then passes**

Run: `php artisan test --compact --filter=ImportLegacyDataResilienceTest`
Expected: FAIL first if the Task 4 try/catch or synced-skip is missing; add/confirm them, then PASS.

- [ ] **Step 3: Confirm/patch isolation in `ImportLegacyData`**

Ensure the per-match `try/catch` (report + continue) and the `synced` skip from Task 4 are present. Do not widen the catch beyond a single match iteration. Push-side isolation (`failed` after N attempts) is already provided by client-agent `PushMatch`/`PushOutboxJob` — reference, don't reimplement.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ImportLegacyDataResilienceTest`
Expected: PASS (bad-row isolation + safe re-run).

- [ ] **Step 5: Full migration-suite run + Pint + commit**

```bash
php artisan test --compact --filter=Migration
vendor/bin/pint --dirty --format agent
git add app/Actions/Migration/ImportLegacyData.php tests/Feature/Migration/ImportLegacyDataResilienceTest.php
git commit -m "feat(migration): per-match failure isolation + idempotent safe re-run"
```

---

## Self-Review checklist (run after fleshing 1–7)

1. **Spec coverage** — every bullet in [`spec.md`](./spec.md) maps to a task: read-only old DB = Task 1; old-schema→json read-mapper = Tasks 2–3; outbox→sink→worker reuse = Task 4; opt-in button + progress = Tasks 5–6; partial-failure isolation + safe re-run = Task 7. Reused client-agent pieces (`ProjectedMatchData`, `EnqueueMatch`/`Outbox`, `PushMatch`/`PushOutboxJob`, `AppAccount`/`ResolveLocalIdentity`, `GenerateDeckSignature`) are referenced, not rebuilt.
2. **`nativephp.sqlite` untouched** — `legacy` connection is `SQLITE_OPEN_READONLY`; no migration targets it; `GenerateDeckSignature`'s card-catalog writes hit `mymtgo`, never `legacy`. Tests use an in-memory `legacy` db, never the real file.
3. **`match_key` = MatchToken** — mapped from `matches.token` (verified uuid in the live DB); asserted `match_key == match.token` in Task 3. No int-fallback path needed.
4. **Sparse correctness** — `imported: true` on every DTO; `mtgo_player_id` (local + opponent) → null; `timeline` → `[]`; `deck`/`games` nullable/empty where 0.x lacks rows; `card_stats` drops `cast`. Asserted in Task 3.
5. **Idempotency** — `EnqueueMatch` upsert on `match_key`; synced rows skipped; re-run re-upserts. Asserted in Tasks 4 + 7.
6. **Placeholder scan** — no "TBD" / "handle edge cases" / "similar to Task N".
7. **Type consistency** — `ProjectedMatchData` field names identical across Tasks 3/4/7; `Outbox` status strings (`pending|synced|failed`) match client-agent Tasks 11/12.
