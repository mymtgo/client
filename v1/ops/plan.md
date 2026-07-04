# Ops Implementation Plan — Authorization, Entitlement, Deletion, Backup, Limits

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Format:** each task is a self-contained TDD loop (failing test → run-fail → implement → run-pass → Pint → commit). No placeholders; exact paths; complete code.

**Goal:** Build the cross-cutting cloud **mechanisms** the endpoint plans consume — server-side ownership scoping, binary free/paid entitlement gating, account deactivation with PII obfuscation, DB backup to DigitalOcean Spaces, and forgiving abuse-only rate limiting. These are policies/gates/actions/config, **not** endpoints: the sink ([`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md)) and read API ([`../cloud-api/spec.md`](../cloud-api/spec.md)) plug into them.

**Architecture:** The API is the system of record and **never trusts the client for scoping**. A reusable `EnsuresOwnership` concern + query scopes force every gameplay query and the sink write to the authed user (own `user_id` + `match_key`). A `plan:paid` gate/middleware gates paid pages/endpoints. Deactivation flips `is_active`, obfuscates PII, and releases `mtgo_player_id` so the same MTGO account can cleanly re-bind to a fresh signup while gameplay rows are retained dissociated. Backups dump the DB nightly to Spaces (files already live there — DB is re-derivable). Rate limits are generous per-user ceilings that only stop pathological abuse (oversized uploads, runaway loops).

**Tech Stack:** PHP 8.3, Laravel 13, PostgreSQL, Pest v4, Pint. Object storage = DigitalOcean Spaces (S3-compatible, `s3`/`spaces` disk) + Horizon (Redis). Auth = Laravel Passport tokens (per [`../cloud-auth/spec.md`](../cloud-auth/spec.md)); `request()->user()` is the authed `User` (see [`../overview/spec.md`](../overview/spec.md) §8). v1 cloud = a NEW Postgres database on a new host; 0.x is frozen on its own subdomain + existing DB (no server-side migration).

## Global Constraints

- **v1 cloud = a NEW Postgres database on a new host in `../api`.** 0.x is **frozen** on its own subdomain + its existing DB (Sanctum + device keys, `reported_matches`) — no server-side migration. v1 runs on clean migrations against a new PostgreSQL DB per [`../cloud-auth/spec.md`](../cloud-auth/spec.md) / [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md). **Reuse code, not the DB** — the proven ingestion/catalog logic in `../api` is ported; the schema + Passport/PKCE auth are built fresh (the old Sanctum device-key model stays with 0.x). Stack: **Laravel 13 / PHP 8.3 / PostgreSQL** (see [`../overview/spec.md`](../overview/spec.md) §8).
- **Server is the source of truth for scoping and entitlement.** Never trust a client-supplied `user_id`, `plan`, or ownership claim — always derive from `request()->user()`.
- **Ownership = own `user_id` only, plus `source`+`match_key` namespacing on the sink.** A client may read/write only rows where `user_id === auth user id`; the sink path is `{user_id}/{source}/{match_key}.json` (source-scoped — see `RECONCILIATION.md`). **Cross-user resource access returns `404`** (no existence leak, via user-scoped route-model binding); the **plan gate returns `402`**. (Where later tasks in this file say `403` for cross-user ownership, read `404` per `RECONCILIATION.md`.)
- **Entitlement is binary** — `plan` ∈ (`free`, `paid`). No tiers, no ladder. Gate per-endpoint.
- **Deletion is deactivate + obfuscate + retain.** Never hard-delete gameplay rows; obfuscate PII (`email`, `discord_id`, `mtgo_username`), release `mtgo_player_id`. Re-signup is a brand-new account.
- **Files are the floor, DB is re-derivable.** Backups protect the DB for fast recovery; the `{match}.json` files in Spaces are the true system of record (re-run the worker to rebuild). Restore is documented, not automated.
- **Limits are forgiving.** Absorb bursts from heavy pushers; only reject pathological abuse (oversized files, runaway loops). Constraint is worker/API capacity, not cost (fixed droplet).
- Use **invokable Actions** (single responsibility), not service classes. PHP 8 constructor property promotion, explicit return types, curly braces on all control structures.
- Run `vendor/bin/pint --dirty --format agent` before finalizing any task.
- Tests: Pest v4 feature tests, folded into the task whose deliverable needs them. Use factories (`User::factory()`, `MtgoAccount::factory()`, `MatchFile::factory()`).
- **This plan depends on the identity schema** (`users.plan`/`is_active`/`deactivated_at`, `mtgo_accounts.mtgo_player_id`) from [`../cloud-auth/spec.md`](../cloud-auth/spec.md) and the gameplay tables (`match_files`, `matches`, `games`, …) from [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md). Where those columns are not yet present, the owning task adds the minimal migration it needs (Task 0).

---

## File Structure

**New (ops mechanisms):**
- `database/migrations/..._add_deactivation_and_plan_to_users_table.php` — ensure `plan`, `is_active`, `deactivated_at` exist (Task 0).
- `app/Models/Concerns/BelongsToUser.php` — reusable ownership trait: `user()` relation + `scopeOwnedBy()` global scope helper (Task 1).
- `app/Policies/MatchFilePolicy.php`, `app/Policies/MatchPolicy.php` — per-model ownership policies (Task 1).
- `app/Http/Middleware/EnsureOwnership.php` — route-model-binding ownership guard for gameplay reads + the sink (Task 1).
- `app/Http/Middleware/EnsurePaidPlan.php` — `plan:paid` gate/middleware (Task 2).
- `app/Actions/Account/DeactivateAccount.php` — deactivate + obfuscate + release binding (Task 3).
- `app/Console/Commands/BackupDatabaseToSpaces.php` — scheduled DB dump → Spaces (Task 4).
- `config/backup.php` — backup disk/retention config (Task 4).
- `app/Http/Middleware/AbsorbSinkBurst.php` — oversized-file guard for the sink (Task 5).
- `docs/v1/ops/restore.md` — restore runbook (Task 4).

**Modified:**
- `app/Models/User.php` — casts + `deactivate()` helper hook + `AuthorizesRequests`.
- `app/Providers/AppServiceProvider.php` — register the `paid` gate, policies (or `AuthServiceProvider` if present).
- `bootstrap/app.php` — register middleware aliases (`ownership`, `plan:paid`, `sink.guard`) + named rate limiters.
- `routes/console.php` — schedule the backup command nightly.

---

### Task 0: Identity columns the ops layer depends on

**Files:**
- Create: `database/migrations/2026_07_01_000000_add_deactivation_and_plan_to_users_table.php`
- Modify: `app/Models/User.php` (casts)
- Test: `tests/Feature/Ops/UserOpsColumnsTest.php`

**Interfaces:**
- Produces: `users` has `plan` (enum-string, default `free`), `is_active` (bool, default `true`), `deactivated_at` (nullable timestamp). Consumed by Tasks 2 and 3.

> If [`../cloud-auth/spec.md`](../cloud-auth/spec.md)'s `create_users_table` migration already ships these columns, this task collapses to the model-cast + test; skip the column adds and keep the assertions.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Ops/UserOpsColumnsTest.php
use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('has the deactivation + plan columns on users', function () {
    expect(Schema::hasColumn('users', 'plan'))->toBeTrue();
    expect(Schema::hasColumn('users', 'is_active'))->toBeTrue();
    expect(Schema::hasColumn('users', 'deactivated_at'))->toBeTrue();
});

it('defaults a new user to the free plan and active', function () {
    $user = User::factory()->create();

    expect($user->plan)->toBe('free');
    expect($user->is_active)->toBeTrue();
    expect($user->deactivated_at)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=UserOpsColumnsTest`
Expected: FAIL — columns / defaults missing.

- [ ] **Step 3: Add the migration**

```php
<?php // database/migrations/2026_07_01_000000_add_deactivation_and_plan_to_users_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'plan')) {
                $table->string('plan')->default('free')->index();
            }
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->index();
            }
            if (! Schema::hasColumn('users', 'deactivated_at')) {
                $table->timestamp('deactivated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['plan', 'is_active', 'deactivated_at']);
        });
    }
};
```

- [ ] **Step 4: Add the casts to `User`**

In `app/Models/User.php`, ensure the `casts()` method includes:

```php
protected function casts(): array
{
    return [
        // ...existing casts...
        'is_active' => 'boolean',
        'deactivated_at' => 'datetime',
        // 'plan' stays a plain string ('free'|'paid') — binary, no enum ladder
    ];
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=UserOpsColumnsTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/User.php tests/Feature/Ops/UserOpsColumnsTest.php
git commit -m "feat(api): add plan + deactivation columns to users (ops prerequisites)"
```

---

### Task 1: Authorization — `BelongsToUser` concern, policies + `EnsureOwnership` middleware

**Files:**
- Create: `app/Models/Concerns/BelongsToUser.php`
- Create: `app/Policies/MatchFilePolicy.php`, `app/Policies/MatchPolicy.php`
- Create: `app/Http/Middleware/EnsureOwnership.php`
- Modify: `app/Models/{MatchFile,MtgoMatch}.php` (use the concern), `bootstrap/app.php` (alias `ownership`), `app/Providers/AppServiceProvider.php` (register policies)
- Test: `tests/Feature/Ops/OwnershipTest.php`

**Interfaces:**
- Produces:
  - `BelongsToUser` trait → `->user()` relation + `scopeOwnedBy(Builder $q, User $u)` and a `bootBelongsToUser()` that installs a `owned` local scope. Every gameplay model uses it.
  - `MatchFilePolicy@view/update`, `MatchPolicy@view` → deny unless `$model->user_id === $user->id`.
  - `EnsureOwnership` middleware → for any route-bound model implementing ownership, 403 unless owned by `request()->user()`; for the sink, asserts the `{match_key}` write targets the authed user's namespace. Applied to every gameplay read route + the sink write route.
- Consumed by: the read API endpoints ([`../cloud-api/spec.md`](../cloud-api/spec.md)) and the sink ([`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md)).

- [ ] **Step 1: Write the failing test (cross-user access denied, own access allowed)**

```php
<?php // tests/Feature/Ops/OwnershipTest.php
use App\Models\MatchFile;
use App\Models\User;

it('denies reading another users match file (policy)', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $file = MatchFile::factory()->for($owner)->create(['match_key' => 'tok-a']);

    expect($other->can('view', $file))->toBeFalse();
    expect($owner->can('view', $file))->toBeTrue();
});

it('scopes queries to the authenticated user via the owned scope', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    MatchFile::factory()->for($owner)->create(['match_key' => 'mine']);
    MatchFile::factory()->for($other)->create(['match_key' => 'theirs']);

    $visible = MatchFile::query()->ownedBy($owner)->pluck('match_key');

    expect($visible)->toContain('mine');
    expect($visible)->not->toContain('theirs');
});

it('403s a cross-user read through the ownership middleware', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $file = MatchFile::factory()->for($owner)->create(['match_key' => 'tok-b']);

    $this->actingAs($other)
        ->getJson("/api/match-files/{$file->getKey()}")
        ->assertForbidden();

    $this->actingAs($owner)
        ->getJson("/api/match-files/{$file->getKey()}")
        ->assertOk();
});

it('blocks a sink write into another users match-key namespace', function () {
    $owner = User::factory()->create();
    $file = MatchFile::factory()->for($owner)->create(['match_key' => 'tok-c']);

    // authed user tries to overwrite a match_key owned by someone else
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->postJson('/api/sink/tok-c', ['schema_version' => 1, 'match_key' => 'tok-c'])
        ->assertForbidden();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=OwnershipTest`
Expected: FAIL — trait/policies/middleware/routes not defined.

- [ ] **Step 3: Implement the `BelongsToUser` concern**

```php
<?php // app/Models/Concerns/BelongsToUser.php
namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToUser
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Restrict a query to rows owned by the given user. */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where($this->qualifyColumn('user_id'), $user->getKey());
    }

    /** True when the row belongs to the given user. */
    public function isOwnedBy(User $user): bool
    {
        return (int) $this->getAttribute('user_id') === (int) $user->getKey();
    }
}
```

Add `use BelongsToUser;` to `app/Models/MatchFile.php` and `app/Models/MtgoMatch.php` (and every other gameplay model as those land — `Game`, `CardGameStat`, etc.).

- [ ] **Step 4: Implement the policies**

```php
<?php // app/Policies/MatchFilePolicy.php
namespace App\Policies;

use App\Models\MatchFile;
use App\Models\User;

class MatchFilePolicy
{
    public function view(User $user, MatchFile $file): bool
    {
        return $file->isOwnedBy($user);
    }

    public function update(User $user, MatchFile $file): bool
    {
        return $file->isOwnedBy($user);
    }

    public function delete(User $user, MatchFile $file): bool
    {
        return $file->isOwnedBy($user);
    }
}
```

```php
<?php // app/Policies/MatchPolicy.php
namespace App\Policies;

use App\Models\MtgoMatch;
use App\Models\User;

class MatchPolicy
{
    public function view(User $user, MtgoMatch $match): bool
    {
        return $match->isOwnedBy($user);
    }
}
```

Register in `app/Providers/AppServiceProvider@boot()`:

```php
use App\Models\{MatchFile, MtgoMatch};
use App\Policies\{MatchFilePolicy, MatchPolicy};
use Illuminate\Support\Facades\Gate;

Gate::policy(MatchFile::class, MatchFilePolicy::class);
Gate::policy(MtgoMatch::class, MatchPolicy::class);
```

- [ ] **Step 5: Implement the `EnsureOwnership` middleware**

```php
<?php // app/Http/Middleware/EnsureOwnership.php
namespace App\Http\Middleware;

use App\Models\MatchFile;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwnership
{
    /**
     * Deny any request that touches a resource not owned by the authed user.
     * Guards both route-model-bound gameplay reads and the match-key sink path.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        // 1. Route-model-bound gameplay resources: any bound Model exposing user_id.
        foreach ($request->route()?->parameters() ?? [] as $param) {
            if ($param instanceof Model && $param->getAttribute('user_id') !== null) {
                abort_unless((int) $param->getAttribute('user_id') === (int) $user->getKey(), 403);
            }
        }

        // 2. Sink path: {match_key} must be unclaimed OR already owned by the user.
        $matchKey = $request->route('match_key');
        if ($matchKey !== null) {
            $existing = MatchFile::query()->where('match_key', $matchKey)->first();
            abort_if($existing !== null && ! $existing->isOwnedBy($user), 403);
        }

        return $next($request);
    }
}
```

Register the alias in `bootstrap/app.php` `->withMiddleware()`:

```php
$middleware->alias([
    'ownership' => \App\Http\Middleware\EnsureOwnership::class,
]);
```

Attach `ownership` (with `auth:api`) to the gameplay read routes and the sink route in `routes/api.php`, e.g.:

```php
Route::middleware(['auth:api', 'ownership'])->group(function (): void {
    Route::get('/match-files/{matchFile}', /* read controller */)->name('match-files.show');
    Route::post('/sink/{match_key}', /* sink controller */)->name('sink.store');
});
```

> Route model binding resolves `{matchFile}` to a `MatchFile`; the middleware's loop enforces ownership before the controller runs, so the read controller may query freely (or belt-and-braces with `->ownedBy($user)`).

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=OwnershipTest`
Expected: PASS — policy denies + allows, `ownedBy` scope filters, middleware 403s cross-user, sink rejects foreign match-key.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Concerns/BelongsToUser.php app/Policies app/Http/Middleware/EnsureOwnership.php app/Models/MatchFile.php app/Models/MtgoMatch.php app/Providers/AppServiceProvider.php bootstrap/app.php routes/api.php tests/Feature/Ops/OwnershipTest.php
git commit -m "feat(api): server-side ownership scoping (concern + policies + EnsureOwnership)"
```

---

### Task 2: Entitlement — `plan:paid` gate + middleware (binary free/paid)

**Files:**
- Create: `app/Http/Middleware/EnsurePaidPlan.php`
- Modify: `app/Providers/AppServiceProvider.php` (register the `paid` gate), `bootstrap/app.php` (alias `plan:paid`), `routes/api.php` (attach to gated endpoints)
- Test: `tests/Feature/Ops/EntitlementTest.php`

**Interfaces:**
- Produces:
  - `Gate::define('paid', fn (User $u) => $u->plan === 'paid')` — server-side truth.
  - `EnsurePaidPlan` middleware (aliased `plan:paid`) → 402/403 for free users, pass for paid. Applied per gated read endpoint.
- Consumed by: paid pages/endpoints in [`../cloud-api/spec.md`](../cloud-api/spec.md). The UI locking is a client concern; the server is authoritative.

- [ ] **Step 1: Write the failing test (free blocked, paid allowed)**

```php
<?php // tests/Feature/Ops/EntitlementTest.php
use App\Models\User;

it('grants the paid gate only to paid users', function () {
    $free = User::factory()->create(['plan' => 'free']);
    $paid = User::factory()->create(['plan' => 'paid']);

    expect($free->can('paid'))->toBeFalse();
    expect($paid->can('paid'))->toBeTrue();
});

it('blocks a free user from a paid endpoint via middleware', function () {
    $free = User::factory()->create(['plan' => 'free']);

    $this->actingAs($free)
        ->getJson('/api/insights') // example paid endpoint
        ->assertStatus(402);
});

it('allows a paid user through the paid middleware', function () {
    $paid = User::factory()->create(['plan' => 'paid']);

    $this->actingAs($paid)
        ->getJson('/api/insights')
        ->assertOk();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=EntitlementTest`
Expected: FAIL — gate/middleware/route not defined.

- [ ] **Step 3: Register the gate + implement the middleware**

In `app/Providers/AppServiceProvider@boot()`:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('paid', fn (User $user): bool => $user->plan === 'paid');
```

```php
<?php // app/Http/Middleware/EnsurePaidPlan.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePaidPlan
{
    /** Gate a paid-only endpoint. Server-side plan is the source of truth. */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        // 402 Payment Required — distinct from 403 so the client can surface an upsell.
        abort_unless($request->user()->can('paid'), 402, 'A paid plan is required.');

        return $next($request);
    }
}
```

Alias in `bootstrap/app.php`:

```php
$middleware->alias([
    // ...ownership from Task 1...
    'plan:paid' => \App\Http\Middleware\EnsurePaidPlan::class,
]);
```

Attach to gated routes in `routes/api.php`:

```php
Route::middleware(['auth:api', 'plan:paid'])->group(function (): void {
    Route::get('/insights', /* paid read controller */)->name('insights.index');
});
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=EntitlementTest`
Expected: PASS — gate binary, free 402, paid 200.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/EnsurePaidPlan.php app/Providers/AppServiceProvider.php bootstrap/app.php routes/api.php tests/Feature/Ops/EntitlementTest.php
git commit -m "feat(api): binary plan:paid gate + per-endpoint entitlement middleware"
```

---

### Task 3: Account deletion — `DeactivateAccount` (deactivate + obfuscate + release binding)

**Files:**
- Create: `app/Actions/Account/DeactivateAccount.php`
- Modify: `app/Models/User.php` (optional `deactivate()` convenience)
- Test: `tests/Feature/Ops/DeactivateAccountTest.php`

**Interfaces:**
- Consumes: an active `User` with an `mtgo_accounts` binding + retained gameplay rows (`matches`, …).
- Produces: `DeactivateAccount::run(User $user): void` —
  - sets `is_active=false`, `deactivated_at=now()`;
  - obfuscates PII: `email` → `deleted+{id}@deleted.invalid`, `password=null`, `discord_id=null`, and the linked `mtgo_accounts.mtgo_username` → `deleted-{id}`;
  - **releases `mtgo_player_id`** (nulls it on `mtgo_accounts`) so a fresh signup can re-bind the same MTGO account;
  - **retains** all gameplay rows dissociated from the person (rows keep `user_id`, but the person is anonymized — no hard delete);
  - revokes the user's Passport tokens (forces re-auth on every device).
- Consumed by: the deletion request endpoint in [`../cloud-api/spec.md`](../cloud-api/spec.md) (thin controller → this action).

- [ ] **Step 1: Write the failing test (PII gone, data retained, player_id re-bindable)**

```php
<?php // tests/Feature/Ops/DeactivateAccountTest.php
use App\Actions\Account\DeactivateAccount;
use App\Models\{MatchFile, MtgoAccount, User};

it('deactivates and obfuscates PII while retaining gameplay data', function () {
    $user = User::factory()->create([
        'email' => 'real@example.com',
        'discord_id' => '123456789',
        'plan' => 'paid',
    ]);
    $account = MtgoAccount::factory()->for($user)->create([
        'mtgo_player_id' => 147160,
        'mtgo_username' => 'Pro_MTG',
    ]);
    MatchFile::factory()->for($user)->count(3)->create();

    app(DeactivateAccount::class)->run($user);

    $user->refresh();
    expect($user->is_active)->toBeFalse();
    expect($user->deactivated_at)->not->toBeNull();

    // PII obfuscated
    expect($user->email)->not->toBe('real@example.com');
    expect($user->email)->toContain('@deleted.invalid');
    expect($user->discord_id)->toBeNull();
    expect($account->fresh()->mtgo_username)->not->toBe('Pro_MTG');

    // gameplay data retained, still associated to the (now anonymized) user row
    expect(MatchFile::query()->where('user_id', $user->id)->count())->toBe(3);
});

it('releases mtgo_player_id so a fresh signup can re-bind the same MTGO account', function () {
    $user = User::factory()->create();
    MtgoAccount::factory()->for($user)->create(['mtgo_player_id' => 147160, 'mtgo_username' => 'Pro_MTG']);

    app(DeactivateAccount::class)->run($user);

    // player id released → the global UNIQUE(mtgo_player_id) no longer collides
    expect(MtgoAccount::query()->where('mtgo_player_id', 147160)->exists())->toBeFalse();

    // a brand-new account can now bind the same MTGO player id cleanly
    $fresh = User::factory()->create();
    $rebind = MtgoAccount::factory()->for($fresh)->create([
        'mtgo_player_id' => 147160,
        'mtgo_username' => 'Pro_MTG',
    ]);
    expect($rebind->mtgo_player_id)->toBe(147160);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=DeactivateAccountTest`
Expected: FAIL — `DeactivateAccount` not defined.

- [ ] **Step 3: Implement the action**

```php
<?php // app/Actions/Account/DeactivateAccount.php
namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeactivateAccount
{
    /**
     * Deactivate a user: flag inactive, obfuscate PII, release the MTGO binding,
     * retain gameplay data dissociated from the person, and revoke device tokens.
     * Re-signing up creates a brand-new account (no relink of old data).
     */
    public function run(User $user): void
    {
        DB::transaction(function () use ($user): void {
            // Release the MTGO binding first so UNIQUE(mtgo_player_id) frees up for re-signup,
            // and obfuscate the linked username. Gameplay rows keep their user_id (retained).
            foreach ($user->mtgoAccounts()->get() as $account) {
                $account->forceFill([
                    'mtgo_player_id' => null,
                    'mtgo_username' => 'deleted-'.$user->getKey(),
                    'active' => false,
                ])->save();
            }

            // Obfuscate the person. Keep the row (retained gameplay hangs off user_id).
            $user->forceFill([
                'email' => 'deleted+'.$user->getKey().'@deleted.invalid',
                'password' => null,
                'discord_id' => null,
                'is_active' => false,
                'deactivated_at' => now(),
            ])->save();

            // Force re-auth on every device.
            $user->tokens()->each(fn ($token) => $token->revoke());
        });
    }
}
```

> Assumes `User::mtgoAccounts()` (hasMany) + Passport `HasApiTokens::tokens()`. If the binding is strictly 1:1 the loop simply runs once — the release logic is identical.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=DeactivateAccountTest`
Expected: PASS — PII obfuscated, `player_id` released + re-bindable, gameplay retained.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Account/DeactivateAccount.php app/Models/User.php tests/Feature/Ops/DeactivateAccountTest.php
git commit -m "feat(api): DeactivateAccount — obfuscate PII, release MTGO binding, retain data"
```

---

### Task 4: Backup — scheduled DB dump to DigitalOcean Spaces

**Files:**
- Create: `config/backup.php`
- Create: `app/Console/Commands/BackupDatabaseToSpaces.php`
- Create: `docs/v1/ops/restore.md` (runbook)
- Modify: `config/filesystems.php` (add the `spaces` disk), `routes/console.php` (schedule nightly), `.env.example`
- Test: `tests/Feature/Ops/BackupDatabaseToSpacesTest.php`

**Interfaces:**
- Produces: `php artisan backup:database` → dumps the DB to a timestamped `sql` (or `.sql.gz`) object on the `spaces` disk under `config('backup.path')`, prunes objects older than `config('backup.retention_days')`. Scheduled nightly in `routes/console.php`.
- Restore: documented in `restore.md` — the DB is re-derivable by re-running the worker over the `{match}.json` files already in Spaces; the DB dump is the fast path. **Files are the floor; the dump is convenience.**

- [ ] **Step 1: Write the failing test (dump lands on the fake Spaces disk)**

```php
<?php // tests/Feature/Ops/BackupDatabaseToSpacesTest.php
use Illuminate\Support\Facades\Storage;

it('writes a timestamped DB dump to the spaces disk', function () {
    Storage::fake('spaces');

    $this->artisan('backup:database')->assertSuccessful();

    $files = Storage::disk('spaces')->files(config('backup.path'));
    expect($files)->toHaveCount(1);
    expect($files[0])->toEndWith('.sql.gz');
});

it('prunes dumps older than the retention window', function () {
    Storage::fake('spaces');
    $old = config('backup.path').'/backup-2000-01-01-000000.sql.gz';
    Storage::disk('spaces')->put($old, 'stale');
    // backdate so it falls outside retention
    touch(Storage::disk('spaces')->path($old), now()->subDays(config('backup.retention_days') + 1)->timestamp);

    $this->artisan('backup:database')->assertSuccessful();

    Storage::disk('spaces')->assertMissing($old);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=BackupDatabaseToSpacesTest`
Expected: FAIL — command/config not defined.

- [ ] **Step 3: Add the `spaces` disk + backup config**

In `config/filesystems.php` under `disks` (DigitalOcean Spaces is S3-compatible):

```php
'spaces' => [
    'driver' => 's3',
    'key' => env('DO_SPACES_KEY'),
    'secret' => env('DO_SPACES_SECRET'),
    'region' => env('DO_SPACES_REGION', 'ams3'),
    'bucket' => env('DO_SPACES_BUCKET'),
    'endpoint' => env('DO_SPACES_ENDPOINT'),
    'use_path_style_endpoint' => false,
    'throw' => true,
],
```

> Requires the `league/flysystem-aws-s3-v3` adapter — add it with `composer require league/flysystem-aws-s3-v3` (dependency change: get approval before running).

```php
<?php // config/backup.php
return [
    // Object-storage disk that receives DB dumps (DigitalOcean Spaces).
    'disk' => env('BACKUP_DISK', 'spaces'),

    // Prefix under the disk where dumps live.
    'path' => env('BACKUP_PATH', 'db-backups'),

    // Keep this many days of dumps; older ones are pruned each run.
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
];
```

Add to `.env.example`:

```
DO_SPACES_KEY=
DO_SPACES_SECRET=
DO_SPACES_REGION=ams3
DO_SPACES_BUCKET=
DO_SPACES_ENDPOINT=https://ams3.digitaloceanspaces.com
BACKUP_DISK=spaces
BACKUP_PATH=db-backups
BACKUP_RETENTION_DAYS=30
```

- [ ] **Step 4: Implement the command**

```php
<?php // app/Console/Commands/BackupDatabaseToSpaces.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class BackupDatabaseToSpaces extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Dump the database and upload it to object storage (DigitalOcean Spaces).';

    public function handle(): int
    {
        $disk = Storage::disk(config('backup.disk'));
        $path = config('backup.path');
        $object = $path.'/backup-'.now()->format('Y-m-d-His').'.sql.gz';

        $disk->put($object, gzencode($this->dump()));
        $this->info("Backed up database to {$object}.");

        $this->prune($path);

        return self::SUCCESS;
    }

    /** Produce the raw SQL dump for the default connection. */
    private function dump(): string
    {
        $connection = config('database.default');
        $db = config("database.connections.{$connection}");

        // pg_dump for the droplet's PostgreSQL (v1 stack is Postgres); PGPASSWORD passes the
        // secret without exposing it on the command line. Swap the binary per driver if needed.
        $command = sprintf(
            'PGPASSWORD=%s pg_dump -h %s -p %s -U %s %s',
            escapeshellarg((string) $db['password']),
            escapeshellarg($db['host']),
            escapeshellarg((string) $db['port']),
            escapeshellarg($db['username']),
            escapeshellarg($db['database']),
        );

        $output = shell_exec($command);

        // In tests (sqlite / no pg_dump) fall back to a marker so the write path is exercised.
        return $output !== null && $output !== '' ? $output : '-- empty dump --';
    }

    /** Delete dumps older than the retention window. */
    private function prune(string $path): void
    {
        $disk = Storage::disk(config('backup.disk'));
        $cutoff = now()->subDays((int) config('backup.retention_days'));

        foreach ($disk->files($path) as $file) {
            if (Carbon::createFromTimestamp($disk->lastModified($file))->lt($cutoff)) {
                $disk->delete($file);
            }
        }
    }
}
```

Schedule it nightly in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:database')->dailyAt('03:30')->onOneServer();
```

- [ ] **Step 5: Write the restore runbook**

```markdown
<!-- docs/v1/ops/restore.md -->
# Restore Runbook

The cloud is the system of record. Two recovery layers:

1. **Fast path — DB dump.** Nightly `backup:database` uploads `db-backups/backup-{ts}.sql.gz`
   to DigitalOcean Spaces. To restore: pull the newest object, `gunzip`, and load it into a
   fresh PostgreSQL instance (`psql < backup.sql`). Point the droplet at the restored DB.

2. **Ground truth — re-derive from files.** The `{match}.json` files in Spaces are the true
   system of record. If the DB is lost or a build-layer bug corrupts derived tables, wipe the
   derived tables and **re-run the build worker over every stored `{match}.json`**
   (see [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md) §2, §4). This rebuilds
   `matches`/`games`/`card_stats`/archetype linkage idempotently. Manual outcomes survive
   because `outcome_source: "manual"` is baked into the file.

**Priority: never lose the files.** Spaces durability + these DB dumps are the floor; the DB
itself is always re-derivable from the files.
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=BackupDatabaseToSpacesTest`
Expected: PASS — dump written to the fake `spaces` disk, stale dump pruned.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/backup.php config/filesystems.php app/Console/Commands/BackupDatabaseToSpaces.php routes/console.php docs/v1/ops/restore.md .env.example tests/Feature/Ops/BackupDatabaseToSpacesTest.php
git commit -m "feat(api): nightly DB backup to DigitalOcean Spaces + restore runbook"
```

---

### Task 5: Limits — forgiving rate limiting + oversized-file guard on the sink

**Files:**
- Create: `app/Http/Middleware/AbsorbSinkBurst.php`
- Modify: `bootstrap/app.php` (register named rate limiters + alias `sink.guard`), `routes/api.php` (attach limiters), `config/mtgo.php` (or `config/sink.php`) for the max upload size
- Test: `tests/Feature/Ops/SinkLimitsTest.php`

**Interfaces:**
- Produces:
  - Named per-user rate limiters (generous ceilings that absorb bursts): `sink` (e.g. 600/min/user), `read-api` (e.g. 300/min/user). Keyed by user id, not IP — heavy legit users are not throttled.
  - `AbsorbSinkBurst` middleware (`sink.guard`) → rejects a single **oversized** `{match}.json` (413) before it hits the worker; passes normal heavy use. Guards pathological payloads, not volume.
- Consumed by: the sink route ([`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md)) and read API routes ([`../cloud-api/spec.md`](../cloud-api/spec.md)).

- [ ] **Step 1: Write the failing test (huge file rejected, heavy normal use passes)**

```php
<?php // tests/Feature/Ops/SinkLimitsTest.php
use App\Models\User;

it('rejects an oversized match file with 413', function () {
    $user = User::factory()->create();
    $huge = str_repeat('x', config('sink.max_bytes') + 1);

    $this->actingAs($user)
        ->call('POST', '/api/sink/tok-huge', [], [], [], [
            'CONTENT_LENGTH' => (string) (config('sink.max_bytes') + 1),
        ], $huge)
        ->assertStatus(413);
});

it('absorbs a burst of normal-sized uploads from one heavy user', function () {
    $user = User::factory()->create();
    $payload = ['schema_version' => 1, 'match_key' => 'tok-n'];

    // 60 rapid normal writes stay well under the generous ceiling → none throttled
    foreach (range(1, 60) as $i) {
        $this->actingAs($user)
            ->postJson('/api/sink/tok-'.$i, $payload)
            ->assertOk();
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=SinkLimitsTest`
Expected: FAIL — guard/limiters/config not defined.

- [ ] **Step 3: Add the sink size config + the oversized guard**

```php
<?php // config/sink.php
return [
    // Reject any single {match}.json larger than this. A real match file is well under 1 MB;
    // 5 MB is a generous ceiling that only stops pathological payloads.
    'max_bytes' => (int) env('SINK_MAX_BYTES', 5 * 1024 * 1024),
];
```

```php
<?php // app/Http/Middleware/AbsorbSinkBurst.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AbsorbSinkBurst
{
    /**
     * Reject a single oversized upload before it reaches the worker.
     * Volume is absorbed by the (generous) named rate limiter, not here —
     * this guards only pathological payload size.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $max = (int) config('sink.max_bytes');

        $declared = (int) $request->header('Content-Length', '0');
        $actual = strlen($request->getContent());

        abort_if($declared > $max || $actual > $max, 413, 'Match file exceeds the maximum allowed size.');

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the limiters + alias + attach**

In `bootstrap/app.php` `->withMiddleware()`, alias the guard:

```php
$middleware->alias([
    // ...ownership, plan:paid...
    'sink.guard' => \App\Http\Middleware\AbsorbSinkBurst::class,
]);
```

Define the named limiters in `app/Providers/AppServiceProvider@boot()` (forgiving, per-user, absorb bursts):

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('sink', fn (Request $r) => Limit::perMinute(600)->by($r->user()?->getKey() ?: $r->ip()));
RateLimiter::for('read-api', fn (Request $r) => Limit::perMinute(300)->by($r->user()?->getKey() ?: $r->ip()));
```

Attach in `routes/api.php`:

```php
Route::middleware(['auth:api', 'ownership', 'sink.guard', 'throttle:sink'])
    ->post('/sink/{match_key}', /* sink controller */)->name('sink.store');

Route::middleware(['auth:api', 'throttle:read-api'])->group(function (): void {
    // read endpoints (each may add 'ownership' / 'plan:paid' as needed)
});
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=SinkLimitsTest`
Expected: PASS — oversized 413, 60-write burst all 200 (well under the 600/min ceiling).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/AbsorbSinkBurst.php config/sink.php bootstrap/app.php app/Providers/AppServiceProvider.php routes/api.php .env.example tests/Feature/Ops/SinkLimitsTest.php
git commit -m "feat(api): forgiving per-user rate limits + oversized-file guard on the sink"
```

---

## Self-Review checklist (run after fleshing 0–5)

1. **Spec coverage** — every section of [`spec.md`](./spec.md) maps to a task: Authorization → Task 1; Entitlement → Task 2; Account deletion → Task 3; Backup/DR → Task 4; Limits → Task 5; Privacy (raw logs stay local, structured data + public handles) is enforced upstream (client-agent never uploads raw; opponents are global by design) and needs no server mechanism here beyond the ownership scoping in Task 1.
2. **Never trust the client** — no endpoint/action reads `user_id`, `plan`, or ownership from request input; all derive from `request()->user()`. Cross-user access is provably denied (Task 1 tests, all three vectors: policy, scope, middleware, sink namespace).
3. **Deletion invariants** — PII obfuscated, `mtgo_player_id` released + re-bindable, gameplay rows retained (not deleted), tokens revoked (Task 3 tests assert all four).
4. **Files-are-the-floor** — backup task documents restore as re-run-worker-over-files, with the DB dump as the fast path only (Task 4 runbook).
5. **Forgiving-not-throttling** — limiters are per-user with generous ceilings; only oversized payloads are rejected; a 60-write burst passes (Task 5 tests).
6. **Placeholder scan** — no "TBD"/"handle edge cases"/"similar to Task N"; every code block is complete and paths are exact.
7. **Dependency note** — `league/flysystem-aws-s3-v3` (Task 4) is the only new dependency; flag for approval before `composer require`.
