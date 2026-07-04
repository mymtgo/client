# Cloud Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Where this runs:** the code described here lives in the **separate cloud API project** at `/Volumes/Dev/mymtgo/api` (the DigitalOcean droplet). This plan FILE lives in the client repo docs only. All file paths below are relative to `/Volumes/Dev/mymtgo/api`.

> **Grounding note:** the API project already exists as a real Laravel app. Observed on disk: Laravel 13 / PHP 8.3, PostgreSQL, Spatie Laravel Data v4, Laravel Horizon (Redis queue), a pre-configured `s3` disk (`config/filesystems.php`) pointed at DigitalOcean Spaces, invokable Actions under `app/Actions/`, queued Jobs under `app/Jobs/`, Pest v4 with `tests/Pest.php` (RefreshDatabase applied per-suite, not global). The canonical stack is **Laravel 13 / PHP 8.3 / PostgreSQL** (see [`../overview/spec.md`](../overview/spec.md) §8). v1 cloud = a NEW Postgres database on a new host; 0.x is frozen on its own subdomain + existing DB (no server-side migration). Identity tables (`users`, `mtgo_accounts`) belong to [`../cloud-auth/spec.md`](../cloud-auth/spec.md) — **reference them, do not create them here.**

> **Format:** the match-building projection is **shared/ported from the client compiler** (same event→match logic reused server-side — see [`spec.md`](./spec.md) §2 and [`../overview/spec.md`](../overview/spec.md) §6). Ported logic is copied + re-verified, not re-authored; new code gets complete TDD steps.

**Goal:** Build the cloud system-of-record pipeline: a **dumb sink** that stores each pushed `{match}.json` to DigitalOcean Spaces and enqueues a build job (auth + ownership only, always 200), a **queued build worker** that reads the file and idempotently upserts the queryable match schema (matches / opponents / games / game_decks / card_game_stats / game_timeline / decks / deck_versions / leagues / tournaments), a thin **`match.logged`** signal emitted after DB commit, and a **re-derivation command** that re-runs the worker over every stored file.

**Architecture:** `match_files` is the inbox and source of record; everything else is derived. The sink write-path **cannot fail on content** — it validates auth/ownership, stores the blob, upserts one `match_files` row (`UNIQUE(user_id, match_key)`), enqueues `BuildMatchFromFile`, and acks 200. The worker consumes the file, runs the shared projection into a `BuildMatch` action that upserts on `match_key` inside a DB transaction (never-regress deck versioning keyed on the XML `modified_at`), handles sparse/malformed files gracefully, and dead-letters (never data-loses) on unrecoverable failure. Re-derivation is just re-enqueuing every `match_files` row.

**Tech Stack:** PHP 8.3, Laravel 13, PostgreSQL, Pest v4, Pint v1, Spatie Laravel Data (DTOs), Laravel Horizon (Redis queue), DigitalOcean Spaces via the Laravel `s3` disk (see [`../overview/spec.md`](../overview/spec.md) §8). v1 cloud = a NEW Postgres database on a new host; 0.x is frozen on its own subdomain + existing DB (no server-side migration).

## Global Constraints

- **`match_files` is the source of record; every gameplay table is derived.** Re-running the worker over stored files rebuilds everything (see [`spec.md`](./spec.md) §1, §4).
- **`match_key` = MatchToken uuid** — the per-match identity. `mtgo_id` (MatchID int) is an attribute; `league_token` groups league runs, never a key (see [`../contract/spec.md`](../contract/spec.md) "Identity rules").
- **Per-user, source-scoped uniqueness, never global:** `matches` and `match_files` both carry a `source` column (`mtgo`|`arena`; v1 = `mtgo`) and **`UNIQUE(user_id, source, match_key)`** (the constraint 0.x lacks; source-scoped for the Arena seam — see `RECONCILIATION.md`). The same MatchToken is shared by both players, so it can never be globally unique per-account. Every migration + test/create in this plan that shows `UNIQUE(user_id, match_key)` / omits `source` must include it.
- **Opponents are global**, keyed on the stable numeric id: `mtgo_player_id` is **nullable** with a **partial unique index** (`UNIQUE(mtgo_player_id) WHERE mtgo_player_id IS NOT NULL`) because 0.x imports carry no player id; those rows fall back to `username`. `username` is a display attribute that may change (see [`../ops/spec.md`](../ops/spec.md) Privacy, [`../contract/spec.md`](../contract/spec.md)).
- **Auth + ownership only on the sink.** Every request is scoped to the authenticated token's `user_id`; a client may only write files under its own `user_id` + `match_key`. Ownership is enforced server-side — the server never trusts the client for scoping (see [`../ops/spec.md`](../ops/spec.md) Authorization, [`../cloud-auth/spec.md`](../cloud-auth/spec.md) Bearer tokens). The sink **never validates content/schema** and **always acks 200** (except auth/ownership rejection).
- **Worker is idempotent — upsert on `match_key`.** Same file processed twice = same DB state. Never-regress deck versioning: same signature → reuse; newer `modified_at` → new version; older → ignore.
- **Sparse / malformed is normal input.** `games: []`, games without `card_stats`/`timeline`, and 0.x `imported: true` files all build a valid (match-only) record. Malformed JSON → dead-letter, never a crash-loop, never partial data.
- **Dead-letter, not data-loss.** Unrecoverable failure sets `match_files.status = 'dead-letter'` with `error`; the blob is retained so a later worker fix + re-derivation recovers it.
- **`match.logged` is emitted by the worker after DB commit only, never by the sink.** Thin signal `{ matchKey, version }` — the socket never carries match data (see [`../cloud-api/spec.md`](../cloud-api/spec.md) Realtime).
- **Object storage = DigitalOcean Spaces via the `s3` disk.** Files are namespaced `matches/{user_id}/{match_key}/{file_version}.json`. Tests use `Storage::fake('s3')`.
- **Manual outcomes survive re-derivation** because `outcome_source: "manual"` is baked into the file, not stored only in the DB (see [`spec.md`](./spec.md) §4).
- **Reuse code, not the DB.** These are clean v1 migrations on the NEW Postgres DB — not additions to the live 0.x DB. The proven event→match projection is **ported** from the client compiler / `../api`; the cloud-of-record schema here is built fresh (see [`../overview/spec.md`](../overview/spec.md) §6, §8).
- **Postgres schema choices** (exploit Postgres, don't just swap the driver): `match_key` (and `league_token`) as native `uuid`; JSON columns (`game_decks.deck_json`, `game_timeline` context) as `jsonb`; conditional uniqueness as **partial unique indexes** — e.g. `opponents`'s `mtgo_player_id` is nullable with `UNIQUE(mtgo_player_id) WHERE mtgo_player_id IS NOT NULL`; enums (`outcome`, `outcome_source`, `status`) as string + `casts()`, not native PG enums; idempotent upserts via `INSERT ... ON CONFLICT`.
- Use **invokable Actions** (single responsibility), not service classes. PHP 8 constructor promotion, explicit return types, curly braces on all control structures, PHPDoc over inline comments.
- Run `vendor/bin/pint --dirty --format agent` before finalizing any task.
- Tests: Pest v4, feature tests preferred, folded into the task whose deliverable needs them. `Storage::fake('s3')` + `RefreshDatabase` per-suite (it is not global in `tests/Pest.php`).

---

## File Structure

**New (cloud pipeline):**
- `database/migrations/*_create_match_files_table.php` — the inbox / source of record.
- `database/migrations/*_create_opponents_table.php`
- `database/migrations/*_create_decks_table.php`
- `database/migrations/*_create_deck_versions_table.php`
- `database/migrations/*_create_leagues_table.php`
- `database/migrations/*_create_tournaments_table.php`
- `database/migrations/*_create_matches_table.php`
- `database/migrations/*_create_games_table.php`
- `database/migrations/*_create_game_decks_table.php`
- `database/migrations/*_create_card_game_stats_table.php`
- `database/migrations/*_create_game_timeline_table.php`
- `app/Models/{MatchFile,Opponent,MtgoMatch,Game,GameDeck,CardGameStat,GameTimelineEntry,Deck,DeckVersion,League,Tournament}.php`
  - **Note:** the model is `MtgoMatch` (table `matches`), never `Match` (reserved word). A `DeckVersion` model already exists for the catalog domain — **do not clobber it**; the cloud-pipeline deck-version rows live in the new `deck_versions` table with the columns this plan defines (reconcile ownership with the catalog domain at integration; this plan owns the schema shape stated in [`spec.md`](./spec.md) §3).
- `app/Data/MatchFile/*.php` — Spatie Data DTO tree mirroring [`../contract/spec.md`](../contract/spec.md) (the shape the worker parses).
- `app/Http/Controllers/Sink/StoreMatchFileController.php` — the dumb sink endpoint.
- `app/Http/Requests/StoreMatchFileRequest.php` — auth + ownership form request.
- `app/Actions/Pipeline/StoreMatchFile.php` — store blob + upsert `match_files` + enqueue.
- `app/Actions/Pipeline/BuildMatch.php` — the shared projection → schema upsert (idempotent).
- `app/Actions/Pipeline/ResolveDeckVersion.php` — never-regress deck versioning.
- `app/Actions/Pipeline/UpsertOpponent.php` — global opponent upsert on `mtgo_player_id`.
- `app/Jobs/BuildMatchFromFile.php` — the queued build worker.
- `app/Events/MatchLogged.php` — thin `match.logged` broadcast.
- `app/Console/Commands/RederiveMatches.php` — re-enqueue every stored file.

**Referenced, not created (owned by other specs):**
- `users`, `mtgo_accounts` tables + models — [`../cloud-auth/spec.md`](../cloud-auth/spec.md).
- The read API + Reverb channel that consumes `match.logged` — [`../cloud-api/spec.md`](../cloud-api/spec.md).
- The `s3` disk config (already present in `config/filesystems.php`).

---

### Task 1: `match_files` inbox migration + model

**Files:**
- Create: `database/migrations/2026_07_01_000001_create_match_files_table.php`
- Create: `app/Models/MatchFile.php`
- Test: `tests/Feature/Pipeline/MatchFileSchemaTest.php`

**Interfaces:**
- Produces: the `match_files` inbox table with `UNIQUE(user_id, match_key)`, and a `MatchFile` model. Consumed by the sink (Task 8) and the worker (Task 10).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Pipeline/MatchFileSchemaTest.php
use App\Models\MatchFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the match_files inbox table with the documented columns', function () {
    expect(Schema::hasTable('match_files'))->toBeTrue();
    expect(Schema::hasColumns('match_files', [
        'id', 'user_id', 'match_key', 'object_path', 'file_version',
        'status', 'last_processed_version', 'error', 'received_at', 'processed_at',
    ]))->toBeTrue();
});

it('enforces UNIQUE(user_id, match_key)', function () {
    MatchFile::create([
        'user_id' => 1, 'match_key' => 'tok-1', 'object_path' => 'matches/1/tok-1/1.json',
        'file_version' => 1, 'status' => 'received',
    ]);

    expect(fn () => MatchFile::create([
        'user_id' => 1, 'match_key' => 'tok-1', 'object_path' => 'matches/1/tok-1/2.json',
        'file_version' => 2, 'status' => 'received',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('allows the same match_key for a different user', function () {
    MatchFile::create(['user_id' => 1, 'match_key' => 'tok-1', 'object_path' => 'a', 'file_version' => 1, 'status' => 'received']);
    MatchFile::create(['user_id' => 2, 'match_key' => 'tok-1', 'object_path' => 'b', 'file_version' => 1, 'status' => 'received']);

    expect(MatchFile::where('match_key', 'tok-1')->count())->toBe(2);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=MatchFileSchemaTest`
Expected: FAIL — table `match_files` does not exist.

- [ ] **Step 3: Write the migration**

```php
<?php // database/migrations/2026_07_01_000001_create_match_files_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id'); // FK to cloud-auth users; constraint added when that table lands
            $table->uuid('match_key');
            $table->string('object_path');            // blob location in Spaces
            $table->unsignedInteger('file_version');  // last version stored by the sink
            $table->string('status')->default('received'); // received|processing|built|dead-letter
            $table->unsignedInteger('last_processed_version')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'match_key']);
            $table->index(['status', 'file_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_files');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php // app/Models/MatchFile.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchFile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'file_version' => 'int',
            'last_processed_version' => 'int',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=MatchFileSchemaTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/*_create_match_files_table.php app/Models/MatchFile.php tests/Feature/Pipeline/MatchFileSchemaTest.php
git commit -m "feat(cloud): match_files inbox table (source of record) + model"
```

---

### Task 2: `opponents` (global, keyed on mtgo_player_id) + `UpsertOpponent`

**Files:**
- Create: `database/migrations/2026_07_01_000002_create_opponents_table.php`
- Create: `app/Models/Opponent.php`
- Create: `app/Actions/Pipeline/UpsertOpponent.php`
- Test: `tests/Feature/Pipeline/UpsertOpponentTest.php`

**Interfaces:**
- Produces: `UpsertOpponent::run(?int $mtgoPlayerId, ?string $username): Opponent` — global upsert keyed on `mtgo_player_id` when present (refreshes the display `username`, never keys on it); when the id is **null** (0.x imports carry no player id) it falls back to keying on `username`. `mtgo_player_id` is nullable with a partial unique index (`WHERE mtgo_player_id IS NOT NULL`). Consumed by `BuildMatch` (Task 10).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Pipeline/UpsertOpponentTest.php
use App\Actions\Pipeline\UpsertOpponent;
use App\Models\Opponent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an opponent keyed on the stable player id', function () {
    $opp = app(UpsertOpponent::class)->run(3022021, 'anticloser');
    expect($opp->mtgo_player_id)->toBe(3022021)->and($opp->username)->toBe('anticloser');
});

it('upserts on player id and refreshes a renamed handle without duplicating', function () {
    app(UpsertOpponent::class)->run(3022021, 'anticloser');
    $renamed = app(UpsertOpponent::class)->run(3022021, 'new_handle');

    expect(Opponent::where('mtgo_player_id', 3022021)->count())->toBe(1);
    expect($renamed->username)->toBe('new_handle');
});

it('keeps an existing username when the new one is null', function () {
    app(UpsertOpponent::class)->run(3022021, 'anticloser');
    $again = app(UpsertOpponent::class)->run(3022021, null);
    expect($again->username)->toBe('anticloser');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=UpsertOpponentTest`
Expected: FAIL — table/model/action missing.

- [ ] **Step 3: Write the migration**

```php
<?php // database/migrations/2026_07_01_000002_create_opponents_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opponents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mtgo_player_id')->nullable(); // real, rename-proof key — NULLABLE (0.x imports have no player id)
            $table->string('username')->nullable();                   // display attribute; fallback identity when player id is absent
            $table->timestamps();
        });

        // Partial unique index (Postgres): only enforce uniqueness for rows that actually
        // carry a player id — 0.x-import opponents (null id) are identified by username fallback.
        DB::statement('CREATE UNIQUE INDEX opponents_mtgo_player_id_unique ON opponents (mtgo_player_id) WHERE mtgo_player_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('opponents');
    }
};
```

- [ ] **Step 4: Write the model + action**

```php
<?php // app/Models/Opponent.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Opponent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['mtgo_player_id' => 'int'];
    }
}
```

```php
<?php // app/Actions/Pipeline/UpsertOpponent.php
namespace App\Actions\Pipeline;

use App\Models\Opponent;

final class UpsertOpponent
{
    public function run(?int $mtgoPlayerId, ?string $username): Opponent
    {
        // 0.x imports have no player id — key on the username fallback instead.
        $opponent = $mtgoPlayerId !== null
            ? Opponent::firstOrNew(['mtgo_player_id' => $mtgoPlayerId])
            : Opponent::firstOrNew(['mtgo_player_id' => null, 'username' => $username]);

        if ($username !== null && $username !== '') {
            $opponent->username = $username;
        }

        $opponent->save();

        return $opponent;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=UpsertOpponentTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/*_create_opponents_table.php app/Models/Opponent.php app/Actions/Pipeline/UpsertOpponent.php tests/Feature/Pipeline/UpsertOpponentTest.php
git commit -m "feat(cloud): global opponents table + upsert keyed on mtgo_player_id"
```

---

### Task 3: `decks` + `deck_versions` migrations + models

**Files:**
- Create: `database/migrations/2026_07_01_000003_create_decks_table.php`
- Create: `database/migrations/2026_07_01_000004_create_deck_versions_table.php`
- Create: `app/Models/Deck.php`, `app/Models/DeckVersion.php` (**see Task-level note below**)
- Test: `tests/Feature/Pipeline/DeckSchemaTest.php`

**Interfaces:**
- Produces: `decks` (user-scoped, keyed on `mtgo_id` = NetDeckId) and `deck_versions` (`deck_id`, `signature`, `modified_at`). Consumed by `ResolveDeckVersion` (Task 4) and `BuildMatch` (Task 10).

> **Ownership (shared with catalog):** cloud-pipeline **OWNS** the `decks` + `deck_versions` migrations — they are authoritative and created here in the [`spec.md`](./spec.md) §3 shape (`deck_versions`: `deck_id`, `signature`, `modified_at`). The catalog plan ([`../catalog/plan.md`](../catalog/plan.md)) must **REFERENCE** these tables, never re-create them. If a legacy catalog `DeckVersion` model conflicts, namespace the pipeline model (`App\Models\Pipeline\DeckVersion`) or reconcile at integration — but do not silently repurpose or re-migrate the catalog table here, and catalog does not re-migrate these.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Pipeline/DeckSchemaTest.php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the decks table scoped by user', function () {
    expect(Schema::hasColumns('decks', [
        'id', 'user_id', 'mtgo_id', 'name', 'format', 'color_identity',
        'original_name', 'cover_id', 'archetype_id',
    ]))->toBeTrue();
});

it('creates the deck_versions table keyed on signature + modified_at', function () {
    expect(Schema::hasColumns('deck_versions', [
        'id', 'deck_id', 'signature', 'modified_at',
    ]))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=DeckSchemaTest`
Expected: FAIL — tables missing.

- [ ] **Step 3: Write the migrations**

```php
<?php // database/migrations/2026_07_01_000003_create_decks_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('mtgo_id')->nullable(); // NetDeckId
            $table->string('name')->nullable();
            $table->string('format')->nullable();
            $table->string('color_identity')->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('cover_id')->nullable();
            $table->unsignedBigInteger('archetype_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'mtgo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decks');
    }
};
```

```php
<?php // database/migrations/2026_07_01_000004_create_deck_versions_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deck_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deck_id')->constrained()->cascadeOnDelete();
            $table->string('signature'); // base64 cardlist, carried verbatim
            $table->timestamp('modified_at')->nullable(); // MTGO XML timestamp — version source
            $table->timestamps();

            $table->index(['deck_id', 'signature']);
            $table->index(['deck_id', 'modified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deck_versions');
    }
};
```

- [ ] **Step 4: Write the models**

```php
<?php // app/Models/Deck.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deck extends Model
{
    protected $guarded = [];

    public function versions(): HasMany
    {
        return $this->hasMany(DeckVersion::class);
    }
}
```

```php
<?php // app/Models/DeckVersion.php  (pipeline shape — see Task note re: catalog collision)
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeckVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['modified_at' => 'datetime'];
    }

    public function deck(): BelongsTo
    {
        return $this->belongsTo(Deck::class);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=DeckSchemaTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/*_create_decks_table.php database/migrations/*_create_deck_versions_table.php app/Models/Deck.php app/Models/DeckVersion.php tests/Feature/Pipeline/DeckSchemaTest.php
git commit -m "feat(cloud): decks + deck_versions tables (cloud owns versioning)"
```

---

### Task 4: `ResolveDeckVersion` — never-regress versioning on `modified_at`

**Files:**
- Create: `app/Actions/Pipeline/ResolveDeckVersion.php`
- Test: `tests/Feature/Pipeline/ResolveDeckVersionTest.php`

**Interfaces:**
- Consumes: a `Deck`, a `signature`, and a `modified_at` (from the contract `match.deck`).
- Produces: `ResolveDeckVersion::run(Deck $deck, string $signature, ?CarbonInterface $modifiedAt): DeckVersion` — same signature → reuse the existing version; a *different* signature with a **newer** `modified_at` → create a new version; **older or equal** `modified_at` → ignore (return the latest existing). Consumed by `BuildMatch` (Task 10).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Pipeline/ResolveDeckVersionTest.php
use App\Actions\Pipeline\ResolveDeckVersion;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeDeck(): Deck
{
    return Deck::create(['user_id' => 1, 'name' => 'Test', 'format' => 'CModern']);
}

it('reuses the version when the signature is unchanged', function () {
    $deck = makeDeck();
    $a = app(ResolveDeckVersion::class)->run($deck, 'sig-A', now());
    $b = app(ResolveDeckVersion::class)->run($deck, 'sig-A', now()->addDay());

    expect(DeckVersion::where('deck_id', $deck->id)->count())->toBe(1);
    expect($b->id)->toBe($a->id);
});

it('creates a new version when the signature changes and modified_at is newer', function () {
    $deck = makeDeck();
    app(ResolveDeckVersion::class)->run($deck, 'sig-A', now());
    $b = app(ResolveDeckVersion::class)->run($deck, 'sig-B', now()->addDay());

    expect(DeckVersion::where('deck_id', $deck->id)->count())->toBe(2);
    expect($b->signature)->toBe('sig-B');
});

it('ignores an older modified_at even if the signature differs (never regress)', function () {
    $deck = makeDeck();
    $current = app(ResolveDeckVersion::class)->run($deck, 'sig-B', now());
    $stale = app(ResolveDeckVersion::class)->run($deck, 'sig-A', now()->subDay());

    expect(DeckVersion::where('deck_id', $deck->id)->count())->toBe(1);
    expect($stale->id)->toBe($current->id); // returned latest, no regression
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ResolveDeckVersionTest`
Expected: FAIL — `ResolveDeckVersion` not defined.

- [ ] **Step 3: Implement**

```php
<?php // app/Actions/Pipeline/ResolveDeckVersion.php
namespace App\Actions\Pipeline;

use App\Models\Deck;
use App\Models\DeckVersion;
use Carbon\CarbonInterface;

final class ResolveDeckVersion
{
    /**
     * Cloud owns dedup / never-regress, keyed on the MTGO XML modified_at
     * (deterministic across machines, not a local clock).
     */
    public function run(Deck $deck, string $signature, ?CarbonInterface $modifiedAt): DeckVersion
    {
        $exact = DeckVersion::where('deck_id', $deck->id)
            ->where('signature', $signature)
            ->first();

        if ($exact !== null) {
            return $exact; // same signature → reuse
        }

        $latest = DeckVersion::where('deck_id', $deck->id)
            ->orderByDesc('modified_at')
            ->first();

        // older or equal timestamp → ignore, return the current latest (no regression)
        if ($latest !== null && $modifiedAt !== null && $latest->modified_at !== null) {
            if ($modifiedAt->lessThanOrEqualTo($latest->modified_at)) {
                return $latest;
            }
        }

        return DeckVersion::create([
            'deck_id' => $deck->id,
            'signature' => $signature,
            'modified_at' => $modifiedAt,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ResolveDeckVersionTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Pipeline/ResolveDeckVersion.php tests/Feature/Pipeline/ResolveDeckVersionTest.php
git commit -m "feat(cloud): never-regress deck versioning keyed on XML modified_at"
```

---

### Task 5: `leagues` + `tournaments` migrations + models

**Files:**
- Create: `database/migrations/2026_07_01_000005_create_leagues_table.php`
- Create: `database/migrations/2026_07_01_000006_create_tournaments_table.php`
- Create: `app/Models/League.php`, `app/Models/Tournament.php`
- Test: `tests/Feature/Pipeline/LeagueTournamentSchemaTest.php`

**Interfaces:**
- Produces: `leagues` (user-scoped, keyed on the per-season `token`) and `tournaments` (user-scoped, keyed on `mtgo_event_id`). Consumed by `BuildMatch` (Task 10) for linkage. Match rows reference them via `league_id` / `tournament_id`.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Pipeline/LeagueTournamentSchemaTest.php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the leagues table scoped by user', function () {
    expect(Schema::hasColumns('leagues', [
        'id', 'user_id', 'token', 'name', 'format', 'joined_at', 'dropped_at',
    ]))->toBeTrue();
});

it('creates the tournaments table scoped by user', function () {
    expect(Schema::hasColumns('tournaments', [
        'id', 'user_id', 'mtgo_event_id', 'name',
    ]))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=LeagueTournamentSchemaTest`
Expected: FAIL — tables missing.

- [ ] **Step 3: Write the migrations**

```php
<?php // database/migrations/2026_07_01_000005_create_leagues_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leagues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->uuid('token'); // per-season league token (repeats; grouping only, never match_key)
            $table->string('name')->nullable();
            $table->string('format')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('dropped_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leagues');
    }
};
```

```php
<?php // database/migrations/2026_07_01_000006_create_tournaments_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('mtgo_event_id');
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'mtgo_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
```

- [ ] **Step 4: Write the models**

```php
<?php // app/Models/League.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class League extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'dropped_at' => 'datetime'];
    }
}
```

```php
<?php // app/Models/Tournament.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tournament extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['mtgo_event_id' => 'int'];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=LeagueTournamentSchemaTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/*_create_leagues_table.php database/migrations/*_create_tournaments_table.php app/Models/League.php app/Models/Tournament.php tests/Feature/Pipeline/LeagueTournamentSchemaTest.php
git commit -m "feat(cloud): user-scoped leagues + tournaments tables"
```

---

### Task 6: `matches` + `games` + `game_decks` + `card_game_stats` + `game_timeline` migrations + models

**Files:**
- Create: `database/migrations/2026_07_01_000007_create_matches_table.php`
- Create: `database/migrations/2026_07_01_000008_create_games_table.php`
- Create: `database/migrations/2026_07_01_000009_create_game_decks_table.php`
- Create: `database/migrations/2026_07_01_000010_create_card_game_stats_table.php`
- Create: `database/migrations/2026_07_01_000011_create_game_timeline_table.php`
- Create: `app/Models/{MtgoMatch,Game,GameDeck,CardGameStat,GameTimelineEntry}.php`
- Test: `tests/Feature/Pipeline/MatchSchemaTest.php`

**Interfaces:**
- Produces: the full derived match record. `matches.UNIQUE(user_id, match_key)`; `game_decks.UNIQUE(game_id, is_opponent)`; `card_game_stats.UNIQUE(game_id, oracle_id, opponent)`. Consumed by `BuildMatch` (Task 10).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Pipeline/MatchSchemaTest.php
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the matches table with UNIQUE(user_id, match_key) and the derived columns', function () {
    expect(Schema::hasColumns('matches', [
        'id', 'user_id', 'mtgo_account_id', 'match_key', 'mtgo_id', 'league_token',
        'format', 'match_type', 'outcome', 'outcome_source', 'state',
        'started_at', 'ended_at', 'deck_version_id', 'league_id', 'tournament_id',
        'opponent_id', 'notes', 'imported', 'source_file_version',
    ]))->toBeTrue();
    // dropped vs 0.x — must NOT exist
    expect(Schema::hasColumn('matches', 'result'))->toBeFalse();
    expect(Schema::hasColumn('matches', 'games_won'))->toBeFalse();
    expect(Schema::hasColumn('matches', 'games_lost'))->toBeFalse();
});

it('enforces UNIQUE(user_id, match_key) on matches', function () {
    MtgoMatch::create(['user_id' => 1, 'match_key' => 'tok-1', 'outcome' => 'Unknown', 'outcome_source' => 'unknown']);
    expect(fn () => MtgoMatch::create(['user_id' => 1, 'match_key' => 'tok-1', 'outcome' => 'Win', 'outcome_source' => 'resolved']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('creates games, game_decks (UNIQUE game_id+is_opponent), card_game_stats, game_timeline', function () {
    expect(Schema::hasColumns('games', [
        'id', 'match_id', 'mtgo_id', 'won', 'started_at', 'ended_at', 'turn_count',
        'local_on_play', 'local_mulligans', 'opp_mulligans', 'local_dice', 'opp_dice',
        'local_instance', 'opp_instance',
    ]))->toBeTrue();
    expect(Schema::hasColumn('games', 'starting_hand_size'))->toBeFalse(); // dropped
    expect(Schema::hasColumns('game_decks', ['id', 'game_id', 'is_opponent', 'deck_json']))->toBeTrue();
    expect(Schema::hasColumns('card_game_stats', [
        'id', 'game_id', 'oracle_id', 'quantity', 'kept', 'seen', 'played', 'won',
        'is_postboard', 'sided_out', 'opponent', 'pregame_revealed', 'pregame_played',
        'kicked', 'flashback', 'madness', 'evoked', 'activated',
    ]))->toBeTrue();
    expect(Schema::hasColumn('card_game_stats', 'cast'))->toBeFalse(); // dropped (dup of played)
    expect(Schema::hasColumns('game_timeline', ['id', 'game_id', 'action', 'timestamp', 'player', 'context']))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=MatchSchemaTest`
Expected: FAIL — tables missing.

- [ ] **Step 3: Write the migrations**

```php
<?php // database/migrations/2026_07_01_000007_create_matches_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('mtgo_account_id')->nullable();
            $table->uuid('match_key'); // = MatchToken uuid
            $table->unsignedBigInteger('mtgo_id')->nullable(); // MatchID int — attribute only
            $table->uuid('league_token')->nullable();          // groups league runs, never a key
            $table->string('format')->nullable();
            $table->string('match_type')->nullable();
            $table->string('outcome')->default('Unknown');        // Win|Loss|Draw|Unknown
            $table->string('outcome_source')->default('unknown'); // resolved|manual|unknown
            $table->string('state')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('deck_version_id')->nullable();
            $table->foreignId('league_id')->nullable();
            $table->foreignId('tournament_id')->nullable();
            $table->foreignId('opponent_id')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('imported')->default(false);
            $table->unsignedInteger('source_file_version')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'match_key']);
            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
```

```php
<?php // database/migrations/2026_07_01_000008_create_games_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->unsignedBigInteger('mtgo_id')->nullable(); // GameID
            $table->boolean('won')->nullable();                // null = unknown/abandoned
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('turn_count')->nullable();
            $table->boolean('local_on_play')->nullable();
            $table->unsignedInteger('local_mulligans')->nullable();
            $table->unsignedInteger('opp_mulligans')->nullable();
            $table->integer('local_dice')->nullable();
            $table->integer('opp_dice')->nullable();
            $table->unsignedBigInteger('local_instance')->nullable();
            $table->unsignedBigInteger('opp_instance')->nullable();
            $table->timestamps();

            $table->unique(['match_id', 'mtgo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
```

```php
<?php // database/migrations/2026_07_01_000009_create_game_decks_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_decks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->boolean('is_opponent')->default(false);
            $table->jsonb('deck_json')->nullable(); // Postgres jsonb — { signature: ... } per contract
            $table->timestamps();

            $table->unique(['game_id', 'is_opponent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_decks');
    }
};
```

```php
<?php // database/migrations/2026_07_01_000010_create_card_game_stats_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_game_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->string('oracle_id');
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('kept')->default(0);
            $table->unsignedInteger('seen')->default(0);
            $table->unsignedInteger('played')->default(0);
            $table->boolean('won')->nullable();
            $table->boolean('is_postboard')->default(false);
            $table->boolean('sided_out')->default(false);
            $table->boolean('opponent')->default(false);
            $table->boolean('pregame_revealed')->default(false);
            $table->boolean('pregame_played')->default(false);
            $table->unsignedInteger('kicked')->default(0);
            $table->unsignedInteger('flashback')->default(0);
            $table->unsignedInteger('madness')->default(0);
            $table->unsignedInteger('evoked')->default(0);
            $table->unsignedInteger('activated')->default(0);
            $table->timestamps();

            $table->unique(['game_id', 'oracle_id', 'opponent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_game_stats');
    }
};
```

```php
<?php // database/migrations/2026_07_01_000011_create_game_timeline_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_timeline', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->string('action')->nullable();
            $table->timestamp('timestamp')->nullable();
            $table->string('player')->nullable();
            $table->jsonb('context')->nullable(); // Postgres jsonb — structured per-action context
            $table->timestamps();

            $table->index(['game_id', 'timestamp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_timeline');
    }
};
```

- [ ] **Step 4: Write the models** (all `protected $guarded = [];`; `MtgoMatch` maps to the `matches` table and never uses the reserved name `Match`)

```php
<?php // app/Models/MtgoMatch.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MtgoMatch extends Model
{
    protected $table = 'matches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'imported' => 'bool',
            'source_file_version' => 'int',
        ];
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class, 'match_id');
    }

    public function opponent(): BelongsTo
    {
        return $this->belongsTo(Opponent::class);
    }
}
```

```php
<?php // app/Models/Game.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'won' => 'bool', 'started_at' => 'datetime', 'ended_at' => 'datetime',
            'local_on_play' => 'bool',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MtgoMatch::class, 'match_id');
    }

    public function cardStats(): HasMany
    {
        return $this->hasMany(CardGameStat::class);
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(GameTimelineEntry::class);
    }

    public function decks(): HasMany
    {
        return $this->hasMany(GameDeck::class);
    }
}
```

Create `GameDeck` (casts `is_opponent` bool, `deck_json` array), `CardGameStat` (bool casts on the flags), and `GameTimelineEntry` (`protected $table = 'game_timeline'`, `timestamp` datetime cast) the same way.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=MatchSchemaTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/*_create_matches_table.php database/migrations/*_create_games_table.php database/migrations/*_create_game_decks_table.php database/migrations/*_create_card_game_stats_table.php database/migrations/*_create_game_timeline_table.php app/Models/{MtgoMatch,Game,GameDeck,CardGameStat,GameTimelineEntry}.php tests/Feature/Pipeline/MatchSchemaTest.php
git commit -m "feat(cloud): matches/games/game_decks/card_game_stats/game_timeline schema"
```

---

### Task 7: `MatchFileData` DTO (parse the `{match}.json` contract)

**Files:**
- Create: `app/Data/MatchFile/MatchFileData.php`, `MatchData.php`, `GameData.php`, `CardStatData.php`, `DeckData.php`, `OpponentData.php`, `LeagueData.php`, `TournamentData.php`, `TimelineEntryData.php`
- Test: `tests/Unit/Data/MatchFileDataTest.php`

**Interfaces:**
- Produces: `MatchFileData::from(array $json)` — a Spatie `Data` tree mirroring [`../contract/spec.md`](../contract/spec.md) (envelope + `match{}` + nested `games[]` / `card_stats[]` / `timeline[]`). Tolerant of the **sparse variant** (`games: []`, missing `card_stats`/`timeline`, `league`/`tournament` null). Consumed by `BuildMatch` (Task 10). This is the read-side mirror of the client's `ProjectedMatchData` — same field names, opposite direction.

- [ ] **Step 1: Write the failing test (full + sparse)**

```php
<?php // tests/Unit/Data/MatchFileDataTest.php
use App\Data\MatchFile\MatchFileData;

it('parses the full {match}.json envelope + match shape', function () {
    $data = MatchFileData::from([
        'schema_version' => 1,
        'client_version' => '1.0.0',
        'match_key' => '95f4d09f-7d8f-4e14-aafd-1abed0415ea8',
        'compiled_at' => '2026-07-01T00:00:00Z',
        'file_version' => 7,
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
            'notes' => null,
            'opponent' => ['mtgo_player_id' => 3022021, 'username' => 'anticloser'],
            'deck' => ['mtgo_id' => 42, 'name' => 'UBR', 'format' => 'CModern', 'color_identity' => 'UBR', 'modified_at' => '2026-06-30T00:00:00Z', 'signature' => 'c2ln'],
            'league' => null,
            'tournament' => null,
            'games' => [[
                'mtgo_id' => 954965154, 'won' => true, 'started_at' => null, 'ended_at' => null,
                'turn_count' => 9, 'local_on_play' => true,
                'local_mulligans' => 1, 'opp_mulligans' => 0, 'local_dice' => 6, 'opp_dice' => 3,
                'local_instance' => 111, 'opp_instance' => 222,
                'local_deck' => ['signature' => 'c2ln'], 'opponent_deck' => ['signature' => 'b3Bw'],
                'card_stats' => [[
                    'oracle_id' => 'abc', 'opponent' => false, 'quantity' => 4, 'kept' => 1, 'seen' => 2,
                    'played' => 1, 'won' => true, 'is_postboard' => false, 'sided_out' => false,
                    'pregame_revealed' => false, 'pregame_played' => false,
                    'kicked' => 0, 'flashback' => 0, 'madness' => 0, 'evoked' => 0, 'activated' => 0,
                ]],
                'timeline' => [['action' => 'play', 'timestamp' => '2026-07-01T00:01:00Z', 'player' => 'Pro_MTG', 'context' => 'land']],
            ]],
            'opponent_archetype' => ['uuid' => 'x', 'name' => 'Burn', 'confidence' => 0.82],
        ],
    ]);

    expect($data->match_key)->toBe($data->match->token);
    expect($data->match->opponent->mtgo_player_id)->toBe(3022021);
    expect($data->match->games[0]->card_stats[0]->oracle_id)->toBe('abc');
    expect($data->match->games[0]->timeline[0]->action)->toBe('play');
});

it('parses the sparse 0.x import variant (no games / stats / timeline)', function () {
    $data = MatchFileData::from([
        'schema_version' => 1, 'client_version' => '1.0.0',
        'match_key' => 'tok-import', 'compiled_at' => '2026-07-01T00:00:00Z',
        'file_version' => 1, 'imported' => true,
        'mtgo_username' => 'Pro_MTG', 'mtgo_player_id' => 147160,
        'match' => [
            'token' => 'tok-import', 'mtgo_id' => 1, 'format' => 'CModern', 'match_type' => 'League',
            'outcome' => 'Win', 'outcome_source' => 'resolved', 'state' => 'Complete',
            'started_at' => null, 'ended_at' => null,
            'opponent' => ['mtgo_player_id' => 3022021, 'username' => null],
            'games' => [],
        ],
    ]);

    expect($data->imported)->toBeTrue();
    expect($data->match->games)->toBe([]);
    expect($data->match->deck)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=MatchFileDataTest`
Expected: FAIL — DTO classes not defined.

- [ ] **Step 3: Implement the DTO tree**

Create the `Data` classes with typed, promoted properties matching the contract. Make every field the sparse variant omits **nullable with a default** (`deck`, `league`, `tournament`, `opponent_archetype`, `notes`, all game/stat fields the sparse variant lacks). Use `#[DataCollectionOf(...)]` / typed arrays for `games`, `card_stats`, `timeline`. `mtgo_player_id` parses as `int` (contract sends it as a string — cast in the DTO). Example root:

```php
<?php // app/Data/MatchFile/MatchFileData.php
namespace App\Data\MatchFile;

use Spatie\LaravelData\Data;

class MatchFileData extends Data
{
    public function __construct(
        public int $schema_version,
        public string $client_version,
        public string $match_key,
        public string $compiled_at,
        public int $file_version,
        public bool $imported,
        public ?string $mtgo_username,
        public ?int $mtgo_player_id,
        public MatchData $match,
    ) {}
}
```

`MatchData` holds the scalar match fields + nested `OpponentData $opponent`, `?DeckData $deck = null`, `?LeagueData $league = null`, `?TournamentData $tournament = null`, and `/** @var GameData[] */ public array $games = []`. `GameData` holds `/** @var CardStatData[] */ public array $card_stats = []` and `/** @var TimelineEntryData[] */ public array $timeline = []`, plus `?DeckData`-like `local_deck`/`opponent_deck` (or a small `GameDeckData` with just `signature`).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=MatchFileDataTest`
Expected: PASS (both full + sparse cases).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/MatchFile tests/Unit/Data/MatchFileDataTest.php
git commit -m "feat(cloud): MatchFileData DTO parsing the {match}.json contract (full + sparse)"
```

---

### Task 8: Dumb sink — `StoreMatchFile` action (store blob + upsert + enqueue)

**Files:**
- Create: `app/Actions/Pipeline/StoreMatchFile.php`
- Test: `tests/Feature/Pipeline/StoreMatchFileTest.php`

**Interfaces:**
- Consumes: `int $userId`, `string $matchKey`, `int $fileVersion`, `string $rawJson` (the untouched request body).
- Produces: `StoreMatchFile::run(int $userId, string $matchKey, int $fileVersion, string $rawJson): MatchFile` — writes the blob to the `s3` disk at `matches/{userId}/{matchKey}/{fileVersion}.json`, upserts the `match_files` row on `(user_id, match_key)` (bumps `file_version`, resets `status` to `received`), and dispatches `BuildMatchFromFile`. **Never parses/validates content.**

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Pipeline/StoreMatchFileTest.php
use App\Actions\Pipeline\StoreMatchFile;
use App\Jobs\BuildMatchFromFile;
use App\Models\MatchFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3');
    Bus::fake();
});

it('stores the blob, upserts match_files, and enqueues the build job', function () {
    $row = app(StoreMatchFile::class)->run(1, 'tok-1', 3, '{"any":"bytes"}');

    Storage::disk('s3')->assertExists('matches/1/tok-1/3.json');
    expect($row->file_version)->toBe(3)->and($row->status)->toBe('received');
    Bus::assertDispatched(BuildMatchFromFile::class);
});

it('upserts (does not duplicate) on the same user + match_key and bumps file_version', function () {
    app(StoreMatchFile::class)->run(1, 'tok-1', 1, '{"v":1}');
    $second = app(StoreMatchFile::class)->run(1, 'tok-1', 2, '{"v":2}');

    expect(MatchFile::where('user_id', 1)->where('match_key', 'tok-1')->count())->toBe(1);
    expect($second->file_version)->toBe(2);
    Storage::disk('s3')->assertExists('matches/1/tok-1/2.json');
});

it('stores content verbatim even when it is not valid json (never validates)', function () {
    $row = app(StoreMatchFile::class)->run(1, 'tok-1', 1, 'not-json-at-all');

    expect(Storage::disk('s3')->get('matches/1/tok-1/1.json'))->toBe('not-json-at-all');
    expect($row->status)->toBe('received');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=StoreMatchFileTest`
Expected: FAIL — action not defined (and `BuildMatchFromFile` may be missing; a stub is fine at this point — Task 9 fleshes it out).

- [ ] **Step 3: Implement**

```php
<?php // app/Actions/Pipeline/StoreMatchFile.php
namespace App\Actions\Pipeline;

use App\Jobs\BuildMatchFromFile;
use App\Models\MatchFile;
use Illuminate\Support\Facades\Storage;

final class StoreMatchFile
{
    /**
     * Dumb sink: store the blob verbatim, upsert the inbox row, enqueue the build.
     * Never parses or validates content — the write-path cannot fail on content.
     */
    public function run(int $userId, string $matchKey, int $fileVersion, string $rawJson): MatchFile
    {
        $path = "matches/{$userId}/{$matchKey}/{$fileVersion}.json";

        Storage::disk('s3')->put($path, $rawJson);

        $file = MatchFile::updateOrCreate(
            ['user_id' => $userId, 'match_key' => $matchKey],
            [
                'object_path' => $path,
                'file_version' => $fileVersion,
                'status' => 'received',
                'error' => null,
                'received_at' => now(),
            ],
        );

        BuildMatchFromFile::dispatch($file->id);

        return $file;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=StoreMatchFileTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Pipeline/StoreMatchFile.php tests/Feature/Pipeline/StoreMatchFileTest.php
git commit -m "feat(cloud): dumb sink StoreMatchFile action (store blob + upsert inbox + enqueue)"
```

---

### Task 9: Sink endpoint — auth + ownership form request + controller (always 200)

**Files:**
- Create: `app/Http/Requests/StoreMatchFileRequest.php`
- Create: `app/Http/Controllers/Sink/StoreMatchFileController.php`
- Modify: `routes/api.php` (register the authenticated route)
- Test: `tests/Feature/Sink/StoreMatchFileEndpointTest.php`

**Interfaces:**
- Consumes: an authenticated request (Bearer token → `user_id`, per [`../cloud-auth/spec.md`](../cloud-auth/spec.md)) carrying the `{match}.json` body.
- Produces: `POST /api/matches` → validates **auth + ownership only** (the body's `match_key`/`file_version` are present and the `mtgo_player_id`/`user` resolve to the caller — the server never trusts client scoping), calls `StoreMatchFile`, returns **200** with `{ accepted: true, match_key, file_version }`. Rejects only on failed auth/ownership.

> **Auth grounding:** v1 is **Passport across the board** — this task guards the route with `auth:api` (Passport Bearer tokens, per [`../cloud-auth/spec.md`](../cloud-auth/spec.md)). The old Sanctum/device-key model stays with the frozen 0.x API, not here. The test uses `actingAs($user)` so it is guard-agnostic. Ownership = the resolved user owns the `user_id` namespace; the client cannot write another user's files.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Sink/StoreMatchFileEndpointTest.php
use App\Models\MatchFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3');
    Bus::fake();
});

function contractBody(string $matchKey = 'tok-1', int $version = 1): array
{
    return [
        'schema_version' => 1, 'client_version' => '1.0.0', 'match_key' => $matchKey,
        'compiled_at' => '2026-07-01T00:00:00Z', 'file_version' => $version, 'imported' => false,
        'mtgo_username' => 'Pro_MTG', 'mtgo_player_id' => 147160,
        'match' => ['token' => $matchKey, 'outcome' => 'Win', 'outcome_source' => 'resolved',
            'opponent' => ['mtgo_player_id' => 3022021, 'username' => 'anticloser'], 'games' => []],
    ];
}

it('rejects an unauthenticated push', function () {
    $this->postJson('/api/matches', contractBody())->assertUnauthorized();
});

it('accepts an authenticated push and always returns 200', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/matches', contractBody('tok-1', 3))
        ->assertOk()
        ->assertJson(['accepted' => true, 'match_key' => 'tok-1', 'file_version' => 3]);

    expect(MatchFile::where('user_id', $user->id)->where('match_key', 'tok-1')->exists())->toBeTrue();
    Storage::disk('s3')->assertExists("matches/{$user->id}/tok-1/3.json");
});

it('acks 200 even for a garbage body (sink never validates content)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/matches', ['match_key' => 'tok-2', 'file_version' => 1, 'junk' => str_repeat('x', 50)])
        ->assertOk();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=StoreMatchFileEndpointTest`
Expected: FAIL — route/controller missing.

- [ ] **Step 3: Implement the form request** (auth + ownership + presence of the two keys the sink needs; nothing about content shape)

```php
<?php // app/Http/Requests/StoreMatchFileRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMatchFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership: the caller may only write under their own user namespace.
        // The user_id is taken from the token, never from the body.
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Auth/ownership only — NOT content/schema. The sink stores the body verbatim.
        return [
            'match_key' => ['required', 'string'],
            'file_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
```

- [ ] **Step 4: Implement the controller**

```php
<?php // app/Http/Controllers/Sink/StoreMatchFileController.php
namespace App\Http\Controllers\Sink;

use App\Actions\Pipeline\StoreMatchFile;
use App\Http\Requests\StoreMatchFileRequest;
use Illuminate\Http\JsonResponse;

class StoreMatchFileController
{
    public function __invoke(StoreMatchFileRequest $request, StoreMatchFile $store): JsonResponse
    {
        $matchKey = (string) $request->input('match_key');
        $fileVersion = (int) $request->input('file_version');

        // user_id from the token — the server never trusts the client for scoping.
        $file = $store->run(
            userId: (int) $request->user()->id,
            matchKey: $matchKey,
            fileVersion: $fileVersion,
            rawJson: $request->getContent(),
        );

        return response()->json([
            'accepted' => true,
            'match_key' => $file->match_key,
            'file_version' => $file->file_version,
        ]);
    }
}
```

Register in `routes/api.php` under the Passport authenticated guard (`auth:api` — Passport is the v1 guard across the board, per [`../cloud-auth/spec.md`](../cloud-auth/spec.md)):

```php
Route::post('/matches', \App\Http\Controllers\Sink\StoreMatchFileController::class)
    ->middleware('auth:api') // Passport Bearer token (cloud-auth)
    ->name('matches.store');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=StoreMatchFileEndpointTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/StoreMatchFileRequest.php app/Http/Controllers/Sink/StoreMatchFileController.php routes/api.php tests/Feature/Sink/StoreMatchFileEndpointTest.php
git commit -m "feat(cloud): sink endpoint (auth + ownership only, always 200)"
```

---

### Task 10: `BuildMatch` action — shared projection → idempotent schema upsert

**Files:**
- Create: `app/Actions/Pipeline/BuildMatch.php`
- Test: `tests/Feature/Pipeline/BuildMatchTest.php`

**Interfaces:**
- Consumes: `MatchFileData` (Task 7), `UpsertOpponent` (Task 2), `ResolveDeckVersion` (Task 4), the schema (Tasks 1–6).
- Produces: `BuildMatch::run(int $userId, MatchFileData $data): MtgoMatch` — inside a DB transaction, idempotently upserts `matches` on `(user_id, match_key)`, replaces its `games` / `game_decks` / `card_game_stats` / `game_timeline` from the file, upserts `opponents` + `decks`/`deck_versions` + `leagues`/`tournaments`, and links FKs. Same file twice = same DB state. Bakes `outcome` + `outcome_source` straight from the file (so `manual` survives). **This is the same event→match projection the client compiler produces — reused/ported here to consume the contract rather than re-derive from logs.** Consumed by the worker (Task 11) and re-derivation (Task 12).

- [ ] **Step 1: Write the failing test (idempotency + sparse + manual outcome)**

```php
<?php // tests/Feature/Pipeline/BuildMatchTest.php
use App\Actions\Pipeline\BuildMatch;
use App\Data\MatchFile\MatchFileData;
use App\Models\CardGameStat;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fullMatchData(int $version = 1): MatchFileData
{
    return MatchFileData::from([
        'schema_version' => 1, 'client_version' => '1.0.0', 'match_key' => 'tok-1',
        'compiled_at' => '2026-07-01T00:00:00Z', 'file_version' => $version, 'imported' => false,
        'mtgo_username' => 'Pro_MTG', 'mtgo_player_id' => 147160,
        'match' => [
            'token' => 'tok-1', 'mtgo_id' => 285753048, 'format' => 'CModern', 'match_type' => 'League',
            'outcome' => 'Win', 'outcome_source' => 'resolved', 'state' => 'Complete',
            'started_at' => '2026-07-01T00:00:00Z', 'ended_at' => '2026-07-01T00:10:00Z',
            'opponent' => ['mtgo_player_id' => 3022021, 'username' => 'anticloser'],
            'deck' => ['mtgo_id' => 42, 'name' => 'UBR', 'format' => 'CModern', 'color_identity' => 'UBR', 'modified_at' => '2026-06-30T00:00:00Z', 'signature' => 'c2ln'],
            'games' => [[
                'mtgo_id' => 954965154, 'won' => true, 'turn_count' => 9, 'local_on_play' => true,
                'local_mulligans' => 1, 'opp_mulligans' => 0, 'local_dice' => 6, 'opp_dice' => 3,
                'local_instance' => 111, 'opp_instance' => 222,
                'local_deck' => ['signature' => 'c2ln'], 'opponent_deck' => ['signature' => 'b3Bw'],
                'card_stats' => [['oracle_id' => 'abc', 'opponent' => false, 'quantity' => 4, 'kept' => 1, 'seen' => 2, 'played' => 1, 'won' => true]],
                'timeline' => [['action' => 'play', 'timestamp' => '2026-07-01T00:01:00Z', 'player' => 'Pro_MTG', 'context' => 'land']],
            ]],
        ],
    ]);
}

it('builds a full match with games, stats, timeline, opponent + deck version', function () {
    $match = app(BuildMatch::class)->run(1, fullMatchData());

    expect($match->match_key)->toBe('tok-1')->and($match->outcome)->toBe('Win');
    expect($match->games)->toHaveCount(1);
    expect(Opponent::where('mtgo_player_id', 3022021)->exists())->toBeTrue();
    expect($match->opponent_id)->not->toBeNull();
    expect($match->deck_version_id)->not->toBeNull();
    expect(CardGameStat::count())->toBe(1);
    expect($match->games->first()->timeline)->toHaveCount(1);
});

it('is idempotent — same file twice yields one match and one game set', function () {
    app(BuildMatch::class)->run(1, fullMatchData(1));
    app(BuildMatch::class)->run(1, fullMatchData(1));

    expect(MtgoMatch::where('user_id', 1)->where('match_key', 'tok-1')->count())->toBe(1);
    expect(Game::count())->toBe(1);
    expect(CardGameStat::count())->toBe(1);
});

it('builds a match-only record from the sparse (0.x import) variant', function () {
    $data = MatchFileData::from([
        'schema_version' => 1, 'client_version' => '1.0.0', 'match_key' => 'tok-import',
        'compiled_at' => '2026-07-01T00:00:00Z', 'file_version' => 1, 'imported' => true,
        'mtgo_username' => 'Pro_MTG', 'mtgo_player_id' => 147160,
        'match' => ['token' => 'tok-import', 'outcome' => 'Win', 'outcome_source' => 'resolved',
            'opponent' => ['mtgo_player_id' => 3022021, 'username' => null], 'games' => []],
    ]);

    $match = app(BuildMatch::class)->run(1, $data);

    expect($match->imported)->toBeTrue();
    expect($match->games)->toHaveCount(0);
    expect($match->deck_version_id)->toBeNull();
});

it('preserves a manual outcome from the file', function () {
    $data = fullMatchData();
    $data->match->outcome = 'Loss';
    $data->match->outcome_source = 'manual';

    $match = app(BuildMatch::class)->run(1, $data);

    expect($match->outcome)->toBe('Loss')->and($match->outcome_source)->toBe('manual');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=BuildMatchTest`
Expected: FAIL — `BuildMatch` not defined.

- [ ] **Step 3: Implement**

```php
<?php // app/Actions/Pipeline/BuildMatch.php
namespace App\Actions\Pipeline;

use App\Data\MatchFile\MatchFileData;
use App\Models\Deck;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Support\Facades\DB;

final class BuildMatch
{
    public function __construct(
        private UpsertOpponent $upsertOpponent,
        private ResolveDeckVersion $resolveDeckVersion,
    ) {}

    /**
     * Same event→match projection the client compiler produces, reused server-side
     * to consume the contract file. Idempotent upsert on (user_id, match_key).
     */
    public function run(int $userId, MatchFileData $data): MtgoMatch
    {
        return DB::transaction(function () use ($userId, $data): MtgoMatch {
            $m = $data->match;

            $opponentId = $m->opponent !== null
                ? $this->upsertOpponent->run((int) $m->opponent->mtgo_player_id, $m->opponent->username)->id
                : null;

            $deckVersionId = $this->resolveDeckVersionId($userId, $data);
            $leagueId = $this->resolveLeagueId($userId, $data);
            $tournamentId = $this->resolveTournamentId($userId, $data);

            $match = MtgoMatch::updateOrCreate(
                ['user_id' => $userId, 'match_key' => $m->token],
                [
                    'mtgo_id' => $m->mtgo_id,
                    'league_token' => $m->league->token ?? null,
                    'format' => $m->format,
                    'match_type' => $m->match_type,
                    'outcome' => $m->outcome,
                    'outcome_source' => $m->outcome_source, // manual survives re-derivation
                    'state' => $m->state,
                    'started_at' => $m->started_at,
                    'ended_at' => $m->ended_at,
                    'deck_version_id' => $deckVersionId,
                    'league_id' => $leagueId,
                    'tournament_id' => $tournamentId,
                    'opponent_id' => $opponentId,
                    'notes' => $m->notes,
                    'imported' => $data->imported,
                    'source_file_version' => $data->file_version,
                ],
            );

            // Idempotent replace of the derived children.
            $match->games()->delete(); // cascades game_decks / card_game_stats / game_timeline

            foreach ($m->games as $g) {
                $game = $match->games()->create([
                    'mtgo_id' => $g->mtgo_id,
                    'won' => $g->won,
                    'started_at' => $g->started_at,
                    'ended_at' => $g->ended_at,
                    'turn_count' => $g->turn_count,
                    'local_on_play' => $g->local_on_play,
                    'local_mulligans' => $g->local_mulligans,
                    'opp_mulligans' => $g->opp_mulligans,
                    'local_dice' => $g->local_dice,
                    'opp_dice' => $g->opp_dice,
                    'local_instance' => $g->local_instance,
                    'opp_instance' => $g->opp_instance,
                ]);

                if ($g->local_deck !== null) {
                    $game->decks()->create(['is_opponent' => false, 'deck_json' => ['signature' => $g->local_deck->signature]]);
                }
                if ($g->opponent_deck !== null) {
                    $game->decks()->create(['is_opponent' => true, 'deck_json' => ['signature' => $g->opponent_deck->signature]]);
                }

                foreach ($g->card_stats as $s) {
                    $game->cardStats()->create((array) $s->toArray());
                }
                foreach ($g->timeline as $t) {
                    $game->timeline()->create((array) $t->toArray());
                }
            }

            return $match->fresh(['games.timeline', 'games.cardStats']);
        });
    }

    private function resolveDeckVersionId(int $userId, MatchFileData $data): ?int
    {
        $deck = $data->match->deck;
        if ($deck === null) {
            return null;
        }

        $model = Deck::updateOrCreate(
            ['user_id' => $userId, 'mtgo_id' => $deck->mtgo_id],
            ['name' => $deck->name, 'format' => $deck->format, 'color_identity' => $deck->color_identity],
        );

        return $this->resolveDeckVersion->run($model, $deck->signature, $deck->modified_at)->id;
    }

    private function resolveLeagueId(int $userId, MatchFileData $data): ?int
    {
        $league = $data->match->league;
        if ($league === null) {
            return null;
        }

        return League::updateOrCreate(
            ['user_id' => $userId, 'token' => $league->token],
            ['name' => $league->name, 'format' => $league->format, 'joined_at' => $league->joined_at, 'dropped_at' => $league->dropped_at],
        )->id;
    }

    private function resolveTournamentId(int $userId, MatchFileData $data): ?int
    {
        $tournament = $data->match->tournament;
        if ($tournament === null) {
            return null;
        }

        return Tournament::updateOrCreate(
            ['user_id' => $userId, 'mtgo_event_id' => $tournament->mtgo_event_id],
            ['name' => $tournament->name],
        )->id;
    }
}
```

(Adjust `deck->modified_at` to a `CarbonInterface` — cast it in `DeckData` — and ensure the `card_stats` / `timeline` `toArray()` keys line up with the table columns; drop any contract-only keys like `opponent_archetype`, which the worker re-derives separately.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=BuildMatchTest`
Expected: PASS (full, idempotent, sparse, manual-outcome cases all green).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Pipeline/BuildMatch.php tests/Feature/Pipeline/BuildMatchTest.php
git commit -m "feat(cloud): BuildMatch shared projection -> idempotent schema upsert"
```

---

### Task 11: Build worker job + `match.logged` after commit + dead-letter

**Files:**
- Create: `app/Jobs/BuildMatchFromFile.php`
- Create: `app/Events/MatchLogged.php`
- Test: `tests/Feature/Pipeline/BuildMatchFromFileTest.php`

**Interfaces:**
- Consumes: a `match_files.id`, the blob on the `s3` disk, `MatchFileData` (7), `BuildMatch` (10).
- Produces: `BuildMatchFromFile` (queued) — reads `{match}.json` from Spaces, parses to `MatchFileData`, calls `BuildMatch` inside the DB transaction, marks `match_files.status = 'built'` + `last_processed_version`, and **after the commit** dispatches `MatchLogged` (`{ matchKey, version }`). Malformed JSON / unparseable file / build exception → `status = 'dead-letter'` + `error`, **no `MatchLogged`**, blob retained. Re-running is safe (idempotent). Emitted signal is thin (see [`../cloud-api/spec.md`](../cloud-api/spec.md)).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Pipeline/BuildMatchFromFileTest.php
use App\Events\MatchLogged;
use App\Jobs\BuildMatchFromFile;
use App\Models\MatchFile;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('s3'));

function inboxFileWith(string $json, int $version = 1): MatchFile
{
    Storage::disk('s3')->put("matches/1/tok-1/{$version}.json", $json);

    return MatchFile::create([
        'user_id' => 1, 'match_key' => 'tok-1', 'object_path' => "matches/1/tok-1/{$version}.json",
        'file_version' => $version, 'status' => 'received',
    ]);
}

it('builds the match, marks built, and emits match.logged after commit', function () {
    Event::fake([MatchLogged::class]);
    $file = inboxFileWith(json_encode(fullMatchContract('tok-1', 3)), 3);

    (new BuildMatchFromFile($file->id))->handle();

    expect(MtgoMatch::where('match_key', 'tok-1')->exists())->toBeTrue();
    expect($file->fresh()->status)->toBe('built');
    expect($file->fresh()->last_processed_version)->toBe(3);
    Event::assertDispatched(MatchLogged::class, fn ($e) => $e->matchKey === 'tok-1' && $e->version === 3);
});

it('dead-letters on malformed json without losing the file or emitting the event', function () {
    Event::fake([MatchLogged::class]);
    $file = inboxFileWith('this is not json', 1);

    (new BuildMatchFromFile($file->id))->handle();

    expect($file->fresh()->status)->toBe('dead-letter');
    expect($file->fresh()->error)->not->toBeNull();
    expect(MtgoMatch::count())->toBe(0);
    Storage::disk('s3')->assertExists($file->object_path); // retained for later re-derivation
    Event::assertNotDispatched(MatchLogged::class);
});

it('is idempotent when the same file is processed twice', function () {
    Event::fake();
    $file = inboxFileWith(json_encode(fullMatchContract('tok-1', 1)), 1);

    (new BuildMatchFromFile($file->id))->handle();
    (new BuildMatchFromFile($file->id))->handle();

    expect(MtgoMatch::where('match_key', 'tok-1')->count())->toBe(1);
});
```

Add a `fullMatchContract(string $key, int $version): array` helper to `tests/Pest.php` (the same shape as `fullMatchData()` from Task 10, returned as a plain array).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=BuildMatchFromFileTest`
Expected: FAIL — job/event not defined.

- [ ] **Step 3: Implement the event**

```php
<?php // app/Events/MatchLogged.php
namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class MatchLogged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $userId,
        public string $matchKey,
        public int $version,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("user.{$this->userId}"); // per-user authenticated channel
    }

    public function broadcastAs(): string
    {
        return 'match.logged';
    }

    /** @return array<string, mixed> Thin signal only — never the match data. */
    public function broadcastWith(): array
    {
        return ['matchKey' => $this->matchKey, 'version' => $this->version];
    }
}
```

- [ ] **Step 4: Implement the job**

```php
<?php // app/Jobs/BuildMatchFromFile.php
namespace App\Jobs;

use App\Actions\Pipeline\BuildMatch;
use App\Data\MatchFile\MatchFileData;
use App\Events\MatchLogged;
use App\Models\MatchFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BuildMatchFromFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $matchFileId) {}

    public function handle(BuildMatch $buildMatch): void
    {
        $file = MatchFile::find($this->matchFileId);
        if ($file === null) {
            return;
        }

        $file->update(['status' => 'processing']);

        try {
            $raw = Storage::disk('s3')->get($file->object_path);
            $decoded = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
            $data = MatchFileData::from($decoded);

            $buildMatch->run($file->user_id, $data); // transactional inside

            $file->update([
                'status' => 'built',
                'last_processed_version' => $data->file_version,
                'processed_at' => now(),
                'error' => null,
            ]);

            // After DB commit only — thin signal, never the sink.
            MatchLogged::dispatch($file->user_id, $file->match_key, $data->file_version);
        } catch (Throwable $e) {
            // Dead-letter, never data-loss: the blob is retained for a later re-derivation.
            $file->update([
                'status' => 'dead-letter',
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }
    }
}
```

(If Reverb is not yet wired, `MatchLogged` still dispatches; keep the `ShouldBroadcast` contract so it lights up when [`../cloud-api/spec.md`](../cloud-api/spec.md) lands. Do not `throw` on build failure — dead-lettering is the terminal state, so the queue does not retry-loop a poison file.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=BuildMatchFromFileTest`
Expected: PASS (build+emit, dead-letter, idempotent cases all green).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Jobs/BuildMatchFromFile.php app/Events/MatchLogged.php tests/Feature/Pipeline/BuildMatchFromFileTest.php tests/Pest.php
git commit -m "feat(cloud): build worker job (idempotent build, dead-letter, match.logged after commit)"
```

---

### Task 12: Re-derivation command — re-run the worker over all stored files

**Files:**
- Create: `app/Console/Commands/RederiveMatches.php`
- Test: `tests/Feature/Pipeline/RederiveMatchesTest.php`

**Interfaces:**
- Consumes: the `match_files` inbox, `BuildMatchFromFile` (11).
- Produces: `php artisan matches:rederive [--user=] [--match-key=] [--include-dead-letter] [--sync]` — re-enqueues `BuildMatchFromFile` for every (optionally filtered) `match_files` row. This is the [`spec.md`](./spec.md) §4 build-layer re-derivation: no client involvement, no raw logs. `--sync` runs inline (for CLI verification); default dispatches to the queue.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Pipeline/RederiveMatchesTest.php
use App\Jobs\BuildMatchFromFile;
use App\Models\MatchFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(fn () => Bus::fake());

function inboxRow(string $key, string $status = 'built'): MatchFile
{
    return MatchFile::create([
        'user_id' => 1, 'match_key' => $key, 'object_path' => "matches/1/{$key}/1.json",
        'file_version' => 1, 'status' => $status,
    ]);
}

it('re-enqueues a build job for every stored file', function () {
    inboxRow('tok-1');
    inboxRow('tok-2');

    $this->artisan('matches:rederive')->assertExitCode(0);

    Bus::assertDispatchedTimes(BuildMatchFromFile::class, 2);
});

it('can scope re-derivation to a single user', function () {
    inboxRow('tok-1');
    MatchFile::create(['user_id' => 2, 'match_key' => 'tok-x', 'object_path' => 'p', 'file_version' => 1, 'status' => 'built']);

    $this->artisan('matches:rederive', ['--user' => 1])->assertExitCode(0);

    Bus::assertDispatchedTimes(BuildMatchFromFile::class, 1);
});

it('skips dead-letter rows unless --include-dead-letter is passed', function () {
    inboxRow('tok-1', 'built');
    inboxRow('tok-2', 'dead-letter');

    $this->artisan('matches:rederive')->assertExitCode(0);
    Bus::assertDispatchedTimes(BuildMatchFromFile::class, 1);

    $this->artisan('matches:rederive', ['--include-dead-letter' => true])->assertExitCode(0);
    Bus::assertDispatchedTimes(BuildMatchFromFile::class, 3); // 1 + 2
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=RederiveMatchesTest`
Expected: FAIL — command not defined.

- [ ] **Step 3: Implement**

```php
<?php // app/Console/Commands/RederiveMatches.php
namespace App\Console\Commands;

use App\Jobs\BuildMatchFromFile;
use App\Models\MatchFile;
use Illuminate\Console\Command;

class RederiveMatches extends Command
{
    protected $signature = 'matches:rederive
        {--user= : Limit to one user_id}
        {--match-key= : Limit to one match_key}
        {--include-dead-letter : Also reprocess dead-letter rows}
        {--sync : Run each build inline instead of dispatching to the queue}';

    protected $description = 'Re-run the build worker over stored {match}.json files (build-layer re-derivation).';

    public function handle(): int
    {
        $query = MatchFile::query();

        if ($user = $this->option('user')) {
            $query->where('user_id', (int) $user);
        }
        if ($key = $this->option('match-key')) {
            $query->where('match_key', $key);
        }
        if (! $this->option('include-dead-letter')) {
            $query->where('status', '!=', 'dead-letter');
        }

        $count = 0;
        $sync = (bool) $this->option('sync');

        $query->orderBy('id')->chunkById(200, function ($files) use (&$count, $sync): void {
            foreach ($files as $file) {
                if ($sync) {
                    BuildMatchFromFile::dispatchSync($file->id);
                } else {
                    BuildMatchFromFile::dispatch($file->id);
                }
                $count++;
            }
        });

        $this->info("Re-derivation queued for {$count} file(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=RederiveMatchesTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/RederiveMatches.php tests/Feature/Pipeline/RederiveMatchesTest.php
git commit -m "feat(cloud): matches:rederive command (re-run worker over stored files)"
```

---

## Self-Review checklist (run after fleshing 1–12)

1. **Spec coverage** — every [`spec.md`](./spec.md) bullet maps to a task: §1 dumb sink = Tasks 8–9; §2 build worker (shared projection, idempotent, sparse, dead-letter, `match.logged`) = Tasks 7,10,11; §3 schema = Tasks 1–6; §4 re-derivation + manual-outcome preservation = Tasks 10 (`outcome_source` baked in), 12.
2. **Constraint audit** — `match_files.UNIQUE(user_id, match_key)` (Task 1) and `matches.UNIQUE(user_id, match_key)` (Task 6) both present; `opponents` partial unique `UNIQUE(mtgo_player_id) WHERE mtgo_player_id IS NOT NULL` with nullable id + username fallback (Task 2); `game_decks.UNIQUE(game_id,is_opponent)` + `card_game_stats.UNIQUE(game_id,oracle_id,opponent)` (Task 6); dropped 0.x columns (`result`/`games_won`/`games_lost`/`starting_hand_size`/`cast`) asserted absent (Task 6 test).
3. **Sink cannot fail on content** — Task 8/9 tests push garbage bytes and still get 200; only auth/ownership rejects.
4. **Idempotency + never-regress** — `BuildMatch` twice = one match (Task 10); `ResolveDeckVersion` ignores older `modified_at` (Task 4).
5. **`match.logged` timing** — dispatched only inside the success path after the transaction, never on dead-letter, never by the sink (Task 11 test asserts both).
6. **Placeholder scan** — no "TBD" / "handle edge cases" / "similar to Task N".
7. **Type consistency** — `MatchFileData` property + nested-DTO names identical across Tasks 7, 10, 11 (`match`, `games`, `card_stats`, `timeline`, `opponent`, `deck`, `outcome`, `outcome_source`).
8. **Identity tables not created here** — `users` / `mtgo_accounts` are referenced (FKs, `actingAs`) but owned by [`../cloud-auth/spec.md`](../cloud-auth/spec.md); the auth guard on Task 9 is `auth:api` (Passport across the board).

<!-- ref: EABlmZg6IP33fWJe2j2i8u -->
