# Cloud Auth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Format:** each task is a bite-sized TDD loop — write the failing test, run it red, implement, run it green, Pint, commit. No placeholders; every path is exact; every code block is complete.

**Goal:** Stand up the `../api` Laravel app as an **OAuth2 authorization server** (Laravel Passport) that issues per-device, refreshable, revocable tokens to the desktop client via **Authorization Code + PKCE** (public client, no secret). Add email/password registration + login and **server-side** Discord OAuth (Socialite; Discord secret never leaves the server). Model app identity (`users` with `plan`/`is_active`/`deactivated_at`) and bind it **strictly 1:1** to an MTGO identity (`mtgo_accounts`).

**Architecture:** Passport lives on the Laravel 13 app in `/Volumes/Dev/mymtgo/api`, backed by a **NEW PostgreSQL database** on a new host (see [`../overview/spec.md`](../overview/spec.md) §8). v1 cloud = a NEW Postgres database; 0.x is frozen on its own subdomain + existing DB (no server-side migration). The auth model is built **fresh** on the new DB — clean v1 migrations for `users` (with the identity columns from [`../ops/spec.md`](../ops/spec.md)) and a new `mtgo_accounts` table — **not** additions to the live 0.x DB. The proven ingestion/catalog logic in `../api` is reused (ported), but Passport/PKCE auth is new; the old Sanctum device-key API stays with 0.x. We add Passport as the token-issuing OAuth server and expose the login screen the client's auth window loads. The client side (auth window, PKCE verifier, `mymtgo://` deep-link, token storage) is built separately per [`../client-auth/spec.md`](../client-auth/spec.md); this plan is the **server half** it talks to.

**Tech Stack:** PHP 8.3, Laravel 13, PostgreSQL, Laravel Passport (framework-bundled, installed via `install:api --passport`), Laravel Socialite (Discord), Inertia v2 (existing login view), Spatie Laravel Data (DTOs), Pest v4, Pint.

## Global Constraints

- **Target project is `/Volumes/Dev/mymtgo/api`** — an existing Laravel 13 app repo, but v1 runs on a **NEW PostgreSQL database on a new host**. Stack: **Laravel 13 / PHP 8.3 / PostgreSQL**, DigitalOcean Spaces (`s3` disk) + Horizon (Redis) (see [`../overview/spec.md`](../overview/spec.md) §8). v1 cloud = a NEW Postgres database; 0.x is frozen on its own subdomain + existing DB (no server-side migration). All paths below are relative to that repo. Do **not** touch `/Volumes/Dev/mymtgo/client` (that's the desktop app).
- **Reuse code, not the DB.** The `users` + `mtgo_accounts` migrations here are clean v1 migrations on the new Postgres DB — **not** additions to the live 0.x DB. Passport/PKCE auth is built fresh (the old Sanctum device-key model stays with 0.x); the proven ingestion/catalog logic in `../api` is ported (see [`../overview/spec.md`](../overview/spec.md) §6, §8).
- **Postgres schema choices:** `plan` (and any status enums) as string + `casts()` (via `App\Enums\UserPlan`), not native PG enums; `mtgo_accounts` conditional uniqueness as **partial unique indexes** (`UNIQUE(mtgo_player_id) WHERE mtgo_player_id IS NOT NULL`, `UNIQUE(user_id) WHERE user_id IS NOT NULL`) so released bindings on deactivation don't collide; idempotent upserts via `INSERT ... ON CONFLICT`.
- **Passport is the OAuth2 server** — the API issues tokens; it is not an OAuth *client* of anyone except Discord. The desktop client is a **public PKCE client** (no secret ships on device). The web app keeps session auth (Fortify) — do not migrate it to tokens.
- **Discord secret is server-only** — `DISCORD_CLIENT_SECRET` lives in `../api/.env`, read via `config('services.discord')`, never returned to any client. Use Socialite `->stateless()` (the auth window is a fresh browser context, no session cookie guaranteed).
- **Redirect target is the client's custom scheme** — on successful auth the `/oauth/authorize` approval redirects the browser to `mymtgo://oauth/callback?code=...`. `mymtgo` must be an allowed redirect URI on the first-party PKCE client.
- **Strict 1:1 MTGO binding** — `mtgo_accounts` has a partial `UNIQUE(mtgo_player_id) WHERE mtgo_player_id IS NOT NULL` (one MTGO account ↔ one app account, globally) **and** partial `UNIQUE(user_id) WHERE user_id IS NOT NULL` (one MTGO identity per app account); the columns are nullable so a deactivation-released binding does not collide. Binding is by the stable numeric `mtgo_player_id`; `mtgo_username` is a display attribute. The username/player-id is read from logs and is **non-editable** — the bind endpoint takes it from the push, never from user input beyond the token's owner scope.
- **Deletion obfuscation is referenced, not built here** — the `users` columns (`is_active`, `deactivated_at`) and the released-`mtgo_player_id` semantics come from [`../ops/spec.md`](../ops/spec.md); the deletion *mechanism* (obfuscate PII, release player id, retain anonymized gameplay) is an ops deliverable. This plan only lands the columns + `is_active` login gate so deletion has somewhere to write.
- **Server never trusts the client for scoping** ([`../ops/spec.md`](../ops/spec.md)) — every token-guarded endpoint resolves the user from the token (`$request->user()`), never from a body-supplied user id.
- Use **single-action invokable controllers** by domain (matches the app's existing `app/Http/Controllers/{Domain}/…Controller.php` convention). Form Request classes for validation, not inline `$request->validate()` in controllers. PHP 8 constructor promotion, explicit return types, curly braces on all control structures.
- Run `vendor/bin/pint --dirty --format agent` before finalizing any task.
- Tests: Pest v4 feature tests in `tests/Feature/Auth/`, unit where pure. `tests/Pest.php` binds `Feature` to `Tests\TestCase`; **enable `RefreshDatabase`** for the auth suite (Passport + new tables need a migrated schema — the file currently has it commented out; add it scoped to `Feature/Auth` via an `uses()` line, do not flip it globally). Fake Discord with `Socialite::fake()`; drive the PKCE exchange with a real generated verifier/challenge and `Http`/route calls.
- Discord + Passport env keys are added to `.env.example` only (never commit real `.env`; `.env` is permission-denied — the maintainer edits secrets manually).

---

## File Structure

**New:**
- `database/migrations/2026_07_01_000001_add_identity_columns_to_users_table.php` — nullable `password`, `discord_id`, `plan`, `is_active`, `deactivated_at`.
- `database/migrations/2026_07_01_000002_create_mtgo_accounts_table.php` — the 1:1 binding table.
- `app/Models/MtgoAccount.php` + `database/factories/MtgoAccountFactory.php`.
- `app/Enums/UserPlan.php` — `Free` | `Paid`.
- `app/Providers/PassportServiceProvider.php` — token lifetimes + `Passport::authorizationView()` (renders the login screen).
- `app/Http/Controllers/Auth/RegisterController.php` — email/password registration.
- `app/Http/Controllers/Auth/LoginController.php` — email/password login (posts the API-served login screen).
- `app/Http/Controllers/Auth/DiscordRedirectController.php` + `app/Http/Controllers/Auth/DiscordCallbackController.php` — server-side Discord handshake.
- `app/Http/Controllers/Auth/RevokeDeviceController.php` — revoke one device's tokens.
- `app/Http/Controllers/Mtgo/BindMtgoAccountController.php` — 1:1 identity binding from a push.
- `app/Actions/Auth/FindOrCreateDiscordUser.php` — find-or-create by `discord_id`.
- `app/Actions/Mtgo/BindMtgoAccount.php` — the strict-1:1 bind action.
- `app/Http/Requests/Auth/RegisterRequest.php`, `LoginRequest.php`, `app/Http/Requests/Mtgo/BindMtgoAccountRequest.php`.
- `app/Data/AuthUserData.php`, `app/Data/MtgoAccountData.php` — response DTOs.
- `resources/views/auth/oauth-authorize.blade.php` — the Passport approval/login screen served at `/oauth/authorize`.
- `config/services.php` (modify) — `discord` credentials block.
- `config/auth.php` (modify) — `api` guard, `driver => passport`.
- `app/Models/User.php` (modify) — `OAuthenticatable` + `HasApiTokens`, new fillable/casts, `mtgoAccount()` relation.
- `bootstrap/providers.php` (modify) — register `PassportServiceProvider`.
- `routes/web.php` (modify) — Discord + register/login routes served to the auth window.
- `routes/api.php` (modify) — `auth:api` token-guarded revoke + MTGO bind endpoints.
- `.env.example` (modify) — Discord + Passport keys.

**Untouched (leave as-is):** the whole existing ingest/metagame/tournament stack, the Sanctum device-key API group, the Fortify web `auth/Login` Inertia view.

---

### Task 1: Install Passport as an OAuth2 server + register the first-party public PKCE client

**Files:**
- Run: `php artisan install:api --passport` (publishes + runs Passport migrations, generates encryption keys)
- Modify: `config/auth.php` (add the `api` passport guard)
- Modify: `app/Models/User.php` (`implements OAuthenticatable`, `use HasApiTokens`)
- Create: `app/Providers/PassportServiceProvider.php` (token lifetimes)
- Modify: `bootstrap/providers.php` (register the provider)
- Modify: `.env.example` (`PASSPORT_*` placeholders)
- Test: `tests/Feature/Auth/PassportServerTest.php`

**Interfaces:**
- Produces: a working OAuth2 server — `/oauth/authorize` + `/oauth/token` routes exist, `auth:api` guard resolves a Bearer token to a `User`, and a first-party **public** PKCE client is seeded (client id known to the desktop app, no secret). Consumed by Tasks 5, 6, 7.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Auth/PassportServerTest.php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('exposes the oauth authorize + token routes', function () {
    $routes = collect(app('router')->getRoutes())->map->uri();

    expect($routes)->toContain('oauth/authorize');
    expect($routes)->toContain('oauth/token');
});

it('authenticates a bearer token against the api guard', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    // a trivial api route protected by auth:api resolves the user
    $this->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
});
```

(The `/api/auth/me` route is added in Step 4 as the smallest possible `auth:api` probe; it is reused by the revoke task.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PassportServerTest`
Expected: FAIL — Passport not installed, no `api` guard, `/api/auth/me` missing.

- [ ] **Step 3: Install Passport + wire the guard**

```bash
composer require laravel/passport
php artisan install:api --passport --no-interaction
```

In `config/auth.php`, add the `api` guard alongside the existing `web` guard:

```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],

    'api' => [
        'driver' => 'passport',
        'provider' => 'users',
    ],
],
```

- [ ] **Step 4: Make `User` OAuth-authenticatable + add the probe route**

Edit `app/Models/User.php` — add the interface + trait:

```php
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    // ... existing body unchanged
}
```

In `routes/api.php`, add (top of file, above the Sanctum device-key group):

```php
use App\Data\AuthUserData;

Route::middleware('auth:api')->get('/auth/me', function (\Illuminate\Http\Request $request) {
    return AuthUserData::from($request->user());
})->name('auth.me');
```

`AuthUserData` is the minimal DTO created in Task 2 (Step 3); if running tasks out of order, stub it as `Spatie\LaravelData\Data` with `public int $id` for now.

- [ ] **Step 5: Token lifetimes + register the provider**

Create `app/Providers/PassportServiceProvider.php`:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class PassportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Short-lived access token; long refresh so the desktop client
        // stays signed in and refreshes silently (see ../client-auth/spec.md).
        Passport::tokensExpireIn(now()->addDays(15));
        Passport::refreshTokensExpireIn(now()->addDays(60));
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));
    }
}
```

Add it to `bootstrap/providers.php`:

```php
use App\Providers\PassportServiceProvider;
// ... in the returned array:
    PassportServiceProvider::class,
```

- [ ] **Step 6: Seed the first-party public PKCE client**

Create the desktop client (no secret) so the client app has a stable `client_id`:

```bash
php artisan passport:client --public --name="MyMTGO Desktop" --redirect_uri="mymtgo://oauth/callback" --no-interaction
```

Record the printed `Client ID` — the desktop app hardcodes it (public clients carry no secret). Add `PASSPORT_DESKTOP_CLIENT_ID=` to `.env.example` so the value has a documented home. This step is a one-time deploy action; note in the commit that production must run the same command against its DB.

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --compact --filter=PassportServerTest`
Expected: PASS — routes present, Bearer resolves the user.

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/auth.php app/Models/User.php app/Providers/PassportServiceProvider.php bootstrap/providers.php routes/api.php composer.json composer.lock .env.example tests/Feature/Auth/PassportServerTest.php
git commit -m "feat(api): install Passport OAuth2 server + first-party public PKCE client"
```

---

### Task 2: Identity columns on `users` + `plan` enum + response DTO

**Files:**
- Create: `database/migrations/2026_07_01_000001_add_identity_columns_to_users_table.php`
- Create: `app/Enums/UserPlan.php`
- Create: `app/Data/AuthUserData.php`
- Modify: `app/Models/User.php` (fillable, casts, `password` nullable semantics)
- Modify: `database/factories/UserFactory.php` (states for discord-only + paid)
- Test: `tests/Feature/Auth/UserIdentityColumnsTest.php`

**Interfaces:**
- Produces: `users` gains nullable `password`, nullable `discord_id` (unique), `plan` (`free`|`paid`, default `free`), `is_active` (default true), nullable `deactivated_at`. `User::$casts` maps `plan` → `UserPlan` and `is_active` → bool. `AuthUserData` is the token-user response shape. Consumed by Tasks 3, 4, 6.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Auth/UserIdentityColumnsTest.php

use App\Enums\UserPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults a new user to the free plan and active', function () {
    $user = User::factory()->create();

    expect($user->plan)->toBe(UserPlan::Free);
    expect($user->is_active)->toBeTrue();
    expect($user->deactivated_at)->toBeNull();
});

it('allows a discord-only user with a null password', function () {
    $user = User::factory()->discord()->create();

    expect($user->password)->toBeNull();
    expect($user->discord_id)->not->toBeNull();
});

it('enforces a unique discord_id', function () {
    User::factory()->create(['discord_id' => '999']);

    expect(fn () => User::factory()->create(['discord_id' => '999']))
        ->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=UserIdentityColumnsTest`
Expected: FAIL — columns/enum/factory states missing.

- [ ] **Step 3: Migration, enum, DTO, model, factory**

Migration `database/migrations/2026_07_01_000001_add_identity_columns_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('discord_id')->nullable()->unique()->after('email');
            $table->string('plan')->default('free')->after('discord_id');
            $table->boolean('is_active')->default(true)->after('plan');
            $table->timestamp('deactivated_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['discord_id']);
            $table->dropColumn(['discord_id', 'plan', 'is_active', 'deactivated_at']);
            // password stays nullable on rollback — non-destructive.
        });
    }
};
```

> Note: `->change()` on `password` requires all prior attributes be restated (Laravel 11+ rule, applies on Laravel 13); `string('password')->nullable()` is the full new definition — it was `string('password')` (not-null) before.

Enum `app/Enums/UserPlan.php`:

```php
<?php

namespace App\Enums;

enum UserPlan: string
{
    case Free = 'free';
    case Paid = 'paid';
}
```

DTO `app/Data/AuthUserData.php`:

```php
<?php

namespace App\Data;

use App\Enums\UserPlan;
use App\Models\User;
use Spatie\LaravelData\Data;

class AuthUserData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $email,
        public UserPlan $plan,
        public bool $isActive,
        public bool $hasMtgoBinding,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            plan: $user->plan,
            isActive: $user->is_active,
            hasMtgoBinding: $user->mtgoAccount()->exists(),
        );
    }
}
```

Edit `app/Models/User.php` — extend fillable + casts and add the relation (added fully in Task 5, stub here):

```php
protected $fillable = ['name', 'email', 'password', 'is_admin', 'discord_id', 'plan', 'is_active', 'deactivated_at'];

protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_admin' => 'bool',
        'plan' => \App\Enums\UserPlan::class,
        'is_active' => 'bool',
        'deactivated_at' => 'datetime',
    ];
}

public function mtgoAccount(): \Illuminate\Database\Eloquent\Relations\HasOne
{
    return $this->hasOne(\App\Models\MtgoAccount::class);
}
```

Edit `database/factories/UserFactory.php` — add a `discord()` state:

```php
public function discord(): static
{
    return $this->state(fn () => [
        'password' => null,
        'discord_id' => (string) fake()->unique()->numberBetween(10_000, 99_999_999),
    ]);
}

public function paid(): static
{
    return $this->state(fn () => ['plan' => \App\Enums\UserPlan::Paid]);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=UserIdentityColumnsTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_01_000001_add_identity_columns_to_users_table.php app/Enums/UserPlan.php app/Data/AuthUserData.php app/Models/User.php database/factories/UserFactory.php tests/Feature/Auth/UserIdentityColumnsTest.php
git commit -m "feat(api): add identity columns (plan/is_active/deactivated_at/discord_id) to users"
```

---

### Task 3: Email/password registration + login (the API-served auth screen)

**Files:**
- Create: `app/Http/Requests/Auth/RegisterRequest.php`, `app/Http/Requests/Auth/LoginRequest.php`
- Create: `app/Http/Controllers/Auth/RegisterController.php`, `app/Http/Controllers/Auth/LoginController.php`
- Modify: `routes/web.php` (register/login POST routes the auth window uses)
- Test: `tests/Feature/Auth/EmailPasswordAuthTest.php`

**Interfaces:**
- Consumes: the `users` schema (Task 2).
- Produces: `POST /register` creates an active `free` user with a hashed password and logs them into the `web` session (so the subsequent `/oauth/authorize` approval is authenticated). `POST /login` authenticates an existing email/password user; **rejects deactivated (`is_active=false`) accounts**; rejects Discord-only accounts (null password). Consumed by Task 5 (authorize step assumes a session-authenticated user).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Auth/EmailPasswordAuthTest.php

use App\Enums\UserPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('registers a new email/password user as active + free and signs them in', function () {
    $response = $this->post('/register', [
        'name' => 'Pro MTG',
        'email' => 'pro@example.com',
        'password' => 'password-123',
        'password_confirmation' => 'password-123',
    ]);

    $this->assertAuthenticated();
    $user = User::where('email', 'pro@example.com')->firstOrFail();
    expect($user->plan)->toBe(UserPlan::Free);
    expect($user->is_active)->toBeTrue();
    expect(Hash::check('password-123', $user->password))->toBeTrue();
});

it('logs in an existing user with valid credentials', function () {
    User::factory()->create(['email' => 'a@b.com', 'password' => Hash::make('secret-123')]);

    $this->post('/login', ['email' => 'a@b.com', 'password' => 'secret-123'])
        ->assertRedirect();

    $this->assertAuthenticated();
});

it('rejects a deactivated account', function () {
    User::factory()->create([
        'email' => 'gone@b.com',
        'password' => Hash::make('secret-123'),
        'is_active' => false,
        'deactivated_at' => now(),
    ]);

    $this->post('/login', ['email' => 'gone@b.com', 'password' => 'secret-123'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('rejects login for a discord-only account (null password)', function () {
    User::factory()->discord()->create(['email' => 'disc@b.com']);

    $this->post('/login', ['email' => 'disc@b.com', 'password' => 'whatever-123'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=EmailPasswordAuthTest`
Expected: FAIL — routes/controllers/requests missing.

- [ ] **Step 3: Requests**

`app/Http/Requests/Auth/RegisterRequest.php`:

```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
```

`app/Http/Requests/Auth/LoginRequest.php`:

```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
```

- [ ] **Step 4: Controllers**

`app/Http/Controllers/Auth/RegisterController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request): RedirectResponse
    {
        $user = User::create([
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'password' => Hash::make($request->string('password')),
            'plan' => UserPlan::Free,
            'is_active' => true,
        ]);

        Auth::login($user);

        // Bounce back to the pending /oauth/authorize request the auth window opened.
        return redirect()->intended(route('home'));
    }
}
```

`app/Http/Controllers/Auth/LoginController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): RedirectResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        $invalid = $user === null
            || $user->password === null            // discord-only account
            || ! Hash::check((string) $request->string('password'), $user->password);

        if ($invalid || ! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }
}
```

- [ ] **Step 5: Routes**

In `routes/web.php`, add above the admin group:

```php
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;

Route::post('/register', RegisterController::class)->middleware('guest')->name('register.store');
Route::post('/login', LoginController::class)->middleware('guest')->name('login.store');
```

> The GET login *screen* is the Passport authorization view from Task 5 (it renders the Discord button + email/password form). These POST routes handle its submissions. Fortify's existing `auth/Login` Inertia view is the web app's own login and is left untouched.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=EmailPasswordAuthTest`
Expected: PASS.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/Auth app/Http/Controllers/Auth/RegisterController.php app/Http/Controllers/Auth/LoginController.php routes/web.php tests/Feature/Auth/EmailPasswordAuthTest.php
git commit -m "feat(api): email/password registration + login with deactivation + discord-only guards"
```

---

### Task 4: Discord OAuth via Socialite (server-side handshake, find-or-create)

**Files:**
- Run: `composer require laravel/socialite`
- Create: `app/Actions/Auth/FindOrCreateDiscordUser.php`
- Create: `app/Http/Controllers/Auth/DiscordRedirectController.php`, `app/Http/Controllers/Auth/DiscordCallbackController.php`
- Modify: `config/services.php` (`discord` block), `routes/web.php` (redirect + callback), `.env.example`
- Test: `tests/Feature/Auth/DiscordAuthTest.php`

**Interfaces:**
- Consumes: the `users` schema (Task 2), Socialite. The Discord **secret lives only in `config('services.discord.client_secret')`** and is never emitted.
- Produces: `GET /auth/discord/redirect` → stateless Socialite redirect to Discord. `GET /auth/discord/callback` → `FindOrCreateDiscordUser::run($socialiteUser)` (match on `discord_id`, else create an active `free`, password-null user), logs them into the `web` session, redirects to the pending `/oauth/authorize`. Consumed by Task 5.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Auth/DiscordAuthTest.php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

it('redirects to discord', function () {
    Socialite::fake('discord');

    $this->get('/auth/discord/redirect')->assertRedirect();
});

it('creates a discord user on first callback and signs them in', function () {
    Socialite::fake('discord', (new SocialiteUser)->map([
        'id' => 'discord-abc',
        'name' => 'DiscordPlayer',
        'email' => 'dp@example.com',
    ]));

    $this->get('/auth/discord/callback')->assertRedirect();

    $this->assertAuthenticated();
    $user = User::where('discord_id', 'discord-abc')->firstOrFail();
    expect($user->password)->toBeNull();
    expect($user->plan->value)->toBe('free');
});

it('reuses the existing account on repeat discord login', function () {
    $existing = User::factory()->create(['discord_id' => 'discord-abc', 'password' => null]);

    Socialite::fake('discord', (new SocialiteUser)->map([
        'id' => 'discord-abc',
        'name' => 'DiscordPlayer',
        'email' => 'dp@example.com',
    ]));

    $this->get('/auth/discord/callback')->assertRedirect();

    expect(User::where('discord_id', 'discord-abc')->count())->toBe(1);
    $this->assertAuthenticatedAs($existing);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=DiscordAuthTest`
Expected: FAIL — Socialite/controllers/config/routes missing.

- [ ] **Step 3: Install Socialite + config**

```bash
composer require laravel/socialite
```

In `config/services.php`, add:

```php
'discord' => [
    'client_id' => env('DISCORD_CLIENT_ID'),
    'client_secret' => env('DISCORD_CLIENT_SECRET'),
    'redirect' => env('DISCORD_REDIRECT_URI'),
],
```

> Discord is not a built-in Socialite provider — add the community driver `composer require socialiteproviders/discord` and register its event listener per SocialiteProviders (in `AppServiceProvider::boot()` via `Event::listen(SocialiteWasCalled::class, ...)`), OR register the driver inline. Wire whichever the team prefers; the controllers below call `Socialite::driver('discord')` either way. Add `DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET`, `DISCORD_REDIRECT_URI` to `.env.example` (values blank).

- [ ] **Step 4: Find-or-create action**

`app/Actions/Auth/FindOrCreateDiscordUser.php`:

```php
<?php

namespace App\Actions\Auth;

use App\Enums\UserPlan;
use App\Models\User;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class FindOrCreateDiscordUser
{
    public function run(SocialiteUser $discordUser): User
    {
        return User::firstOrCreate(
            ['discord_id' => $discordUser->getId()],
            [
                'name' => $discordUser->getNickname() ?: $discordUser->getName() ?: 'Discord User',
                'email' => $discordUser->getEmail(),
                'password' => null,
                'plan' => UserPlan::Free,
                'is_active' => true,
            ],
        );
    }
}
```

- [ ] **Step 5: Controllers + routes**

`app/Http/Controllers/Auth/DiscordRedirectController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;

class DiscordRedirectController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return Socialite::driver('discord')->stateless()->redirect();
    }
}
```

`app/Http/Controllers/Auth/DiscordCallbackController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\FindOrCreateDiscordUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class DiscordCallbackController extends Controller
{
    public function __construct(private FindOrCreateDiscordUser $findOrCreate) {}

    public function __invoke(): RedirectResponse
    {
        $discordUser = Socialite::driver('discord')->stateless()->user();

        $user = $this->findOrCreate->run($discordUser);

        Auth::login($user, remember: true);

        // Resume the /oauth/authorize request the auth window was on.
        return redirect()->intended(route('home'));
    }
}
```

In `routes/web.php`:

```php
use App\Http\Controllers\Auth\DiscordCallbackController;
use App\Http\Controllers\Auth\DiscordRedirectController;

Route::get('/auth/discord/redirect', DiscordRedirectController::class)->name('discord.redirect');
Route::get('/auth/discord/callback', DiscordCallbackController::class)->name('discord.callback');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=DiscordAuthTest`
Expected: PASS.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add composer.json composer.lock config/services.php app/Actions/Auth/FindOrCreateDiscordUser.php app/Http/Controllers/Auth/Discord*.php routes/web.php .env.example tests/Feature/Auth/DiscordAuthTest.php
git commit -m "feat(api): server-side Discord OAuth via Socialite with find-or-create"
```

---

### Task 5: The `/oauth/authorize` + `/oauth/token` PKCE flow + `mymtgo://` redirect

**Files:**
- Create: `resources/views/auth/oauth-authorize.blade.php` (the login screen served at `/oauth/authorize`)
- Modify: `app/Providers/PassportServiceProvider.php` (`Passport::authorizationView(...)`)
- Test: `tests/Feature/Auth/PkceFlowTest.php`

**Interfaces:**
- Consumes: the seeded public PKCE client (Task 1), a session-authenticated user (Tasks 3/4).
- Produces: the full Authorization Code + PKCE handshake — an authenticated user hitting `/oauth/authorize?...&code_challenge=...&code_challenge_method=S256` gets an auth code redirected to `mymtgo://oauth/callback?code=...`; exchanging that `code` + `code_verifier` at `POST /oauth/token` yields `access_token` + `refresh_token`. Unauthenticated users see the login screen (Discord button + email/password form). Consumed by Task 6 (refresh).

- [ ] **Step 1: Write the failing test (full PKCE code exchange)**

```php
<?php // tests/Feature/Auth/PkceFlowTest.php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

uses(RefreshDatabase::class);

function pkcePair(): array
{
    $verifier = Str::random(128);
    $challenge = strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_');

    return [$verifier, $challenge];
}

function desktopPkceClient(): Client
{
    return app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'MyMTGO Desktop Test',
        redirectUris: ['mymtgo://oauth/callback'],
        confidential: false, // public client → PKCE, no secret
    );
}

it('runs the authorization-code + PKCE exchange to access + refresh tokens', function () {
    $user = User::factory()->create();
    $client = desktopPkceClient();
    [$verifier, $challenge] = pkcePair();
    $state = Str::random(40);

    // 1. Authenticated user approves the authorize request.
    $authorize = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'mymtgo://oauth/callback',
        'response_type' => 'code',
        'scope' => '',
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]));

    // Passport auto-approves a first-party client and redirects with a code.
    $authorize->assertRedirect();
    $location = $authorize->headers->get('Location');
    expect($location)->toStartWith('mymtgo://oauth/callback');
    parse_str(parse_url($location, PHP_URL_QUERY), $params);
    expect($params['state'])->toBe($state);

    // 2. Exchange the code + verifier for tokens.
    $token = $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => 'mymtgo://oauth/callback',
        'code_verifier' => $verifier,
        'code' => $params['code'],
    ]);

    $token->assertOk()
        ->assertJsonStructure(['token_type', 'expires_in', 'access_token', 'refresh_token']);
});

it('rejects the exchange when the verifier does not match the challenge', function () {
    $user = User::factory()->create();
    $client = desktopPkceClient();
    [, $challenge] = pkcePair();

    $authorize = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'mymtgo://oauth/callback',
        'response_type' => 'code',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]));
    parse_str(parse_url($authorize->headers->get('Location'), PHP_URL_QUERY), $params);

    $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => 'mymtgo://oauth/callback',
        'code_verifier' => 'the-wrong-verifier-entirely-'.Str::random(80),
        'code' => $params['code'],
    ])->assertStatus(400);
});
```

> If Passport does not auto-approve a first-party client in the test env, add `->withoutMiddleware()` is **not** the fix — instead set the client's `first_party` behaviour or POST the approval to `/oauth/authorize` (`approve`). Prefer configuring the created client so approval is implicit; keep the assertions on the `mymtgo://` redirect + token structure.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PkceFlowTest`
Expected: FAIL initially if the authorization view is unset (Passport throws when rendering the approval prompt for an unauthenticated/unapproved request). The token-exchange assertions confirm PKCE once the view + client are wired.

- [ ] **Step 3: Authorization (login) view**

Create `resources/views/auth/oauth-authorize.blade.php` — the screen the auth window lands on when the user is **not** yet session-authenticated. It offers the two methods from [`../client-auth/spec.md`](../client-auth/spec.md):

```blade
{{-- resources/views/auth/oauth-authorize.blade.php --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in to MyMTGO</title>
</head>
<body>
    <main>
        <h1>Sign in to MyMTGO</h1>

        {{-- Discord: server-side handshake, secret never leaves the API --}}
        <a href="{{ route('discord.redirect') }}">Continue with Discord</a>

        {{-- Email / password --}}
        <form method="POST" action="{{ route('login.store') }}">
            @csrf
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Sign in</button>
        </form>

        <form method="POST" action="{{ route('register.store') }}">
            @csrf
            <input type="text" name="name" placeholder="Name" required>
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <input type="password" name="password_confirmation" placeholder="Confirm password" required>
            <button type="submit">Create account</button>
        </form>
    </main>
</body>
</html>
```

> Because the desktop client is first-party, we want silent approval (no consent screen) once authenticated. Register `Passport::authorizationView()` returning this Blade view so the *unauthenticated* case shows the login screen; the authenticated first-party case skips consent and redirects straight to `mymtgo://`.

In `PassportServiceProvider::boot()` add:

```php
use Illuminate\Support\Facades\View;

Passport::authorizationView(fn ($parameters) => view('auth.oauth-authorize', $parameters));
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=PkceFlowTest`
Expected: PASS — redirect to `mymtgo://oauth/callback?code=...&state=...`, token exchange returns access + refresh, verifier-mismatch is a 400.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/auth/oauth-authorize.blade.php app/Providers/PassportServiceProvider.php tests/Feature/Auth/PkceFlowTest.php
git commit -m "feat(api): oauth authorize+token PKCE flow with mymtgo:// redirect + login screen"
```

---

### Task 6: Token refresh + per-device revocation

**Files:**
- Create: `app/Http/Controllers/Auth/RevokeDeviceController.php`
- Modify: `routes/api.php` (`auth:api` revoke route)
- Test: `tests/Feature/Auth/TokenRefreshRevokeTest.php`

**Interfaces:**
- Consumes: the PKCE client + issued tokens (Task 5), the `auth:api` guard (Task 1).
- Produces: `POST /oauth/token` with `grant_type=refresh_token` returns a fresh access + refresh pair (silent refresh — no re-login). `DELETE /api/auth/device` (token-guarded) revokes **the current device's** access token + its refresh token, forcing re-auth on that device only. Enforced server-side from `$request->user()->token()` — never a client-supplied id.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Auth/TokenRefreshRevokeTest.php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

uses(RefreshDatabase::class);

function issueTokensFor(User $user): array
{
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Device', redirectUris: ['mymtgo://oauth/callback'], confidential: false,
    );

    $verifier = Str::random(128);
    $challenge = strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_');

    $authorize = test()->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(), 'redirect_uri' => 'mymtgo://oauth/callback',
        'response_type' => 'code', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]));
    parse_str(parse_url($authorize->headers->get('Location'), PHP_URL_QUERY), $p);

    $json = test()->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $client->getKey(),
        'redirect_uri' => 'mymtgo://oauth/callback', 'code_verifier' => $verifier, 'code' => $p['code'],
    ])->json();

    return [$client, $json];
}

it('refreshes an access token without re-login', function () {
    $user = User::factory()->create();
    [$client, $tokens] = issueTokensFor($user);

    $refreshed = $this->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $tokens['refresh_token'],
        'client_id' => $client->getKey(),
        'scope' => '',
    ]);

    $refreshed->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
    expect($refreshed->json('access_token'))->not->toBe($tokens['access_token']);
});

it('revokes the current device and rejects its access token afterwards', function () {
    $user = User::factory()->create();
    [, $tokens] = issueTokensFor($user);
    $bearer = ['Authorization' => 'Bearer '.$tokens['access_token']];

    // token works before revoke
    $this->withHeaders($bearer)->getJson('/api/auth/me')->assertOk();

    $this->withHeaders($bearer)->deleteJson('/api/auth/device')->assertOk();

    // same token rejected after revoke
    $this->withHeaders($bearer)->getJson('/api/auth/me')->assertUnauthorized();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=TokenRefreshRevokeTest`
Expected: FAIL — `DELETE /api/auth/device` route/controller missing. (The refresh test may already pass — Passport ships refresh; keep it as a regression guard.)

- [ ] **Step 3: Revoke controller + route**

`app/Http/Controllers/Auth/RevokeDeviceController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevokeDeviceController extends Controller
{
    /**
     * Revoke only the token that authenticated this request (this device),
     * plus its refresh token. Forces re-auth on this device only.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->user()->token();

        $token->refreshToken?->revoke();
        $token->revoke();

        return response()->json(['revoked' => true]);
    }
}
```

In `routes/api.php`, inside the `auth:api` group (add one if only the `/auth/me` line exists):

```php
use App\Http\Controllers\Auth\RevokeDeviceController;

Route::middleware('auth:api')->group(function () {
    Route::get('/auth/me', /* ... from Task 1 ... */)->name('auth.me');
    Route::delete('/auth/device', RevokeDeviceController::class)->name('auth.device.revoke');
});
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=TokenRefreshRevokeTest`
Expected: PASS — refresh yields a new access token; revoked access token is unauthorized afterward.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Auth/RevokeDeviceController.php routes/api.php tests/Feature/Auth/TokenRefreshRevokeTest.php
git commit -m "feat(api): silent token refresh + per-device token revocation"
```

---

### Task 7: MTGO identity binding endpoint (strict 1:1)

**Files:**
- Create: `database/migrations/2026_07_01_000002_create_mtgo_accounts_table.php`
- Create: `app/Models/MtgoAccount.php`, `database/factories/MtgoAccountFactory.php`
- Create: `app/Data/MtgoAccountData.php`
- Create: `app/Http/Requests/Mtgo/BindMtgoAccountRequest.php`
- Create: `app/Actions/Mtgo/BindMtgoAccount.php`
- Create: `app/Http/Controllers/Mtgo/BindMtgoAccountController.php`
- Modify: `routes/api.php` (`auth:api` bind route)
- Test: `tests/Feature/Auth/BindMtgoAccountTest.php`

**Interfaces:**
- Consumes: the token-authenticated `User` (Task 1), the `mtgo_player_id`/`mtgo_username` read from the client's logs and sent on the push ([`../client-auth/spec.md`](../client-auth/spec.md)).
- Produces: `POST /api/mtgo/bind` binds `{mtgo_player_id, mtgo_username}` to the current user, **strictly 1:1**: `UNIQUE(mtgo_player_id)` globally + `UNIQUE(user_id)`. Re-binding the *same* player id to the *same* user is idempotent (updates `mtgo_username`); binding a player id already owned by a *different* user is **rejected 409**; binding a *different* player id to a user already bound is **rejected 409**. Never trusts a body-supplied user id — scope is the token owner.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Auth/BindMtgoAccountTest.php

use App\Models\MtgoAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

it('binds an mtgo identity to the authenticated user', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson('/api/mtgo/bind', ['mtgo_player_id' => 147160, 'mtgo_username' => 'Pro_MTG'])
        ->assertOk()
        ->assertJsonPath('data.mtgoPlayerId', 147160)
        ->assertJsonPath('data.mtgoUsername', 'Pro_MTG');

    expect(MtgoAccount::where('user_id', $user->id)->count())->toBe(1);
});

it('is idempotent for the same user + same player id (updates username)', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson('/api/mtgo/bind', ['mtgo_player_id' => 147160, 'mtgo_username' => 'Old_Name'])->assertOk();
    $this->postJson('/api/mtgo/bind', ['mtgo_player_id' => 147160, 'mtgo_username' => 'New_Name'])->assertOk();

    expect(MtgoAccount::where('user_id', $user->id)->count())->toBe(1);
    expect(MtgoAccount::where('user_id', $user->id)->first()->mtgo_username)->toBe('New_Name');
});

it('rejects binding a player id already owned by another user (409)', function () {
    $other = User::factory()->create();
    MtgoAccount::factory()->for($other)->create(['mtgo_player_id' => 147160]);

    $me = User::factory()->create();
    Passport::actingAs($me);

    $this->postJson('/api/mtgo/bind', ['mtgo_player_id' => 147160, 'mtgo_username' => 'Pro_MTG'])
        ->assertStatus(409);

    expect(MtgoAccount::where('user_id', $me->id)->count())->toBe(0);
});

it('rejects binding a different player id to an already-bound user (409)', function () {
    $me = User::factory()->create();
    MtgoAccount::factory()->for($me)->create(['mtgo_player_id' => 147160]);
    Passport::actingAs($me);

    $this->postJson('/api/mtgo/bind', ['mtgo_player_id' => 999999, 'mtgo_username' => 'Someone'])
        ->assertStatus(409);

    expect(MtgoAccount::where('user_id', $me->id)->first()->mtgo_player_id)->toBe(147160);
});

it('requires authentication', function () {
    $this->postJson('/api/mtgo/bind', ['mtgo_player_id' => 147160, 'mtgo_username' => 'Pro_MTG'])
        ->assertUnauthorized();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=BindMtgoAccountTest`
Expected: FAIL — table/model/action/controller/route missing.

- [ ] **Step 3: Migration + model + factory + DTO**

`database/migrations/2026_07_01_000002_create_mtgo_accounts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mtgo_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mtgo_player_id')->nullable(); // released (set null) on deactivation
            $table->string('mtgo_username');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Partial unique indexes (Postgres): enforce 1:1 only for live bindings; released
        // rows (null player id / user id after deactivation) don't collide.
        DB::statement('CREATE UNIQUE INDEX mtgo_accounts_mtgo_player_id_unique ON mtgo_accounts (mtgo_player_id) WHERE mtgo_player_id IS NOT NULL'); // one MTGO account ↔ one app account, globally
        DB::statement('CREATE UNIQUE INDEX mtgo_accounts_user_id_unique ON mtgo_accounts (user_id) WHERE user_id IS NOT NULL');                    // one MTGO identity per app account
    }

    public function down(): void
    {
        Schema::dropIfExists('mtgo_accounts');
    }
};
```

`app/Models/MtgoAccount.php`:

```php
<?php

namespace App\Models;

use Database\Factories\MtgoAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MtgoAccount extends Model
{
    /** @use HasFactory<MtgoAccountFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'mtgo_player_id', 'mtgo_username', 'active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mtgo_player_id' => 'integer',
            'active' => 'bool',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`database/factories/MtgoAccountFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\MtgoAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MtgoAccount> */
class MtgoAccountFactory extends Factory
{
    protected $model = MtgoAccount::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'mtgo_player_id' => fake()->unique()->numberBetween(100_000, 9_999_999),
            'mtgo_username' => fake()->userName(),
            'active' => true,
        ];
    }
}
```

`app/Data/MtgoAccountData.php`:

```php
<?php

namespace App\Data;

use App\Models\MtgoAccount;
use Spatie\LaravelData\Data;

class MtgoAccountData extends Data
{
    public function __construct(
        public int $mtgoPlayerId,
        public string $mtgoUsername,
        public bool $active,
    ) {}

    public static function fromModel(MtgoAccount $account): self
    {
        return new self(
            mtgoPlayerId: $account->mtgo_player_id,
            mtgoUsername: $account->mtgo_username,
            active: $account->active,
        );
    }
}
```

- [ ] **Step 4: Request + action + controller + route**

`app/Http/Requests/Mtgo/BindMtgoAccountRequest.php`:

```php
<?php

namespace App\Http\Requests\Mtgo;

use Illuminate\Foundation\Http\FormRequest;

class BindMtgoAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mtgo_player_id' => ['required', 'integer', 'min:1'],
            'mtgo_username' => ['required', 'string', 'max:255'],
        ];
    }
}
```

`app/Actions/Mtgo/BindMtgoAccount.php` — the strict-1:1 enforcer:

```php
<?php

namespace App\Actions\Mtgo;

use App\Models\MtgoAccount;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class BindMtgoAccount
{
    /**
     * Bind an MTGO identity to a user, enforcing strict 1:1.
     *
     * @throws BindConflictException when the player id belongs to another user,
     *                               or the user is already bound to a different id.
     */
    public function run(User $user, int $mtgoPlayerId, string $mtgoUsername): MtgoAccount
    {
        $byPlayer = MtgoAccount::where('mtgo_player_id', $mtgoPlayerId)->first();
        $byUser = MtgoAccount::where('user_id', $user->id)->first();

        // Player id already owned by someone else → reject.
        if ($byPlayer !== null && $byPlayer->user_id !== $user->id) {
            throw new BindConflictException('This MTGO account is already bound to another account.');
        }

        // This user is already bound to a *different* player id → reject.
        if ($byUser !== null && $byUser->mtgo_player_id !== $mtgoPlayerId) {
            throw new BindConflictException('This account is already bound to a different MTGO identity.');
        }

        // Idempotent create/update on the stable player id (username is a display attribute).
        return MtgoAccount::updateOrCreate(
            ['mtgo_player_id' => $mtgoPlayerId],
            ['user_id' => $user->id, 'mtgo_username' => $mtgoUsername, 'active' => true],
        );
    }
}
```

Create the small domain exception `app/Actions/Mtgo/BindConflictException.php`:

```php
<?php

namespace App\Actions\Mtgo;

use RuntimeException;

class BindConflictException extends RuntimeException {}
```

`app/Http/Controllers/Mtgo/BindMtgoAccountController.php`:

```php
<?php

namespace App\Http\Controllers\Mtgo;

use App\Actions\Mtgo\BindConflictException;
use App\Actions\Mtgo\BindMtgoAccount;
use App\Data\MtgoAccountData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mtgo\BindMtgoAccountRequest;
use Illuminate\Http\JsonResponse;

class BindMtgoAccountController extends Controller
{
    public function __construct(private BindMtgoAccount $bind) {}

    public function __invoke(BindMtgoAccountRequest $request): JsonResponse
    {
        try {
            $account = $this->bind->run(
                user: $request->user(),
                mtgoPlayerId: $request->integer('mtgo_player_id'),
                mtgoUsername: (string) $request->string('mtgo_username'),
            );
        } catch (BindConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => MtgoAccountData::fromModel($account)]);
    }
}
```

In `routes/api.php`, inside the `auth:api` group:

```php
use App\Http\Controllers\Mtgo\BindMtgoAccountController;

Route::post('/mtgo/bind', BindMtgoAccountController::class)->name('mtgo.bind');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=BindMtgoAccountTest`
Expected: PASS — bind, idempotent update, both 409 conflict directions, and auth requirement all green.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_01_000002_create_mtgo_accounts_table.php app/Models/MtgoAccount.php database/factories/MtgoAccountFactory.php app/Data/MtgoAccountData.php app/Http/Requests/Mtgo app/Actions/Mtgo routes/api.php tests/Feature/Auth/BindMtgoAccountTest.php
git commit -m "feat(api): MTGO identity binding endpoint with strict 1:1 enforcement"
```

---

## Self-Review checklist (run after Tasks 1–7)

1. **Spec coverage** — every bullet in [`spec.md`](./spec.md) maps to a task: OAuth2 server + PKCE public client = Tasks 1, 5; login screen (Discord + email/password, API-served) = Tasks 3, 4, 5; `mymtgo://` redirect + per-device refreshable/revocable tokens = Tasks 5, 6; `users` identity + `mtgo_accounts` 1:1 schema = Tasks 2, 7; binding rules (strict 1:1, non-editable, stable `mtgo_player_id`) = Task 7; ops `plan`/`is_active`/`deactivated_at` columns + deactivation login gate = Tasks 2, 3 (deletion mechanism itself is ops, referenced only).
2. **Placeholder scan** — no "TBD"/"handle later"/"similar to Task N"; every code block is complete and runnable.
3. **Secret hygiene** — Discord secret only in `config('services.discord')`; PKCE client is public (no secret); no secret in any response; `.env` never committed (only `.env.example`).
4. **Server-trust boundary** — every token-guarded action (`/auth/me`, `/auth/device`, `/mtgo/bind`) scopes off `$request->user()`, never a body-supplied user id (per [`../ops/spec.md`](../ops/spec.md)).
5. **Naming consistency** — `MtgoAccount`, `mtgo_accounts`, `mtgo_player_id`, `mtgo_username`, `UserPlan::{Free,Paid}`, `AuthUserData`, `MtgoAccountData` identical across Tasks 2, 5, 7.
6. **Additive migrations** — `users` change restates `password` fully as nullable; both migrations have safe `down()`; nothing destructive to the existing app.
