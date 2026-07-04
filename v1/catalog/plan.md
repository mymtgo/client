# Catalog Implementation Plan — Cards, Prices, Archetypes

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Format:** existing ingestion internals are copied + re-verified against real Scryfall/Goatbots payloads (not re-authored); everything new gets complete TDD steps.

**Goal:** Stand up the v1 **catalog** in the cloud API (`/Volumes/Dev/mymtgo/api`): a consolidated `cards` table (Scryfall data + a Goatbots price representation + the MTGO CatalogID↔card mapping), scheduled server-side ingestion of both sources, the v1-shape archetype tables (`archetypes` with a nullable `user_id`, `archetype_decks`, `archetype_deck_cards`, `match_archetypes`), an archetype catalog endpoint the client mirrors locally for offline live classification, and admin-promote-to-global for owned archetypes.

**Architecture:** The cloud is the system of record. Card data comes from **Scryfall** (oracle/scryfall ids, names, types, rarity, color identity, mana cost, images); prices **and** the MTGO CatalogID→card mapping come from **Goatbots** — the mapping is load-bearing because gameplay events reference MTGO CatalogIDs, not oracle ids (see the warp-printing-divergence trap: casts logged under a different printing's CatalogID diverge from the oracle). The build worker ([`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md)) links archetypes to matches via `match_archetypes` and resolves CatalogIDs through this `cards` mapping; `decks`/`deck_versions` are shared with the worker — **this plan does not build the worker**, it builds the catalog the worker reads.

**Tech Stack:** PHP 8.3, Laravel 13, PostgreSQL, Pest v4, Pint, Spatie Laravel Data (DTOs), `halaxa/json-machine` (streaming JSON), Horizon (Redis queue), DigitalOcean Spaces (`s3` disk), `Http::fake` for external sources in tests (see [`../overview/spec.md`](../overview/spec.md) §8). All package versions per the `../api` `composer.json`. v1 cloud = a NEW Postgres database on a new host; 0.x is frozen on its own subdomain + existing DB (no server-side migration). The proven Scryfall/Goatbots ingestion + archetype logic in `../api` is **ported** into v1; the catalog schema is built fresh on the new DB.

## Global Constraints

- **All code lives in `/Volumes/Dev/mymtgo/api`** (the cloud API project). This plan file lives in the client repo docs; nothing here is implemented in the client repo.
- **`cards` is the v1 consolidated catalog table** — it supersedes the legacy `scryfall_cards` + `mtgo_ids` pair. Columns per [`spec.md`](./spec.md): `mtgo_id`, `oracle_id`, `scryfall_id`, `name`, `type`, `rarity`, `color_identity`, `mana_cost`, `image`, plus a **prices representation** (Goatbots `tix`, `updated_at` on price). The MTGO CatalogID mapping is captured in a child `card_mtgo_ids` table (a card has 1+ MTGO ids — regular + foil + reprints), never inlined, because one oracle card maps to many CatalogIDs.
- **`mtgo_id` on `cards` is the primary/current printing's CatalogID**; the full set of CatalogIDs (regular, foil, alternate printings) lives in `card_mtgo_ids` with `UNIQUE(mtgo_id)`. Lookups from gameplay events resolve **CatalogID → card** through `card_mtgo_ids`.
- **Idempotent re-ingestion is mandatory** — running either ingestion job twice over the same source yields the same rows (upsert keyed on `scryfall_id` for cards, on `mtgo_id` for the mapping). Every ingestion task asserts this explicitly.
- **Reuse the proven ingestion internals, don't re-author them.** The existing `DownloadScryfallFile` / `ProcessScryfallFile` / `ProcessScryfallCardBatch` jobs and the `MapGoatDefinitions` command already handle the hostile Scryfall/Goatbots edge cases (double-faced cards, PRM promos, set-code divergence, foil ids). Copy that logic into the v1 jobs targeting `cards` + `card_mtgo_ids`; re-verify it against real captured payloads. Do **not** invent new matching heuristics.
- **Archetype uuids are global/shared across users** (verified: the same uuid recurs across accounts' matches). Manual archetypes carry a **nullable `user_id`** (null = global; non-null = owned). Owned archetypes are admin-promotable to global.
- **`match_archetypes` uses `is_opponent` (bool), not a player id**, with `UNIQUE(match_id, is_opponent)` — max one player-archetype and one opponent-archetype per match. This replaces the 0.x `player_id` linkage.
- **Reuse code, not the DB.** These are clean v1 migrations on the NEW Postgres DB — **not** additions to the live 0.x DB (0.x is frozen on its own subdomain + existing DB). The Scryfall/Goatbots ingestion + archetype matching logic is **ported** from `../api`; the tables are built fresh (see [`../overview/spec.md`](../overview/spec.md) §6, §8).
- **Postgres schema choices:** the `archetypes` **nullable `user_id`** (null = global vs owned) is a **partial unique index** where any per-owner uniqueness is needed (`... WHERE user_id IS NOT NULL`); raw card payloads (`cards.card_data`) are `jsonb`; idempotent re-ingestion via `INSERT ... ON CONFLICT` (upsert keyed on `scryfall_id` / `mtgo_id` / `card_id`); any status/kind enums are string + `casts()`, not native PG enums.
- **Follow the repo's existing conventions exactly:** `$guarded = []` models, single-responsibility invokable Actions (never service classes for app logic), Form Request classes for validation, factories in `database/factories`, Spatie `Data` DTOs, PHP 8 constructor property promotion, explicit return types, curly braces on all control structures.
- **Tests: Pest v4, opt into `RefreshDatabase` per file** (it is commented out globally in `tests/Pest.php`). `Http::fake` all external calls (Scryfall bulk API, Goatbots zip). Use factories.
- Run `vendor/bin/pint --dirty --format agent` before finalizing any task.
- Card data is served **via the API**; the live overlay never calls it (it reads local MTGO XML). The archetype catalog **is** mirrored locally by the client for offline live classification — so its endpoint payload must be self-contained and cache-friendly.

---

## File Structure

**New (v1 catalog — all under `/Volumes/Dev/mymtgo/api`):**
- `config/services.php` (modify) — add `scryfall` + `goatbots` source URLs.
- `database/migrations/*_create_cards_table.php` — the consolidated `cards` table.
- `database/migrations/*_create_card_mtgo_ids_table.php` — CatalogID → card mapping.
- `database/migrations/*_create_card_prices_table.php` — Goatbots price representation.
- `database/migrations/*_reshape_archetypes_for_v1.php` — add `manual`, `is_fallback`, `incomplete`, `merged_into_id`, `source_match_id`, nullable `user_id` to `archetypes`.
- `database/migrations/*_create_archetype_decks_table.php`, `*_create_archetype_deck_cards_table.php`.
- `database/migrations/*_create_match_archetypes_table.php`.
- `app/Models/{Card,CardMtgoId,CardPrice,ArchetypeDeck,ArchetypeDeckCard,MatchArchetype}.php`.
- `app/Jobs/{DownloadScryfallCatalog,ProcessScryfallCatalog,ProcessScryfallCardBatch}.php` (v1 variants writing `cards`/`card_mtgo_ids`).
- `app/Jobs/IngestGoatbotsPrices.php` (prices + CatalogID mapping).
- `app/Actions/Catalog/{UpsertCardFromScryfall,MapCatalogIdToCard,ApplyGoatbotsPrice}.php`.
- `app/Actions/Archetypes/PromoteArchetypeToGlobal.php`.
- `app/Console/Commands/{ImportCatalogCards,IngestGoatbotsPrices}.php`.
- `app/Data/ArchetypeCatalogData.php` + `ArchetypeCatalogDeckData.php` — the catalog endpoint payload.
- `app/Http/Controllers/Catalog/ArchetypeCatalogController.php` — the client-mirrored endpoint.
- `app/Http/Controllers/Admin/PromoteArchetypeController.php` + `app/Http/Requests/Admin/PromoteArchetypeRequest.php`.
- Factories: `Database\Factories\{CardFactory,CardMtgoIdFactory,CardPriceFactory,ArchetypeDeckFactory,ArchetypeDeckCardFactory,MatchArchetypeFactory}`.
- `routes/console.php` (modify) — schedule both ingestion commands.
- `routes/api.php` (modify) — register the archetype catalog endpoint.
- `routes/web.php` (modify) — register the admin promote route.

**Reused as reference (copy logic, re-verify — do not edit the originals):**
- `app/Jobs/{DownloadScryfallFile,ProcessScryfallFile,ProcessScryfallCardBatch}.php` — Scryfall bulk download + streaming + card-shape parsing (double-faced, image, type split, foil ids).
- `app/Console/Commands/MapGoatDefinitions.php` — the Goatbots zip fetch + `matchScryfallCard()` heuristics (collector-number strip, DFC, PRM, set divergence).
- `app/Models/{Archetype,ScryfallCard,MtgoId}.php` — existing shapes to extend/mirror.

**Shared with the worker — REFERENCE only, never re-create (cloud-pipeline OWNS these migrations, per [`../cloud-pipeline/plan.md`](../cloud-pipeline/plan.md) / [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md) §3):**
- `decks`, `deck_versions`, `deck_version_cards`, `matches`, `opponents` — **cloud-pipeline authoritatively creates the `decks` + `deck_versions` migrations**; this plan must **depend on** them, not re-migrate them. This plan only **reads** `matches`/`opponents` for `match_archetypes` FKs and reuses `deck_versions` for archetype cardlists where already present. No catalog task recreates `decks`/`deck_versions`.

---

### Task 1: `cards` table + `Card` model + `CardFactory`

**Files:**
- Create: `database/migrations/2026_07_01_000001_create_cards_table.php`
- Create: `app/Models/Card.php`
- Create: `database/factories/CardFactory.php`
- Test: `tests/Feature/Catalog/CardTableTest.php`

**Interfaces:**
- Produces: a `cards` table + `Card` model with `mtgo_id`, `oracle_id`, `scryfall_id`, `name`, `type`, `rarity`, `color_identity`, `mana_cost`, `image`. Consumed by every later catalog task.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Catalog/CardTableTest.php

use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the cards table with the v1 catalog columns', function () {
    expect(Schema::hasTable('cards'))->toBeTrue();
    expect(Schema::hasColumns('cards', [
        'mtgo_id', 'oracle_id', 'scryfall_id', 'name', 'type',
        'rarity', 'color_identity', 'mana_cost', 'image',
    ]))->toBeTrue();
});

it('persists a card via the factory and enforces a unique scryfall_id', function () {
    $card = Card::factory()->create(['scryfall_id' => 'sc-1', 'oracle_id' => 'or-1']);

    expect($card->fresh()->name)->not->toBeNull();
    expect(fn () => Card::factory()->create(['scryfall_id' => 'sc-1']))
        ->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=CardTableTest`
Expected: FAIL — table/model/factory missing.

- [ ] **Step 3: Create the migration**

```php
<?php // database/migrations/2026_07_01_000001_create_cards_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->string('scryfall_id')->unique();
            $table->string('oracle_id')->index();
            $table->string('mtgo_id')->nullable()->index(); // primary/current printing CatalogID
            $table->string('name')->index();
            $table->string('type')->index();
            $table->string('rarity')->index();
            $table->string('color_identity')->nullable()->index();
            $table->string('mana_cost')->nullable();
            $table->string('image')->nullable();
            $table->jsonb('card_data')->nullable(); // Postgres jsonb — raw Scryfall payload, for re-derivation
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
```

- [ ] **Step 4: Create the model + factory**

```php
<?php // app/Models/Card.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Card extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'card_data' => 'array',
        ];
    }

    public function mtgoIds(): HasMany
    {
        return $this->hasMany(CardMtgoId::class);
    }

    public function price(): HasOne
    {
        return $this->hasOne(CardPrice::class);
    }
}
```

```php
<?php // database/factories/CardFactory.php

namespace Database\Factories;

use App\Models\Card;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Card> */
class CardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'scryfall_id' => fake()->uuid(),
            'oracle_id' => fake()->uuid(),
            'mtgo_id' => (string) fake()->numberBetween(10000, 999999),
            'name' => fake()->words(2, true),
            'type' => 'Creature',
            'rarity' => fake()->randomElement(['common', 'uncommon', 'rare', 'mythic']),
            'color_identity' => fake()->randomElement(['W', 'U', 'B', 'R', 'G', 'WU', 'WUBRG']),
            'mana_cost' => '{1}{R}',
            'image' => fake()->imageUrl(),
            'card_data' => ['games' => ['paper', 'mtgo']],
        ];
    }
}
```

(The `mtgoIds()` / `price()` relations reference tables built in Tasks 2 + 3; they are inert until then and harmless here.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=CardTableTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_01_000001_create_cards_table.php app/Models/Card.php database/factories/CardFactory.php tests/Feature/Catalog/CardTableTest.php
git commit -m "feat(catalog): add v1 consolidated cards table + Card model"
```

---

### Task 2: `card_mtgo_ids` mapping table + `CardMtgoId` model

**Files:**
- Create: `database/migrations/2026_07_01_000002_create_card_mtgo_ids_table.php`
- Create: `app/Models/CardMtgoId.php`
- Create: `database/factories/CardMtgoIdFactory.php`
- Test: `tests/Feature/Catalog/CardMtgoIdTest.php`

**Interfaces:**
- Produces: `card_mtgo_ids` (`card_id` FK, `value` = MTGO CatalogID, `is_foil` bool), `UNIQUE(value)`. Resolves gameplay-event CatalogIDs → `Card`. Consumed by Tasks 4, 5 and the worker.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Catalog/CardMtgoIdTest.php

use App\Models\Card;
use App\Models\CardMtgoId;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('maps a MTGO CatalogID to a card and enforces a unique value', function () {
    $card = Card::factory()->create();
    $card->mtgoIds()->create(['value' => '78901', 'is_foil' => false]);

    expect(CardMtgoId::where('value', '78901')->first()->card->is($card))->toBeTrue();
    expect(fn () => CardMtgoId::create(['card_id' => $card->id, 'value' => '78901']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('resolves a CatalogID back to the owning card (warp-printing-divergence path)', function () {
    $card = Card::factory()->create(['oracle_id' => 'warp-oracle']);
    // a divergent printing logged under a different CatalogID still resolves to the same oracle card
    $card->mtgoIds()->create(['value' => '111', 'is_foil' => false]);
    $card->mtgoIds()->create(['value' => '222', 'is_foil' => true]);

    expect(CardMtgoId::where('value', '222')->first()->card->oracle_id)->toBe('warp-oracle');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=CardMtgoIdTest`
Expected: FAIL — table/model missing.

- [ ] **Step 3: Migration + model + factory**

```php
<?php // database/migrations/2026_07_01_000002_create_card_mtgo_ids_table.php

use App\Models\Card;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_mtgo_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Card::class)->constrained()->cascadeOnDelete();
            $table->string('value')->unique(); // MTGO CatalogID
            $table->boolean('is_foil')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_mtgo_ids');
    }
};
```

```php
<?php // app/Models/CardMtgoId.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardMtgoId extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_foil' => 'bool',
        ];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
```

```php
<?php // database/factories/CardMtgoIdFactory.php

namespace Database\Factories;

use App\Models\Card;
use App\Models\CardMtgoId;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CardMtgoId> */
class CardMtgoIdFactory extends Factory
{
    public function definition(): array
    {
        return [
            'card_id' => Card::factory(),
            'value' => (string) fake()->unique()->numberBetween(10000, 9999999),
            'is_foil' => false,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=CardMtgoIdTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_01_000002_create_card_mtgo_ids_table.php app/Models/CardMtgoId.php database/factories/CardMtgoIdFactory.php tests/Feature/Catalog/CardMtgoIdTest.php
git commit -m "feat(catalog): add card_mtgo_ids CatalogID mapping (unique value, foil-aware)"
```

---

### Task 3: `card_prices` table + `CardPrice` model

**Files:**
- Create: `database/migrations/2026_07_01_000003_create_card_prices_table.php`
- Create: `app/Models/CardPrice.php`
- Create: `database/factories/CardPriceFactory.php`
- Test: `tests/Feature/Catalog/CardPriceTest.php`

**Interfaces:**
- Produces: `card_prices` (`card_id` FK `UNIQUE`, `tix` decimal, `source` string, `fetched_at` timestamp). One current price row per card, overwritten on each Goatbots pass. Consumed by Task 5 + the read API.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Catalog/CardPriceTest.php

use App\Models\Card;
use App\Models\CardPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores one current price per card and exposes it via the relation', function () {
    $card = Card::factory()->create();
    $card->price()->create(['tix' => '1.25', 'source' => 'goatbots', 'fetched_at' => now()]);

    expect((float) $card->fresh()->price->tix)->toBe(1.25);
    expect(fn () => CardPrice::create(['card_id' => $card->id, 'tix' => '2.00', 'source' => 'goatbots']))
        ->toThrow(Illuminate\Database\QueryException::class); // UNIQUE(card_id)
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=CardPriceTest`
Expected: FAIL — table/model missing.

- [ ] **Step 3: Migration + model + factory**

```php
<?php // database/migrations/2026_07_01_000003_create_card_prices_table.php

use App\Models\Card;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Card::class)->constrained()->cascadeOnDelete()->unique();
            $table->decimal('tix', 10, 2)->nullable();
            $table->string('source')->default('goatbots');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_prices');
    }
};
```

```php
<?php // app/Models/CardPrice.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardPrice extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tix' => 'decimal:2',
            'fetched_at' => 'datetime',
        ];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
```

```php
<?php // database/factories/CardPriceFactory.php

namespace Database\Factories;

use App\Models\Card;
use App\Models\CardPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CardPrice> */
class CardPriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'card_id' => Card::factory(),
            'tix' => fake()->randomFloat(2, 0.01, 50),
            'source' => 'goatbots',
            'fetched_at' => now(),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=CardPriceTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_01_000003_create_card_prices_table.php app/Models/CardPrice.php database/factories/CardPriceFactory.php tests/Feature/Catalog/CardPriceTest.php
git commit -m "feat(catalog): add card_prices (one current tix price per card)"
```

---

### Task 4: Scryfall bulk ingestion → upsert `cards` + `card_mtgo_ids`

**Files:**
- Modify: `config/services.php` (add `scryfall.bulk_url`)
- Create: `app/Jobs/DownloadScryfallCatalog.php`, `app/Jobs/ProcessScryfallCatalog.php`, `app/Jobs/ProcessScryfallCatalogBatch.php`
- Create: `app/Actions/Catalog/UpsertCardFromScryfall.php`
- Create: `app/Console/Commands/ImportCatalogCards.php`
- Create: `tests/fixtures/scryfall_sample.json` (small real-shaped bulk sample)
- Test: `tests/Feature/Catalog/ImportCatalogCardsTest.php`

**Interfaces:**
- Consumes: the Scryfall bulk-data endpoint (faked in tests).
- Produces: `ImportCatalogCards` command → downloads bulk, streams it, and **upserts** `cards` (keyed on `scryfall_id`) + `card_mtgo_ids` (keyed on `value`). Re-running is a no-op on unchanged data. Ports the parsing from the existing `ProcessScryfallCardBatch` (double-faced faces, image/type split, oracle-id fallback, regular + foil ids).

- [ ] **Step 1: Add the fixture + failing test**

Capture a small (~10-card) slice of the real Scryfall `default_cards` bulk file into `tests/fixtures/scryfall_sample.json` — **must** include: a plain card with `mtgo_id`, a double-faced card (`card_faces` with per-face `image_uris` + `oracle_id`), a card with `mtgo_foil_id`, and a `token` layout card (no mtgo_id). Real shapes only.

```php
<?php // tests/Feature/Catalog/ImportCatalogCardsTest.php

use App\Models\Card;
use App\Models\CardMtgoId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $bulk = file_get_contents(base_path('tests/fixtures/scryfall_sample.json'));

    Http::fake([
        'api.scryfall.com/bulk-data' => Http::response([
            'data' => [['type' => 'default_cards', 'download_uri' => 'https://cards.example/default.json']],
        ]),
        'cards.example/default.json' => Http::response($bulk),
    ]);
});

it('ingests the scryfall sample into cards + card_mtgo_ids', function () {
    $this->artisan('app:import-catalog-cards')->assertSuccessful();

    expect(Card::count())->toBeGreaterThan(0);
    // double-faced card resolves its front-face oracle_id + image
    $dfc = Card::where('name', 'like', '%//%')->first() ?? Card::whereNotNull('card_data')->get()->firstWhere(fn ($c) => str_contains((string) $c->name, '//'));
    // a regular printing produced a CatalogID mapping
    expect(CardMtgoId::count())->toBeGreaterThan(0);
});

it('is idempotent — re-ingesting the same bulk adds no duplicate rows', function () {
    $this->artisan('app:import-catalog-cards')->assertSuccessful();
    $cards = Card::count();
    $ids = CardMtgoId::count();

    $this->artisan('app:import-catalog-cards')->assertSuccessful();

    expect(Card::count())->toBe($cards);
    expect(CardMtgoId::count())->toBe($ids);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ImportCatalogCardsTest`
Expected: FAIL — command/jobs/action missing.

- [ ] **Step 3: Add config**

In `config/services.php`, add:

```php
'scryfall' => [
    'bulk_url' => env('SCRYFALL_BULK_URL', 'https://api.scryfall.com/bulk-data'),
    'user_agent' => env('SCRYFALL_USER_AGENT', 'MyMtgoApp/1.0'),
],
```

- [ ] **Step 4: Port the download + streaming jobs**

Copy `DownloadScryfallFile` → `DownloadScryfallCatalog` (unchanged logic: resolve `default_cards` `download_uri`, sink to `storage_path('app/private/catalog_cards.json')`, then dispatch `ProcessScryfallCatalog`). Copy `ProcessScryfallFile` → `ProcessScryfallCatalog` (stream with `JsonMachine\Items::fromFile`, batch 200, keep the token/mtgo_id filter, dispatch `ProcessScryfallCatalogBatch`). **Do not re-author the streaming/batching.**

- [ ] **Step 5: Implement `UpsertCardFromScryfall` + the batch job (upsert, not insert)**

Extract the per-card parsing from the existing `ProcessScryfallCardBatch` (front-face selection, `type` / `sub_type` split, image/thumbnail, oracle-id fallback, regular + foil id collection) into `UpsertCardFromScryfall::run(array $scryfallCard): Card`. The **critical change vs the legacy job**: use `Card::updateOrCreate(['scryfall_id' => ...], [...])` and `CardMtgoId::updateOrCreate(['value' => ...], ['card_id' => ..., 'is_foil' => ...])` so re-ingestion is idempotent (the legacy job skipped existing rows via `insert` — we upsert instead). Set `cards.mtgo_id` to the primary (non-foil) CatalogID. `ProcessScryfallCatalogBatch` iterates its `$cards` array calling the action.

```php
<?php // app/Actions/Catalog/UpsertCardFromScryfall.php

namespace App\Actions\Catalog;

use App\Models\Card;

final class UpsertCardFromScryfall
{
    /** @param array<string, mixed> $card */
    public function run(array $card): ?Card
    {
        $isToken = ($card['layout'] ?? null) === 'token';
        $hasMtgoId = isset($card['mtgo_id']);

        if (! $isToken && ! $hasMtgoId) {
            return null;
        }

        [$type, $image, $oracleId] = $this->frontFace($card);

        $model = Card::updateOrCreate(
            ['scryfall_id' => $card['id']],
            [
                'oracle_id' => $oracleId,
                'mtgo_id' => $hasMtgoId ? (string) $card['mtgo_id'] : null,
                'name' => $card['name'],
                'type' => $type,
                'rarity' => $card['rarity'],
                'color_identity' => collect($card['color_identity'] ?? [])->join(','),
                'mana_cost' => $card['mana_cost'] ?? null,
                'image' => $image,
                'card_data' => $card,
            ],
        );

        if (! $isToken && $hasMtgoId) {
            $model->mtgoIds()->updateOrCreate(['value' => (string) $card['mtgo_id']], ['is_foil' => false]);

            if (isset($card['mtgo_foil_id'])) {
                $model->mtgoIds()->updateOrCreate(['value' => (string) $card['mtgo_foil_id']], ['is_foil' => true]);
            }
        }

        return $model;
    }

    /** @return array{0:string,1:?string,2:?string} type, image, oracle_id */
    private function frontFace(array $card): array
    {
        $image = $card['image_uris']['normal'] ?? null;

        if (isset($card['card_faces'][0])) {
            $front = $card['card_faces'][0];
            $rawType = $front['type_line'];
            $oracleId = $card['oracle_id'] ?? ($front['oracle_id'] ?? null);
            $image = $front['image_uris']['normal'] ?? $image;
        } else {
            $rawType = $card['type_line'];
            $oracleId = $card['oracle_id'] ?? null;
        }

        return [trim(explode(' — ', $rawType)[0]), $image, $oracleId];
    }
}
```

`ImportCatalogCards` command mirrors `ImportScryfallCards`: if the bulk file already exists locally, dispatch `ProcessScryfallCatalog`; else dispatch `DownloadScryfallCatalog`. Because `Http::fake` is set and the queue runs sync in tests, the whole chain executes within the command.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=ImportCatalogCardsTest`
Expected: PASS (both ingestion + idempotency cases).

- [ ] **Step 7: Re-verify against a fuller real sample (don't blind-trust the port)**

Expand `scryfall_sample.json` to a captured real slice covering a modern-legal set. Assert distinct `type` values, that every non-token card has ≥1 `card_mtgo_ids` row, and that DFC cards store the front-face oracle_id (guards the warp-printing-divergence trap: a divergent printing must still land under the correct oracle). Fix the ported parser if reality diverges before proceeding.

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/services.php app/Jobs/DownloadScryfallCatalog.php app/Jobs/ProcessScryfallCatalog.php app/Jobs/ProcessScryfallCatalogBatch.php app/Actions/Catalog/UpsertCardFromScryfall.php app/Console/Commands/ImportCatalogCards.php tests/fixtures/scryfall_sample.json tests/Feature/Catalog/ImportCatalogCardsTest.php
git commit -m "feat(catalog): scryfall bulk ingestion → idempotent upsert of cards + mtgo id mapping"
```

---

### Task 5: Goatbots ingestion → prices + CatalogID→card mapping

**Files:**
- Modify: `config/services.php` (add `goatbots` urls)
- Create: `app/Actions/Catalog/MapCatalogIdToCard.php`, `app/Actions/Catalog/ApplyGoatbotsPrice.php`
- Create: `app/Jobs/IngestGoatbotsPrices.php`
- Create: `app/Console/Commands/IngestGoatbotsPrices.php`
- Create: `tests/fixtures/goatbots_card_definitions.txt`, `tests/fixtures/goatbots_prices.txt`
- Test: `tests/Feature/Catalog/IngestGoatbotsPricesTest.php`

**Interfaces:**
- Consumes: the Goatbots `card-definitions.zip` (CatalogID → {name, set, version}) and the Goatbots price feed (CatalogID → tix). Faked in tests.
- Produces: `IngestGoatbotsPrices` command → maps any missing CatalogIDs to `cards` via `MapCatalogIdToCard` (porting `MapGoatDefinitions::matchScryfallCard()` against `cards`), then writes/updates `card_prices` via `ApplyGoatbotsPrice`. The mapping step is **critical** — it is how gameplay-event CatalogIDs resolve to cards (warp-printing-divergence trap).

- [ ] **Step 1: Add fixtures + failing test**

`goatbots_card_definitions.txt` = a small real-shaped JSON object `{ "<catId>": {"name","cardset","version"}, ... }` (Goatbots uses a JSON map streamed via json-machine, `version` = `"<n>/<total>"`). Include: a plain match, a double-faced `"382b"` case, and a `PRM` case — mirroring the heuristics in the existing `MapGoatDefinitions`. `goatbots_prices.txt` = `{ "<catId>": <tix>, ... }`.

```php
<?php // tests/Feature/Catalog/IngestGoatbotsPricesTest.php

use App\Models\Card;
use App\Models\CardMtgoId;
use App\Models\CardPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // a Scryfall-sourced card the Goatbots definition should match to
    Card::factory()->create([
        'name' => 'Lightning Bolt', 'set' => 'MH2', // note: match on card_data/set per MapGoatDefinitions heuristics
    ]);

    Http::fake([
        'goatbots.com/download/prices/card-definitions.zip' => Http::response(
            zipFixture('goatbots_card_definitions.txt', 'card-definitions.txt')
        ),
        'goatbots.com/download/prices/*' => Http::response(
            file_get_contents(base_path('tests/fixtures/goatbots_prices.txt'))
        ),
    ]);
});

it('maps catalog ids and writes tix prices', function () {
    $this->artisan('app:ingest-goatbots-prices')->assertSuccessful();

    expect(CardMtgoId::count())->toBeGreaterThan(0);
    expect(CardPrice::whereNotNull('tix')->count())->toBeGreaterThan(0);
});

it('is idempotent — re-ingesting updates prices in place without duplicate mappings or price rows', function () {
    $this->artisan('app:ingest-goatbots-prices')->assertSuccessful();
    $ids = CardMtgoId::count();
    $prices = CardPrice::count();

    $this->artisan('app:ingest-goatbots-prices')->assertSuccessful();

    expect(CardMtgoId::count())->toBe($ids);
    expect(CardPrice::count())->toBe($prices);
});
```

Add a `zipFixture(string $file, string $entry): string` helper to `tests/Pest.php` that builds an in-memory zip (via `ZipArchive` on a temp path) wrapping the fixture so the faked download unpacks exactly like production.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=IngestGoatbotsPricesTest`
Expected: FAIL — command/jobs/actions missing.

- [ ] **Step 3: Add config**

```php
'goatbots' => [
    'definitions_url' => env('GOATBOTS_DEFINITIONS_URL', 'https://www.goatbots.com/download/prices/card-definitions.zip'),
    'prices_url' => env('GOATBOTS_PRICES_URL', 'https://www.goatbots.com/download/prices/card-prices.zip'),
],
```

- [ ] **Step 4: Port `matchScryfallCard()` into `MapCatalogIdToCard`**

Copy the four-tier matching heuristic from `MapGoatDefinitions::matchScryfallCard()` verbatim (collector-number strip on `version`, DFC `"382b"` base + `like` name, `PRM` CatalogID-as-collector, unique `(set, name)` last resort, bare-name fallback) — but query the v1 `cards` table (matching on `card_data->>'set'` / `card_data->>'collector_number'` / `name`, since v1 `cards` folds the legacy `scryfall_cards` columns into `card_data`). `run(string $catId, object $def): ?Card` returns the matched card and upserts the mapping: `$card->mtgoIds()->firstOrCreate(['value' => $catId])`. Skip CatalogIDs already mapped (as the legacy command does).

- [ ] **Step 5: Implement `ApplyGoatbotsPrice` + the job/command**

`ApplyGoatbotsPrice::run(string $catId, float $tix): void` resolves the CatalogID via `CardMtgoId::where('value', $catId)` → `card_id`, then `CardPrice::updateOrCreate(['card_id' => $cardId], ['tix' => $tix, 'source' => 'goatbots', 'fetched_at' => now()])`. `IngestGoatbotsPrices` job: fetch + unzip definitions (port the zip handling from `MapGoatDefinitions`), stream via `JsonMachine\Items`, call `MapCatalogIdToCard` per entry; then fetch the price feed and call `ApplyGoatbotsPrice` per entry. The `app:ingest-goatbots-prices` command dispatches the job (sync in tests). Clean up temp files unless `--keep-file`.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=IngestGoatbotsPricesTest`
Expected: PASS (mapping + prices + idempotency).

- [ ] **Step 7: Re-verify the mapping against a real definitions slice**

Capture a real `card-definitions.txt` slice including a DFC and a PRM entry; assert each maps to the expected oracle card (the warp-printing-divergence guard: a divergent Goatbots printing must resolve to the correct `cards` row so downstream CAST/SEEN events line up). Fix the ported heuristic if reality diverges.

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/services.php app/Actions/Catalog/MapCatalogIdToCard.php app/Actions/Catalog/ApplyGoatbotsPrice.php app/Jobs/IngestGoatbotsPrices.php app/Console/Commands/IngestGoatbotsPrices.php tests/fixtures/goatbots_card_definitions.txt tests/fixtures/goatbots_prices.txt tests/Pest.php tests/Feature/Catalog/IngestGoatbotsPricesTest.php
git commit -m "feat(catalog): goatbots ingestion — prices + CatalogID→card mapping (idempotent)"
```

---

### Task 6: Schedule both ingestion jobs

**Files:**
- Modify: `routes/console.php`
- Test: `tests/Feature/Catalog/CatalogScheduleTest.php`

**Interfaces:**
- Produces: the two catalog commands wired into the scheduler — Scryfall weekly (before the Goatbots pass, which depends on fresh card rows), Goatbots after it (and more frequently for price freshness). Mirrors the existing card-refresh cadence in `routes/console.php`.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Catalog/CatalogScheduleTest.php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('schedules both catalog ingestion commands', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($e) => $e->command)
        ->filter()
        ->implode("\n");

    expect($commands)->toContain('app:import-catalog-cards');
    expect($commands)->toContain('app:ingest-goatbots-prices');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=CatalogScheduleTest`
Expected: FAIL — commands not scheduled.

- [ ] **Step 3: Add the schedule entries**

In `routes/console.php`, alongside the existing card-refresh block:

```php
// v1 catalog: weekly Scryfall card refresh, then Goatbots mapping + prices.
Schedule::command('app:import-catalog-cards')
    ->weeklyOn(0, '00:30')
    ->withoutOverlapping();

// Goatbots runs after the Scryfall pass (needs fresh cards to map against),
// then daily for price freshness.
Schedule::command('app:ingest-goatbots-prices')
    ->dailyAt('03:30')
    ->withoutOverlapping();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=CatalogScheduleTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/console.php tests/Feature/Catalog/CatalogScheduleTest.php
git commit -m "feat(catalog): schedule scryfall + goatbots ingestion jobs"
```

---

### Task 7: Reshape `archetypes` for v1 (ownership + lifecycle columns)

**Files:**
- Create: `database/migrations/2026_07_01_000007_reshape_archetypes_for_v1.php`
- Modify: `app/Models/Archetype.php`
- Modify: `database/factories/ArchetypeFactory.php` (add v1 states)
- Test: `tests/Feature/Catalog/ArchetypeV1ShapeTest.php`

**Interfaces:**
- Produces: `archetypes` gains `manual` bool, `is_fallback` bool, `incomplete` bool, `merged_into_id` (self FK, nullable), `source_match_id` (nullable), `user_id` (nullable → null = global, owned otherwise). Additive migration — keeps every existing column (`uuid`, `name`, `format`, `color_identity`, `slug`, `super`, `is_active`, definition columns) so nothing regresses. Consumed by Tasks 8–12.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Catalog/ArchetypeV1ShapeTest.php

use App\Models\Archetype;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds the v1 ownership + lifecycle columns to archetypes', function () {
    expect(Schema::hasColumns('archetypes', [
        'manual', 'is_fallback', 'incomplete', 'merged_into_id', 'source_match_id', 'user_id',
    ]))->toBeTrue();
});

it('treats a null user_id as global and a set user_id as owned', function () {
    $global = Archetype::factory()->create(['user_id' => null]);
    $owned = Archetype::factory()->owned(User::factory()->create())->create();

    expect($global->isGlobal())->toBeTrue();
    expect($owned->isGlobal())->toBeFalse();
    expect($owned->user_id)->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ArchetypeV1ShapeTest`
Expected: FAIL — columns/helper missing.

- [ ] **Step 3: Additive migration**

```php
<?php // database/migrations/2026_07_01_000007_reshape_archetypes_for_v1.php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archetypes', function (Blueprint $table) {
            $table->boolean('manual')->default(false)->index();
            $table->boolean('is_fallback')->default(false);
            $table->boolean('incomplete')->default(false);
            $table->foreignId('merged_into_id')->nullable()->constrained('archetypes')->nullOnDelete();
            $table->unsignedBigInteger('source_match_id')->nullable()->index();
            $table->foreignIdFor(User::class)->nullable()->index()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('archetypes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropConstrainedForeignIdFor(User::class);
            $table->dropColumn(['manual', 'is_fallback', 'incomplete', 'source_match_id']);
        });
    }
};
```

- [ ] **Step 4: Extend model + factory**

Add casts (`manual`, `is_fallback`, `incomplete` => `bool`) to `Archetype::casts()`, a `user(): BelongsTo`, a `mergedInto(): BelongsTo` (self), and `isGlobal(): bool => $this->user_id === null`. In `ArchetypeFactory`, default the new booleans to `false`/`user_id` to `null`, and add an `owned(User $user)` state and a `fallback()` state.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=ArchetypeV1ShapeTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_01_000007_reshape_archetypes_for_v1.php app/Models/Archetype.php database/factories/ArchetypeFactory.php tests/Feature/Catalog/ArchetypeV1ShapeTest.php
git commit -m "feat(catalog): reshape archetypes for v1 (nullable user_id ownership + lifecycle flags)"
```

---

### Task 8: `archetype_decks` + `archetype_deck_cards` tables + models

**Files:**
- Create: `database/migrations/2026_07_01_000008_create_archetype_decks_table.php`, `2026_07_01_000009_create_archetype_deck_cards_table.php`
- Create: `app/Models/ArchetypeDeck.php`, `app/Models/ArchetypeDeckCard.php`
- Create: `database/factories/ArchetypeDeckFactory.php`, `database/factories/ArchetypeDeckCardFactory.php`
- Test: `tests/Feature/Catalog/ArchetypeDeckTest.php`

**Interfaces:**
- Produces: `archetype_decks` (variants of an archetype: `archetype_id` FK, `uuid`, `name` nullable, `seen_count`) and `archetype_deck_cards` (`archetype_deck_id` FK, `card_id` FK, `quantity`, `zone` main|side). Same shape as 0.x variant/cardlist. Consumed by the catalog endpoint (Task 10) and `match_archetypes` (Task 9).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Catalog/ArchetypeDeckTest.php

use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('holds a cardlist of catalog cards under an archetype variant', function () {
    $archetype = Archetype::factory()->create();
    $deck = ArchetypeDeck::factory()->for($archetype)->create();
    $card = Card::factory()->create();

    $deck->cards()->create(['card_id' => $card->id, 'quantity' => 4, 'zone' => 'main']);

    expect($archetype->decks)->toHaveCount(1);
    expect($deck->cards->first()->card->is($card))->toBeTrue();
    expect($deck->cards->first()->quantity)->toBe(4);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ArchetypeDeckTest`
Expected: FAIL — tables/models missing.

- [ ] **Step 3: Migrations + models + factories**

`archetype_decks`: `id`, `foreignIdFor(Archetype::class)->constrained()->cascadeOnDelete()`, `uuid` unique (auto-set in a `booted()` creating hook, mirroring `Archetype`), `name` nullable, `unsignedInteger('seen_count')->default(0)`, timestamps. `archetype_deck_cards`: `id`, `foreignIdFor(ArchetypeDeck::class)->constrained()->cascadeOnDelete()`, `foreignIdFor(Card::class)->constrained()`, `unsignedInteger('quantity')`, `string('zone')` (main|side), timestamps, `UNIQUE(archetype_deck_id, card_id, zone)`. Models: `$guarded = []`, `ArchetypeDeck` has `archetype(): BelongsTo` + `cards(): HasMany(ArchetypeDeckCard)`; `ArchetypeDeckCard` has `deck(): BelongsTo` + `card(): BelongsTo(Card)`. Add matching factories. Add `decks(): HasMany(ArchetypeDeck)` to `Archetype`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ArchetypeDeckTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_01_000008_create_archetype_decks_table.php database/migrations/2026_07_01_000009_create_archetype_deck_cards_table.php app/Models/ArchetypeDeck.php app/Models/ArchetypeDeckCard.php database/factories/ArchetypeDeckFactory.php database/factories/ArchetypeDeckCardFactory.php app/Models/Archetype.php tests/Feature/Catalog/ArchetypeDeckTest.php
git commit -m "feat(catalog): archetype_decks + archetype_deck_cards (variants + cardlists over catalog cards)"
```

---

### Task 9: `match_archetypes` linkage table + model

**Files:**
- Create: `database/migrations/2026_07_01_000010_create_match_archetypes_table.php`
- Create: `app/Models/MatchArchetype.php`
- Create: `database/factories/MatchArchetypeFactory.php`
- Test: `tests/Feature/Catalog/MatchArchetypeTest.php`

**Interfaces:**
- Produces: `match_archetypes` (`match_id`, `archetype_id` FK, `archetype_deck_id` FK nullable, `confidence` float, `is_opponent` bool), `UNIQUE(match_id, is_opponent)`. This is the row the **build worker writes** to link an archetype (and optionally a specific variant) to a match, once per side. This plan builds the table + model + constraint; the worker (see [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md)) populates it.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Catalog/MatchArchetypeTest.php

use App\Models\Archetype;
use App\Models\MatchArchetype;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links one player and one opponent archetype per match', function () {
    $player = Archetype::factory()->create();
    $opp = Archetype::factory()->create();

    MatchArchetype::create(['match_id' => 1, 'archetype_id' => $player->id, 'confidence' => 1.0, 'is_opponent' => false]);
    MatchArchetype::create(['match_id' => 1, 'archetype_id' => $opp->id, 'confidence' => 0.5, 'is_opponent' => true]);

    expect(MatchArchetype::where('match_id', 1)->count())->toBe(2);
});

it('rejects a second archetype for the same match + side (UNIQUE match_id,is_opponent)', function () {
    $a = Archetype::factory()->create();
    $b = Archetype::factory()->create();

    MatchArchetype::create(['match_id' => 7, 'archetype_id' => $a->id, 'confidence' => 1.0, 'is_opponent' => false]);

    expect(fn () => MatchArchetype::create(['match_id' => 7, 'archetype_id' => $b->id, 'confidence' => 1.0, 'is_opponent' => false]))
        ->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=MatchArchetypeTest`
Expected: FAIL — table/model missing.

- [ ] **Step 3: Migration + model + factory**

```php
<?php // database/migrations/2026_07_01_000010_create_match_archetypes_table.php

use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_archetypes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('match_id')->index();
            $table->foreignIdFor(Archetype::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(ArchetypeDeck::class)->nullable()->constrained()->nullOnDelete();
            $table->float('confidence')->default(0);
            $table->boolean('is_opponent')->default(false);
            $table->timestamps();

            $table->unique(['match_id', 'is_opponent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_archetypes');
    }
};
```

Model: `$guarded = []`, casts (`confidence` => `float`, `is_opponent` => `bool`), relations `archetype(): BelongsTo`, `archetypeDeck(): BelongsTo`. Factory defaults `match_id` random, `archetype_id` => `Archetype::factory()`, `confidence` random 0–1, `is_opponent` false. Note the FK to `matches` is left as a plain `unsignedBigInteger` (the worker's `matches` table is shared and may not exist in every catalog-only test), matching how the worker references it.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=MatchArchetypeTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_01_000010_create_match_archetypes_table.php app/Models/MatchArchetype.php database/factories/MatchArchetypeFactory.php tests/Feature/Catalog/MatchArchetypeTest.php
git commit -m "feat(catalog): match_archetypes linkage (is_opponent, UNIQUE(match_id,is_opponent))"
```

---

### Task 10: Archetype catalog endpoint (client-mirrored, offline-cacheable)

**Files:**
- Create: `app/Data/ArchetypeCatalogData.php`, `app/Data/ArchetypeCatalogDeckData.php`
- Create: `app/Http/Controllers/Catalog/ArchetypeCatalogController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Catalog/ArchetypeCatalogEndpointTest.php`

**Interfaces:**
- Consumes: the v1 `archetypes` + `archetype_decks` + `archetype_deck_cards` + `cards` tables.
- Produces: `GET /api/archetype-catalog` (gated by `auth:api` — Passport Bearer; v1 clients authenticate with Passport, NOT the 0.x device-key model. The authenticated user scopes owned archetypes; globals (`user_id` null) are always included. Test with `Passport::actingAs($user)`, not `deviceHeaders()`.) → a self-contained JSON list the client caches into its local `archetype_catalog` for offline live classification. Each entry: `uuid`, `name`, `format`, `color_identity`, `is_fallback`, plus `decks[]` each with `uuid` and `cards[]` (`mtgo_id`, `oracle_id`, `name`, `quantity`, `zone`). Returns global archetypes (`user_id` null) **plus** the authenticated user's owned ones. Filterable by `?format=`.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Catalog/ArchetypeCatalogEndpointTest.php

use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns global archetypes with self-contained decklists for local mirroring', function () {
    $archetype = Archetype::factory()->create(['format' => 'modern', 'user_id' => null]);
    $deck = ArchetypeDeck::factory()->for($archetype)->create();
    $card = Card::factory()->create(['mtgo_id' => '555', 'oracle_id' => 'or-x', 'name' => 'Ragavan']);
    $deck->cards()->create(['card_id' => $card->id, 'quantity' => 4, 'zone' => 'main']);

    $res = $this->getJson('/api/archetype-catalog?format=modern', deviceHeaders())
        ->assertOk()
        ->json();

    expect($res)->toHaveCount(1);
    expect($res[0]['uuid'])->toBe($archetype->uuid);
    expect($res[0]['decks'][0]['cards'][0])
        ->toMatchArray(['mtgo_id' => '555', 'oracle_id' => 'or-x', 'name' => 'Ragavan', 'quantity' => 4, 'zone' => 'main']);
});

it('filters by format and excludes other users owned archetypes', function () {
    Archetype::factory()->create(['format' => 'legacy', 'user_id' => null]);
    Archetype::factory()->create(['format' => 'modern', 'user_id' => 999]); // someone else's owned archetype

    $res = $this->getJson('/api/archetype-catalog?format=modern', deviceHeaders())->assertOk()->json();

    expect($res)->toBeEmpty();
});
```

(`deviceHeaders()` exists in `tests/Pest.php`. Note `ValidateDeviceKey` short-circuits in the `local` env, so the header just needs to be present; keep the call for parity with the other endpoint tests.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ArchetypeCatalogEndpointTest`
Expected: FAIL — route/controller/DTO missing.

- [ ] **Step 3: Implement the DTOs + controller**

`ArchetypeCatalogDeckData` = `{ string $uuid, array $cards }`; `ArchetypeCatalogData` = `{ string $uuid, string $name, string $format, ?string $colorIdentity, bool $isFallback, array $decks }` with a `fromModel(Archetype)` that maps `decks.cards.card`. Controller (single-action, invokable) eager-loads `decks.cards.card`, scopes to `whereNull('user_id')->orWhere('user_id', $ownerId)` (owner resolved from the request — for now the device-key flow has no user, so owned filtering keys off a resolved account id where available and otherwise returns globals only), applies the `format` filter, and returns `ArchetypeCatalogData::collect(...)`. Map each card to `['mtgo_id' => $c->card?->mtgo_id, 'oracle_id' => $c->card?->oracle_id, 'name' => $c->card?->name, 'quantity' => $c->quantity, 'zone' => $c->zone]`, filtering out cards missing `oracle_id`/`mtgo_id` (mirrors the existing `/archetypes/{uuid}/decklist` endpoint's null-guard).

- [ ] **Step 4: Register the route**

In `routes/api.php`, inside a `Route::middleware('auth:api')->group(...)` (Passport — see RECONCILIATION.md; v1 is Passport-only, not device-key):

```php
Route::get('/archetype-catalog', \App\Http\Controllers\Catalog\ArchetypeCatalogController::class)
    ->name('catalog.archetypes');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=ArchetypeCatalogEndpointTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/ArchetypeCatalogData.php app/Data/ArchetypeCatalogDeckData.php app/Http/Controllers/Catalog/ArchetypeCatalogController.php routes/api.php tests/Feature/Catalog/ArchetypeCatalogEndpointTest.php
git commit -m "feat(catalog): archetype catalog endpoint (global + owned, self-contained for local mirror)"
```

---

### Task 11: Admin — promote an owned archetype to global

**Files:**
- Create: `app/Actions/Archetypes/PromoteArchetypeToGlobal.php`
- Create: `app/Http/Controllers/Admin/PromoteArchetypeController.php`
- Create: `app/Http/Requests/Admin/PromoteArchetypeRequest.php`
- Modify: `routes/web.php` (register under the existing admin route group)
- Test: `tests/Feature/Admin/PromoteArchetypeTest.php`

**Interfaces:**
- Consumes: an owned `Archetype` (`user_id` set).
- Produces: `PromoteArchetypeToGlobal::run(Archetype $a): Archetype` — sets `user_id = null` and `manual = true` (a promoted archetype is a curated global one), preserving its `uuid` (uuids are shared/global, so promotion is an ownership change, not a re-key). The controller is admin-gated (`is_admin`).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Admin/PromoteArchetypeTest.php

use App\Actions\Archetypes\PromoteArchetypeToGlobal;
use App\Models\Archetype;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('promotes an owned archetype to global, keeping its uuid', function () {
    $owner = User::factory()->create();
    $archetype = Archetype::factory()->owned($owner)->create();
    $uuid = $archetype->uuid;

    $result = app(PromoteArchetypeToGlobal::class)->run($archetype);

    expect($result->user_id)->toBeNull();
    expect($result->isGlobal())->toBeTrue();
    expect($result->uuid)->toBe($uuid);
});

it('lets an admin promote via the endpoint but forbids non-admins', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create(['is_admin' => false]);
    $archetype = Archetype::factory()->owned(User::factory()->create())->create();

    $this->actingAs($user)->post(route('admin.archetypes.promote', $archetype))->assertForbidden();

    $this->actingAs($admin)->post(route('admin.archetypes.promote', $archetype))->assertRedirect();
    expect($archetype->fresh()->user_id)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PromoteArchetypeTest`
Expected: FAIL — action/controller/route missing.

- [ ] **Step 3: Implement**

```php
<?php // app/Actions/Archetypes/PromoteArchetypeToGlobal.php

namespace App\Actions\Archetypes;

use App\Models\Archetype;

final class PromoteArchetypeToGlobal
{
    public function run(Archetype $archetype): Archetype
    {
        $archetype->update([
            'user_id' => null,
            'manual' => true,
        ]);

        return $archetype->refresh();
    }
}
```

`PromoteArchetypeRequest::authorize()` returns `$this->user()?->is_admin === true` (matching the `is_admin` gate). The invokable controller calls the action and redirects back to `admin.archetypes.show`. Register in `routes/web.php` inside the existing admin group (mirror how `admin.archetypes.merge` is registered):

```php
Route::post('archetypes/{archetype}/promote', PromoteArchetypeController::class)
    ->name('admin.archetypes.promote');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=PromoteArchetypeTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Archetypes/PromoteArchetypeToGlobal.php app/Http/Controllers/Admin/PromoteArchetypeController.php app/Http/Requests/Admin/PromoteArchetypeRequest.php routes/web.php tests/Feature/Admin/PromoteArchetypeTest.php
git commit -m "feat(catalog): admin promote owned archetype to global (uuid preserved)"
```

---

## Self-Review checklist (run after fleshing 1–11)

1. **Spec coverage** — every [`spec.md`](./spec.md) bullet maps to a task: cards schema (mtgo_id/oracle_id/scryfall_id/name/type/rarity/color_identity/mana_cost/image) = Task 1; prices representation = Task 3; MTGO CatalogID↔card mapping (warp-printing-divergence) = Tasks 2, 4, 5; Scryfall ingestion = Task 4; Goatbots ingestion = Task 5; schedule both = Task 6; archetypes v1 shape incl. nullable `user_id` + `manual`/`is_fallback`/`incomplete`/`merged_into_id`/`source_match_id` = Task 7; `archetype_decks`/`archetype_deck_cards` = Task 8; `match_archetypes` (is_opponent, UNIQUE) = Task 9; client-mirrored catalog endpoint = Task 10; admin-promote-to-global = Task 11.
2. **Idempotency asserted** — Tasks 4 and 5 both have an explicit "re-ingest adds no duplicates / updates in place" case (upsert on `scryfall_id` / `mtgo_id` / `card_id`). Confirm no `insert()`-without-guard slipped in from the ported code.
3. **`Http::fake` everywhere** — no live Scryfall/Goatbots call in any test; the bulk-data resolve, the card download, the definitions zip, and the price feed are all faked.
4. **Worker not duplicated** — `match_archetypes` (Task 9) is created but populated by the worker; `decks`/`deck_versions`/`matches` are **owned by cloud-pipeline** ([`../cloud-pipeline/plan.md`](../cloud-pipeline/plan.md)) and only referenced here, never re-migrated. Cross-checked against [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md).
5. **Ownership semantics** — `user_id` null = global, set = owned, throughout Tasks 7/10/11; promotion preserves the uuid (uuids are global/shared).
6. **Conventions** — every migration uses `Schema::` + `foreignIdFor`; every model `$guarded = []` with `casts()`; every Action is a single-responsibility invokable class (no service classes for app logic); Pest files opt into `RefreshDatabase`; Pint run before each commit.
7. **Placeholder scan** — no "TBD" / "handle edge cases later" / "similar to Task N" left in the plan; every code block is complete and paths are exact.
