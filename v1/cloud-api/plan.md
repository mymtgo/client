# Cloud API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Where the code lives:** this plan FILE lives in the client repo (`docs/v1/cloud-api/`), but all CODE it describes is written in the **cloud API project at `/Volumes/Dev/mymtgo/api`** (the DigitalOcean droplet app). Every path in this plan is relative to that API project root unless stated otherwise.

**Goal:** Expose the queryable match data (built by the [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md) worker) as a **JSON read API** — matches, games, decks, stats, cards, archetypes — every endpoint scoped to the authenticated token's user, eager-loaded (no N+1), and `plan`-gated where paid-only. Add a **catch-up fetch** ("matches since last-seen version") and wire **Laravel Reverb** so the worker's post-commit `match.logged` signal reaches each user's private channel as a thin `{ matchKey, version }` notification that clients refetch against the read API.

**Architecture:** Read-only [Eloquent API Resources](https://laravel.com/docs/eloquent-resources) over the clean v1 tables (new Postgres DB — see [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md) §3). Auth is Passport Bearer tokens (see [`../cloud-auth/spec.md`](../cloud-auth/spec.md)); the web app may reuse the same guard via session, so all authorization is server-side ([`../ops/spec.md`](../ops/spec.md)). Realtime is liveness only — the socket carries **matchKey + version, never match data**; correctness is the catch-up fetch ([`spec.md`](./spec.md)).

**Tech Stack:** PHP 8.3, Laravel 13, PostgreSQL, Pest v4, Laravel Passport (OAuth2 Bearer), Laravel Reverb (broadcasting) + Horizon (Redis), Eloquent API Resources, Pint (see [`../overview/spec.md`](../overview/spec.md) §8). v1 cloud = a NEW Postgres database on a new host; 0.x is frozen on its own subdomain + existing DB (no server-side migration).

## Global Constraints

- **This plan reads; it does not build the schema.** The v1 tables (`matches`, `opponents`, `games`, `game_decks`, `card_game_stats`, `game_timeline`, `decks`, `deck_versions`, `leagues`, `tournaments`, `match_files`, `cards`, `archetypes`, `match_archetypes`) are created by the [`../cloud-pipeline/plan.md`](../cloud-pipeline/plan.md) worker plan; `users` + `mtgo_accounts` by the [`../cloud-auth/plan.md`](../cloud-auth/plan.md) plan. This plan assumes those migrations exist and only adds **read** controllers, resources, policies, channels, events, and their factories/tests. If a factory is missing when a task needs it, create the minimal factory in that task (noted per task).
- **Every endpoint is user-scoped.** Queries start from `$request->user()` (or a `->where('user_id', $request->user()->id)` when the model is not directly owned). Ownership is enforced **server-side** via policies — the server never trusts a client-supplied `user_id`. A request for another user's resource returns **404** (not 403 — do not leak existence). See [`../ops/spec.md`](../ops/spec.md) §Authorization.
- **Binary entitlement gate.** `User::$plan` is `free|paid` (see [`../cloud-auth/spec.md`](../cloud-auth/spec.md)). Paid-only endpoints call a single `EnsurePaidPlan` middleware (returns **402 Payment Required** for `free`). No tiers. The gate is server-side truth regardless of what the UI renders. See [`../ops/spec.md`](../ops/spec.md) §Entitlement.
- **Forgiving limits.** Per-user throttle ceilings are generous (`throttle:600,1`), sized for heavy pushers; guard only against pathological abuse, never normal heavy play. See [`../ops/spec.md`](../ops/spec.md) §Limits.
- **No N+1.** Every list/show endpoint eager-loads its resource's relations (`->with([...])`); the resource only reads already-loaded relations (`whenLoaded`). A test asserts the query count is bounded regardless of row count.
- **Thin socket, fat fetch.** The broadcast payload is exactly `{ matchKey, version }`. The socket is best-effort liveness; correctness is the catch-up fetch. The socket **never** carries match rows.
- **Auth guard is `api` (Passport).** Feature tests authenticate with `Laravel\Passport\Passport::actingAs($user, ['read'])`. Channel-auth tests use `$this->actingAs($user)` for the broadcasting-auth route.
- **API versioning.** All read routes are grouped under `/api/v1` and named `api.v1.*` (Eloquent-Resource + versioning convention). Do not fold new routes into the legacy `routes/api.php` device-key group.
- Use **single-action invokable controllers** (one class per endpoint), explicit return types, PHP 8 constructor promotion, curly braces on all control structures.
- Run `vendor/bin/pint --dirty --format agent` before finalizing any task.
- Tests: Pest v4, folded into the task whose deliverable needs them. Run the minimum: `php artisan test --compact --filter=TestName`.

---

## File Structure

**New (cloud API — read layer):**
- `config/broadcasting.php` (modify) — set `reverb` connection.
- `config/reverb.php` — Reverb server config (published).
- `routes/api_v1.php` — the `/api/v1` read routes (registered in `bootstrap/app.php`).
- `routes/channels.php` (create/modify) — per-user private channel authorization.
- `app/Http/Middleware/EnsurePaidPlan.php` — `plan` gate (402 for free).
- `app/Http/Controllers/Api/V1/Matches/{IndexMatchController,ShowMatchController}.php`
- `app/Http/Controllers/Api/V1/Matches/CatchUpMatchController.php`
- `app/Http/Controllers/Api/V1/Games/ShowGameController.php`
- `app/Http/Controllers/Api/V1/Decks/{IndexDeckController,ShowDeckController}.php`
- `app/Http/Controllers/Api/V1/Stats/ShowStatsController.php`
- `app/Http/Controllers/Api/V1/Cards/{IndexCardController,ShowCardController}.php`
- `app/Http/Controllers/Api/V1/Archetypes/IndexArchetypeController.php`
- `app/Http/Resources/V1/{MatchResource,MatchSummaryResource,GameResource,CardGameStatResource,GameTimelineResource,GameDeckResource,DeckResource,DeckVersionResource,OpponentResource,LeagueResource,TournamentResource,CardResource,ArchetypeResource,StatsResource}.php`
- `app/Http/Requests/Api/V1/CatchUpMatchRequest.php`, `IndexMatchRequest.php`, `IndexCardRequest.php`
- `app/Policies/{MatchPolicy,GamePolicy,DeckPolicy}.php`
- `app/Actions/Stats/ComputeUserStats.php` — aggregate win-rate / archetype breakdown (paid).
- `app/Events/MatchLogged.php` — the broadcast event (thin signal).
- `app/Actions/Realtime/NotifyMatchLogged.php` — invoked by the worker after commit; dispatches `MatchLogged`.

**Assumed to exist (created by sibling plans — do NOT create here, only reference/factory):**
- Models: `App\Models\{MtgoMatch,Opponent,Game,GameDeck,CardGameStat,GameTimeline,Deck,DeckVersion,League,Tournament,MatchFile,Card,Archetype,MatchArchetype,User,MtgoAccount}` — cloud-pipeline / cloud-auth.
- The `matches` table with `UNIQUE(user_id, match_key)`, `source_file_version`; `match_files` with `file_version`; etc. per [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md) §3.

**Factories added by this plan (only if the sibling plan has not provided them — each task notes it):**
- `database/factories/{MtgoMatchFactory,OpponentFactory,GameFactory,CardGameStatFactory,DeckFactory,DeckVersionFactory,CardFactory,ArchetypeFactory}.php`

---

### Task 1: Reverb + Passport read-guard bootstrapping

**Files:**
- Modify: `composer.json` (require `laravel/reverb`) — install via artisan.
- Modify: `config/broadcasting.php` (default connection `reverb`), `.env.example` (Reverb + broadcast keys).
- Create: `config/reverb.php` (published).
- Modify: `bootstrap/app.php` — register `routes/api_v1.php` and `routes/channels.php`; alias the `plan` middleware.
- Modify: `bootstrap/providers.php` — ensure `BroadcastServiceProvider` / Passport are registered (per [`../cloud-auth/spec.md`](../cloud-auth/spec.md)).
- Test: `tests/Feature/Api/V1/BootstrapTest.php`

**Interfaces:**
- Produces: the `/api/v1` route group (auth guard `api`), the `broadcasting/auth` route, and a resolvable `reverb` broadcast connection. Consumed by every later task.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Api/V1/BootstrapTest.php
use Illuminate\Support\Facades\Route;

it('registers the api/v1 route group behind the api guard', function () {
    // a probe route the group defines in Task 2 will exist; here we assert the group config
    expect(config('broadcasting.default'))->toBe('reverb');
    expect(config('broadcasting.connections.reverb.driver'))->toBe('reverb');
});

it('exposes the broadcasting auth route', function () {
    expect(Route::has('broadcasting.auth') || collect(Route::getRoutes())->contains(
        fn ($r) => $r->uri() === 'broadcasting/auth'
    ))->toBeTrue();
});

it('aliases the plan middleware', function () {
    $aliases = app('router')->getMiddleware();
    expect($aliases)->toHaveKey('plan');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=BootstrapTest`
Expected: FAIL — `broadcasting.default` is not `reverb`, no `plan` alias, no broadcasting auth route.

- [ ] **Step 3: Install + configure Reverb**

```bash
composer require laravel/reverb
php artisan reverb:install --no-interaction
```

In `config/broadcasting.php` set `'default' => env('BROADCAST_CONNECTION', 'reverb')` and confirm the published `connections.reverb` block. Add to `.env.example`:

```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=mymtgo
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST="127.0.0.1"
REVERB_PORT=8080
REVERB_SCHEME=http
```

- [ ] **Step 4: Register routes + the `plan` middleware alias**

In `bootstrap/app.php` `withRouting(...)`, register the new route files and enable broadcasting channels:

```php
->withRouting(
    api: __DIR__.'/../routes/api.php',
    web: __DIR__.'/../routes/web.php',
    commands: __DIR__.'/../routes/console.php',
    channels: __DIR__.'/../routes/channels.php',
    then: function () {
        Route::middleware('api')
            ->prefix('api/v1')
            ->name('api.v1.')
            ->group(base_path('routes/api_v1.php'));
    },
)
```

In `withMiddleware(...)` add the alias:

```php
$middleware->alias([
    'plan' => \App\Http\Middleware\EnsurePaidPlan::class,
]);
```

Create an empty `routes/api_v1.php` (`<?php use Illuminate\Support\Facades\Route;`) and `routes/channels.php` (`<?php` + `Broadcast::routes(['middleware' => ['auth:api']]);` — Passport-authenticated channel auth). Ensure the Passport `api` guard is configured in `config/auth.php` (created by cloud-auth; if absent, add `'api' => ['driver' => 'passport', 'provider' => 'users']`).

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=BootstrapTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add composer.json config/broadcasting.php config/reverb.php bootstrap/app.php bootstrap/providers.php routes/api_v1.php routes/channels.php .env.example tests/Feature/Api/V1/BootstrapTest.php
git commit -m "feat(api): bootstrap reverb + api/v1 route group + plan middleware alias"
```

---

### Task 2: `EnsurePaidPlan` middleware (the entitlement gate)

**Files:**
- Create: `app/Http/Middleware/EnsurePaidPlan.php`
- Test: `tests/Feature/Api/V1/EnsurePaidPlanTest.php`

**Interfaces:**
- Consumes: `$request->user()->plan` (`free|paid`).
- Produces: passes the request through for `paid`; aborts **402** for `free`; aborts **401** when unauthenticated. Consumed by Tasks 6, 7 (paid-only endpoints).

- [ ] **Step 1: Write the failing test (mount the middleware on a probe route)**

```php
<?php // tests/Feature/Api/V1/EnsurePaidPlanTest.php
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;

beforeEach(function () {
    Route::middleware(['api', 'plan'])->get('/api/v1/_probe_paid', fn () => response()->json(['ok' => true]));
});

it('rejects a free user with 402', function () {
    Passport::actingAs(User::factory()->create(['plan' => 'free']), ['read']);
    $this->getJson('/api/v1/_probe_paid')->assertStatus(402);
});

it('allows a paid user', function () {
    Passport::actingAs(User::factory()->create(['plan' => 'paid']), ['read']);
    $this->getJson('/api/v1/_probe_paid')->assertOk()->assertJson(['ok' => true]);
});

it('rejects an unauthenticated request with 401', function () {
    $this->getJson('/api/v1/_probe_paid')->assertStatus(401);
});
```

If a `User` factory does not yet exist (cloud-auth plan owns it), create the minimal `database/factories/UserFactory.php` with a `plan` default of `'free'` and a `paid()` state.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=EnsurePaidPlanTest`
Expected: FAIL — `App\Http\Middleware\EnsurePaidPlan` not found.

- [ ] **Step 3: Implement the middleware**

```php
<?php // app/Http/Middleware/EnsurePaidPlan.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePaidPlan
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        if ($user->plan !== 'paid') {
            abort(Response::HTTP_PAYMENT_REQUIRED, 'This feature requires a paid plan.');
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=EnsurePaidPlanTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/EnsurePaidPlan.php database/factories/UserFactory.php tests/Feature/Api/V1/EnsurePaidPlanTest.php
git commit -m "feat(api): EnsurePaidPlan entitlement middleware (402 for free plan)"
```

---

### Task 3: Ownership policies (`MatchPolicy`, `GamePolicy`, `DeckPolicy`)

**Files:**
- Create: `app/Policies/MatchPolicy.php`, `app/Policies/GamePolicy.php`, `app/Policies/DeckPolicy.php`
- Test: `tests/Feature/Api/V1/OwnershipPolicyTest.php`

**Interfaces:**
- Consumes: `User`, and a model instance (`MtgoMatch`, `Game`, `Deck`).
- Produces: `view(User $user, Model $model): bool` — true only when the model belongs to the user. `Game::view` walks `game->match->user_id`. Consumed by every show endpoint via `$this->authorize('view', $model)` → **404** on mismatch (see Task 5's `->missing()` route binding + policy).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Api/V1/OwnershipPolicyTest.php
use App\Models\{Deck, Game, MtgoMatch, User};

it('allows the owner and denies a stranger for a match', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $match = MtgoMatch::factory()->for($owner)->create();

    expect($owner->can('view', $match))->toBeTrue();
    expect($stranger->can('view', $match))->toBeFalse();
});

it('resolves game ownership through its match', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $game = Game::factory()->for(MtgoMatch::factory()->for($owner))->create();

    expect($owner->can('view', $game))->toBeTrue();
    expect($stranger->can('view', $game))->toBeFalse();
});

it('allows the owner and denies a stranger for a deck', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $deck = Deck::factory()->for($owner)->create();

    expect($owner->can('view', $deck))->toBeTrue();
    expect($stranger->can('view', $deck))->toBeFalse();
});
```

Create the minimal factories this test needs if absent: `MtgoMatchFactory` (belongsTo `User`, random `match_key` uuid, `source_file_version` = 1), `GameFactory` (belongsTo `MtgoMatch`), `DeckFactory` (belongsTo `User`). Note in the commit which factories were added here.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=OwnershipPolicyTest`
Expected: FAIL — policies not defined.

- [ ] **Step 3: Implement the three policies**

```php
<?php // app/Policies/MatchPolicy.php

namespace App\Policies;

use App\Models\MtgoMatch;
use App\Models\User;

final class MatchPolicy
{
    public function view(User $user, MtgoMatch $match): bool
    {
        return $match->user_id === $user->id;
    }
}
```

```php
<?php // app/Policies/GamePolicy.php

namespace App\Policies;

use App\Models\Game;
use App\Models\User;

final class GamePolicy
{
    public function view(User $user, Game $game): bool
    {
        return $game->match->user_id === $user->id;
    }
}
```

```php
<?php // app/Policies/DeckPolicy.php

namespace App\Policies;

use App\Models\Deck;
use App\Models\User;

final class DeckPolicy
{
    public function view(User $user, Deck $deck): bool
    {
        return $deck->user_id === $user->id;
    }
}
```

Laravel 13 auto-discovers policies by naming convention (`App\Models\MtgoMatch` → `App\Policies\MatchPolicy`? — auto-discovery expects `MtgoMatchPolicy`). Because the model is `MtgoMatch`, register the mapping explicitly in `app/Providers/AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Gate;
Gate::policy(\App\Models\MtgoMatch::class, \App\Policies\MatchPolicy::class);
Gate::policy(\App\Models\Game::class, \App\Policies\GamePolicy::class);
Gate::policy(\App\Models\Deck::class, \App\Policies\DeckPolicy::class);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=OwnershipPolicyTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Policies app/Providers/AppServiceProvider.php database/factories tests/Feature/Api/V1/OwnershipPolicyTest.php
git commit -m "feat(api): ownership policies for match/game/deck (owner-only view)"
```

---

### Task 4: Match API resources + `IndexMatchController` (list, scoped, eager-loaded)

**Files:**
- Create: `app/Http/Resources/V1/MatchSummaryResource.php`, `app/Http/Resources/V1/OpponentResource.php`
- Create: `app/Http/Requests/Api/V1/IndexMatchRequest.php`
- Create: `app/Http/Controllers/Api/V1/Matches/IndexMatchController.php`
- Modify: `routes/api_v1.php`
- Test: `tests/Feature/Api/V1/Matches/IndexMatchTest.php`

**Interfaces:**
- Consumes: `MtgoMatch` scoped to `$request->user()`, eager `opponent`.
- Produces: `GET /api/v1/matches` → paginated `MatchSummaryResource` collection (list shape: token, mtgo_id, format, match_type, outcome, outcome_source, state, started_at, ended_at, opponent summary, source_file_version). Filterable by `format`, `match_type`, `outcome`. Consumed by the desktop/web match list + catch-up (Task 8 reuses the summary resource).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Api/V1/Matches/IndexMatchTest.php
use App\Models\{MtgoMatch, User};
use Laravel\Passport\Passport;

it('lists only the authenticated user\'s matches', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    MtgoMatch::factory()->count(3)->for($me)->create();
    MtgoMatch::factory()->count(2)->for($other)->create();

    Passport::actingAs($me, ['read']);
    $res = $this->getJson('/api/v1/matches')->assertOk();

    expect($res->json('data'))->toHaveCount(3);
    $res->assertJsonStructure(['data' => [['token', 'format', 'outcome', 'outcome_source', 'state', 'opponent']], 'meta', 'links']);
});

it('filters matches by format and outcome', function () {
    $me = User::factory()->create();
    MtgoMatch::factory()->for($me)->create(['format' => 'CModern', 'outcome' => 'Win']);
    MtgoMatch::factory()->for($me)->create(['format' => 'CLegacy', 'outcome' => 'Loss']);

    Passport::actingAs($me, ['read']);
    $res = $this->getJson('/api/v1/matches?format=CModern&outcome=Win')->assertOk();
    expect($res->json('data'))->toHaveCount(1);
});

it('does not N+1 when loading opponents', function () {
    $me = User::factory()->create();
    MtgoMatch::factory()->count(10)->for($me)->hasOpponent()->create();

    Passport::actingAs($me, ['read']);
    \DB::enableQueryLog();
    $this->getJson('/api/v1/matches')->assertOk();
    // matches + opponents + count(paginate) — bounded, independent of row count
    expect(count(\DB::getQueryLog()))->toBeLessThanOrEqual(4);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/matches')->assertStatus(401);
});
```

Add an `OpponentFactory` (unique `mtgo_player_id`, `username`) and a `hasOpponent()` relation state on `MtgoMatchFactory` if absent.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=IndexMatchTest`
Expected: FAIL — route/controller/resource missing.

- [ ] **Step 3: Implement request, resources, controller, route**

```php
<?php // app/Http/Requests/Api/V1/IndexMatchRequest.php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class IndexMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'format' => ['sometimes', 'string', 'max:32'],
            'match_type' => ['sometimes', 'string', 'max:32'],
            'outcome' => ['sometimes', 'string', 'in:Win,Loss,Draw,Unknown'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
```

```php
<?php // app/Http/Resources/V1/OpponentResource.php

namespace App\Http\Resources\V1;

use App\Models\Opponent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Opponent */
final class OpponentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'mtgo_player_id' => $this->mtgo_player_id,
            'username' => $this->username,
        ];
    }
}
```

```php
<?php // app/Http/Resources/V1/MatchSummaryResource.php

namespace App\Http\Resources\V1;

use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MtgoMatch */
final class MatchSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->match_key,
            'mtgo_id' => $this->mtgo_id,
            'format' => $this->format,
            'match_type' => $this->match_type,
            'outcome' => $this->outcome,
            'outcome_source' => $this->outcome_source,
            'state' => $this->state,
            'started_at' => optional($this->started_at)?->toIso8601String(),
            'ended_at' => optional($this->ended_at)?->toIso8601String(),
            'source_file_version' => $this->source_file_version,
            'opponent' => OpponentResource::make($this->whenLoaded('opponent')),
        ];
    }
}
```

```php
<?php // app/Http/Controllers/Api/V1/Matches/IndexMatchController.php

namespace App\Http\Controllers\Api\V1\Matches;

use App\Http\Requests\Api\V1\IndexMatchRequest;
use App\Http\Resources\V1\MatchSummaryResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class IndexMatchController
{
    public function __invoke(IndexMatchRequest $request): AnonymousResourceCollection
    {
        $matches = $request->user()->matches()
            ->with('opponent')
            ->when($request->string('format')->isNotEmpty(), fn ($q) => $q->where('format', $request->string('format')))
            ->when($request->string('match_type')->isNotEmpty(), fn ($q) => $q->where('match_type', $request->string('match_type')))
            ->when($request->string('outcome')->isNotEmpty(), fn ($q) => $q->where('outcome', $request->string('outcome')))
            ->latest('started_at')
            ->paginate($request->integer('per_page', 50));

        return MatchSummaryResource::collection($matches);
    }
}
```

In `routes/api_v1.php`:

```php
use App\Http\Controllers\Api\V1\Matches\IndexMatchController;

Route::middleware('auth:api')->group(function () {
    Route::get('/matches', IndexMatchController::class)->name('matches.index');
});
```

If `User::matches()` (hasMany `MtgoMatch`) is not defined by cloud-auth, add it to `app/Models/User.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=IndexMatchTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/Api/V1/IndexMatchRequest.php app/Http/Resources/V1/MatchSummaryResource.php app/Http/Resources/V1/OpponentResource.php app/Http/Controllers/Api/V1/Matches/IndexMatchController.php routes/api_v1.php app/Models/User.php database/factories tests/Feature/Api/V1/Matches/IndexMatchTest.php
git commit -m "feat(api): GET /api/v1/matches list (user-scoped, filtered, eager-loaded)"
```

---

### Task 5: `ShowMatchController` + full nested resource tree

**Files:**
- Create: `app/Http/Resources/V1/MatchResource.php`, `GameResource.php`, `CardGameStatResource.php`, `GameTimelineResource.php`, `GameDeckResource.php`, `DeckResource.php`, `DeckVersionResource.php`, `LeagueResource.php`, `TournamentResource.php`, `ArchetypeResource.php`
- Create: `app/Http/Controllers/Api/V1/Matches/ShowMatchController.php`
- Modify: `routes/api_v1.php`
- Test: `tests/Feature/Api/V1/Matches/ShowMatchTest.php`

**Interfaces:**
- Consumes: `MtgoMatch` bound by `match_key` (route-model-bound on the `match_key` column), eager `opponent`, `deckVersion.deck`, `league`, `tournament`, `games.cardStats`, `games.timeline`, `games.gameDecks`, `matchArchetype.archetype`.
- Produces: `GET /api/v1/matches/{match:match_key}` → the full `MatchResource` (mirrors the [`../contract/spec.md`](../contract/spec.md) `match{}` payload, read side). 404 for a non-owned or unknown key. Consumed by desktop/web match detail.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Api/V1/Matches/ShowMatchTest.php
use App\Models\{CardGameStat, Game, GameTimeline, MtgoMatch, User};
use Laravel\Passport\Passport;

it('returns the full nested match for its owner', function () {
    $me = User::factory()->create();
    $match = MtgoMatch::factory()->for($me)->hasOpponent()->create(['match_key' => 'abc-123']);
    $game = Game::factory()->for($match, 'match')->create();
    CardGameStat::factory()->for($game)->count(2)->create();
    GameTimeline::factory()->for($game)->count(3)->create();

    Passport::actingAs($me, ['read']);
    $res = $this->getJson('/api/v1/matches/abc-123')->assertOk();

    $res->assertJsonPath('data.token', 'abc-123');
    $res->assertJsonStructure(['data' => [
        'token', 'mtgo_id', 'format', 'match_type', 'outcome', 'outcome_source', 'state',
        'opponent' => ['mtgo_player_id', 'username'],
        'games' => [['mtgo_id', 'won', 'turn_count', 'local_on_play', 'card_stats', 'timeline']],
    ]]);
    expect($res->json('data.games.0.card_stats'))->toHaveCount(2);
    expect($res->json('data.games.0.timeline'))->toHaveCount(3);
});

it('returns 404 for another user\'s match (no existence leak)', function () {
    $me = User::factory()->create();
    $match = MtgoMatch::factory()->for(User::factory())->create(['match_key' => 'not-mine']);

    Passport::actingAs($me, ['read']);
    $this->getJson('/api/v1/matches/not-mine')->assertNotFound();
});

it('does not N+1 across games/stats/timeline', function () {
    $me = User::factory()->create();
    $match = MtgoMatch::factory()->for($me)->create(['match_key' => 'perf-1']);
    Game::factory()->for($match, 'match')->count(5)->hasCardStats(4)->hasTimeline(6)->create();

    Passport::actingAs($me, ['read']);
    \DB::enableQueryLog();
    $this->getJson('/api/v1/matches/perf-1')->assertOk();
    // one query per eager-loaded relation, not per row
    expect(count(\DB::getQueryLog()))->toBeLessThanOrEqual(12);
});
```

Add factories/relation states as needed (`CardGameStatFactory`, `GameTimelineFactory`; `hasCardStats`/`hasTimeline` via `Game` relations).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ShowMatchTest`
Expected: FAIL — controller/resources missing.

- [ ] **Step 3: Implement the resource tree + controller**

`CardGameStatResource` (oracle_id, opponent, quantity, kept, seen, played, won, is_postboard, sided_out, pregame_revealed, pregame_played, kicked, flashback, madness, evoked, activated), `GameTimelineResource` (action, timestamp→iso, player, context), `GameDeckResource` (is_opponent, signature from `deck_json`), `GameResource` (mtgo_id, won, started_at, ended_at, turn_count, local_on_play, local_mulligans, opp_mulligans, local_dice, opp_dice, local_instance, opp_instance, `card_stats` = `CardGameStatResource::collection(whenLoaded('cardStats'))`, `timeline` = `GameTimelineResource::collection(whenLoaded('timeline'))`, `local_deck`/`opponent_deck` from `whenLoaded('gameDecks')`), `DeckVersionResource` (signature, modified_at), `DeckResource` (mtgo_id, name, format, color_identity, current version), `LeagueResource`, `TournamentResource`, `ArchetypeResource` (uuid, name, confidence).

```php
<?php // app/Http/Resources/V1/MatchResource.php

namespace App\Http\Resources\V1;

use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MtgoMatch */
final class MatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->match_key,
            'mtgo_id' => $this->mtgo_id,
            'league_token' => $this->league_token,
            'format' => $this->format,
            'match_type' => $this->match_type,
            'outcome' => $this->outcome,
            'outcome_source' => $this->outcome_source,
            'state' => $this->state,
            'started_at' => optional($this->started_at)?->toIso8601String(),
            'ended_at' => optional($this->ended_at)?->toIso8601String(),
            'notes' => $this->notes,
            'imported' => (bool) $this->imported,
            'source_file_version' => $this->source_file_version,
            'opponent' => OpponentResource::make($this->whenLoaded('opponent')),
            'deck' => DeckResource::make($this->whenLoaded('deckVersion', fn () => $this->deckVersion?->deck)),
            'league' => LeagueResource::make($this->whenLoaded('league')),
            'tournament' => TournamentResource::make($this->whenLoaded('tournament')),
            'opponent_archetype' => ArchetypeResource::make($this->whenLoaded('matchArchetype', fn () => $this->matchArchetype?->archetype)),
            'games' => GameResource::collection($this->whenLoaded('games')),
        ];
    }
}
```

```php
<?php // app/Http/Controllers/Api/V1/Matches/ShowMatchController.php

namespace App\Http\Controllers\Api\V1\Matches;

use App\Http\Resources\V1\MatchResource;
use App\Models\MtgoMatch;

final class ShowMatchController
{
    public function __invoke(MtgoMatch $match): MatchResource
    {
        $this->authorizeView($match);

        $match->load([
            'opponent',
            'deckVersion.deck',
            'league',
            'tournament',
            'matchArchetype.archetype',
            'games.cardStats',
            'games.timeline',
            'games.gameDecks',
        ]);

        return MatchResource::make($match);
    }

    private function authorizeView(MtgoMatch $match): void
    {
        abort_unless($match->user_id === request()->user()->id, 404);
    }
}
```

Route (bind on `match_key`, so a wrong owner never resolves an existence leak beyond 404):

```php
use App\Http\Controllers\Api\V1\Matches\ShowMatchController;

Route::get('/matches/{match:match_key}', ShowMatchController::class)->name('matches.show');
```

Using an explicit `abort_unless(... 404)` (rather than the policy's 403) satisfies the ops "never leak existence" rule; the `MatchPolicy` from Task 3 stays available for gate checks elsewhere. Ensure `MtgoMatch` relations (`games`, `opponent`, `deckVersion`, `league`, `tournament`, `matchArchetype`) and `Game` relations (`cardStats`, `timeline`, `gameDecks`) exist on the models (add if the cloud-pipeline plan hasn't).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ShowMatchTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Resources/V1 app/Http/Controllers/Api/V1/Matches/ShowMatchController.php routes/api_v1.php database/factories tests/Feature/Api/V1/Matches/ShowMatchTest.php
git commit -m "feat(api): GET /api/v1/matches/{key} full nested resource (404 on non-owner)"
```

---

### Task 6: `ShowGameController` (single game deep view)

**Files:**
- Create: `app/Http/Controllers/Api/V1/Games/ShowGameController.php`
- Modify: `routes/api_v1.php`
- Test: `tests/Feature/Api/V1/Games/ShowGameTest.php`

**Interfaces:**
- Consumes: `Game` bound by `mtgo_id` (or route key), eager `cardStats`, `timeline`, `gameDecks`, `match`.
- Produces: `GET /api/v1/games/{game}` → `GameResource` (reuses Task 5's resource). Ownership walks `game->match->user_id` → 404. Consumed by the per-game stat drilldown.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Api/V1/Games/ShowGameTest.php
use App\Models\{Game, MtgoMatch, User};
use Laravel\Passport\Passport;

it('returns a game for the owner with its stats + timeline', function () {
    $me = User::factory()->create();
    $game = Game::factory()->for(MtgoMatch::factory()->for($me), 'match')->hasCardStats(3)->hasTimeline(2)->create();

    Passport::actingAs($me, ['read']);
    $res = $this->getJson("/api/v1/games/{$game->getKey()}")->assertOk();
    expect($res->json('data.card_stats'))->toHaveCount(3);
    expect($res->json('data.timeline'))->toHaveCount(2);
});

it('returns 404 for a game belonging to another user', function () {
    $me = User::factory()->create();
    $game = Game::factory()->for(MtgoMatch::factory()->for(User::factory()), 'match')->create();

    Passport::actingAs($me, ['read']);
    $this->getJson("/api/v1/games/{$game->getKey()}")->assertNotFound();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ShowGameTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php // app/Http/Controllers/Api/V1/Games/ShowGameController.php

namespace App\Http\Controllers\Api\V1\Games;

use App\Http\Resources\V1\GameResource;
use App\Models\Game;

final class ShowGameController
{
    public function __invoke(Game $game): GameResource
    {
        abort_unless($game->loadMissing('match')->match->user_id === request()->user()->id, 404);

        $game->load(['cardStats', 'timeline', 'gameDecks']);

        return GameResource::make($game);
    }
}
```

Route:

```php
use App\Http\Controllers\Api\V1\Games\ShowGameController;
Route::get('/games/{game}', ShowGameController::class)->name('games.show');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ShowGameTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/Games/ShowGameController.php routes/api_v1.php tests/Feature/Api/V1/Games/ShowGameTest.php
git commit -m "feat(api): GET /api/v1/games/{id} deep view (owner-scoped via match)"
```

---

### Task 7: Deck endpoints (`IndexDeckController`, `ShowDeckController`)

**Files:**
- Create: `app/Http/Controllers/Api/V1/Decks/IndexDeckController.php`, `ShowDeckController.php`
- Modify: `routes/api_v1.php` (reuses `DeckResource`, `DeckVersionResource` from Task 5)
- Test: `tests/Feature/Api/V1/Decks/DeckEndpointsTest.php`

**Interfaces:**
- Consumes: `Deck` scoped to `$request->user()`, eager `versions`, `archetype`.
- Produces: `GET /api/v1/decks` (paginated summary) and `GET /api/v1/decks/{deck}` (with version history). Owner-scoped → 404 on non-owner. Free endpoint.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Api/V1/Decks/DeckEndpointsTest.php
use App\Models\{Deck, User};
use Laravel\Passport\Passport;

it('lists only the user\'s decks', function () {
    $me = User::factory()->create();
    Deck::factory()->count(2)->for($me)->create();
    Deck::factory()->for(User::factory())->create();

    Passport::actingAs($me, ['read']);
    expect($this->getJson('/api/v1/decks')->assertOk()->json('data'))->toHaveCount(2);
});

it('shows a deck with version history', function () {
    $me = User::factory()->create();
    $deck = Deck::factory()->for($me)->hasVersions(3)->create();

    Passport::actingAs($me, ['read']);
    $res = $this->getJson("/api/v1/decks/{$deck->getKey()}")->assertOk();
    expect($res->json('data.versions'))->toHaveCount(3);
});

it('404s a stranger\'s deck', function () {
    $me = User::factory()->create();
    $deck = Deck::factory()->for(User::factory())->create();
    Passport::actingAs($me, ['read']);
    $this->getJson("/api/v1/decks/{$deck->getKey()}")->assertNotFound();
});
```

Add `DeckVersionFactory` + `hasVersions` relation if absent; extend `DeckResource` to include `versions` = `DeckVersionResource::collection(whenLoaded('versions'))`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=DeckEndpointsTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php // app/Http/Controllers/Api/V1/Decks/IndexDeckController.php

namespace App\Http\Controllers\Api\V1\Decks;

use App\Http\Resources\V1\DeckResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class IndexDeckController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $decks = $request->user()->decks()
            ->with('archetype')
            ->latest('updated_at')
            ->paginate($request->integer('per_page', 50));

        return DeckResource::collection($decks);
    }
}
```

```php
<?php // app/Http/Controllers/Api/V1/Decks/ShowDeckController.php

namespace App\Http\Controllers\Api\V1\Decks;

use App\Http\Resources\V1\DeckResource;
use App\Models\Deck;

final class ShowDeckController
{
    public function __invoke(Deck $deck): DeckResource
    {
        abort_unless($deck->user_id === request()->user()->id, 404);

        $deck->load(['versions', 'archetype']);

        return DeckResource::make($deck);
    }
}
```

Routes:

```php
use App\Http\Controllers\Api\V1\Decks\{IndexDeckController, ShowDeckController};
Route::get('/decks', IndexDeckController::class)->name('decks.index');
Route::get('/decks/{deck}', ShowDeckController::class)->name('decks.show');
```

Add `User::decks()` (hasMany) and `Deck::versions()` (hasMany `DeckVersion`) if absent.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=DeckEndpointsTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/Decks routes/api_v1.php app/Http/Resources/V1/DeckResource.php app/Models database/factories tests/Feature/Api/V1/Decks/DeckEndpointsTest.php
git commit -m "feat(api): GET /api/v1/decks list + show with version history (owner-scoped)"
```

---

### Task 8: Catch-up fetch — "matches since last-seen version"

**Files:**
- Create: `app/Http/Requests/Api/V1/CatchUpMatchRequest.php`
- Create: `app/Http/Controllers/Api/V1/Matches/CatchUpMatchController.php`
- Modify: `routes/api_v1.php`
- Test: `tests/Feature/Api/V1/Matches/CatchUpMatchTest.php`

**Interfaces:**
- Consumes: `$request->user()`'s matches joined to `match_files.file_version`; a client-supplied `since` cursor (the highest `file_version` the client already holds).
- Produces: `GET /api/v1/matches/catch-up?since={version}` → `MatchSummaryResource` collection of matches whose `match_files.file_version > since`, ordered ascending by version, plus a `meta.latest_version` cursor the client stores. **This is the correctness path** for the realtime socket ([`spec.md`](./spec.md) §Realtime). Free endpoint. Consumed on reconnect / app-foreground.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Api/V1/Matches/CatchUpMatchTest.php
use App\Models\{MatchFile, MtgoMatch, User};
use Laravel\Passport\Passport;

function matchAtVersion(User $u, int $version): MtgoMatch {
    $match = MtgoMatch::factory()->for($u)->create(['source_file_version' => $version]);
    MatchFile::factory()->for($u)->create(['match_key' => $match->match_key, 'file_version' => $version, 'status' => 'built']);
    return $match;
}

it('returns only matches newer than the since cursor', function () {
    $me = User::factory()->create();
    matchAtVersion($me, 1);
    matchAtVersion($me, 2);
    $newest = matchAtVersion($me, 3);

    Passport::actingAs($me, ['read']);
    $res = $this->getJson('/api/v1/matches/catch-up?since=1')->assertOk();

    expect($res->json('data'))->toHaveCount(2); // versions 2 and 3
    expect($res->json('meta.latest_version'))->toBe(3);
});

it('excludes other users\' matches from catch-up', function () {
    $me = User::factory()->create();
    matchAtVersion($me, 5);
    matchAtVersion(User::factory()->create(), 9);

    Passport::actingAs($me, ['read']);
    $res = $this->getJson('/api/v1/matches/catch-up?since=0')->assertOk();
    expect($res->json('data'))->toHaveCount(1);
    expect($res->json('meta.latest_version'))->toBe(5);
});

it('defaults since to 0 and returns everything', function () {
    $me = User::factory()->create();
    matchAtVersion($me, 1);
    Passport::actingAs($me, ['read']);
    expect($this->getJson('/api/v1/matches/catch-up')->assertOk()->json('data'))->toHaveCount(1);
});
```

Add `MatchFileFactory` if absent (belongsTo `User`, `match_key`, `file_version`, `status`).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=CatchUpMatchTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php // app/Http/Requests/Api/V1/CatchUpMatchRequest.php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class CatchUpMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'since' => ['sometimes', 'integer', 'min:0'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }
}
```

```php
<?php // app/Http/Controllers/Api/V1/Matches/CatchUpMatchController.php

namespace App\Http\Controllers\Api\V1\Matches;

use App\Http\Requests\Api\V1\CatchUpMatchRequest;
use App\Http\Resources\V1\MatchSummaryResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CatchUpMatchController
{
    public function __invoke(CatchUpMatchRequest $request): AnonymousResourceCollection
    {
        $since = $request->integer('since', 0);

        $matches = $request->user()->matches()
            ->with('opponent')
            ->where('source_file_version', '>', $since)
            ->orderBy('source_file_version')
            ->paginate($request->integer('per_page', 200));

        $latest = (int) $request->user()->matches()->max('source_file_version');

        return MatchSummaryResource::collection($matches)
            ->additional(['meta' => ['latest_version' => $latest]]);
    }
}
```

`source_file_version` on `matches` is the per-user monotonic cursor written by the worker (see [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md) §3 — the version the worker processed). Route (register **before** `/matches/{match:match_key}` so `catch-up` is not captured as a match key):

```php
use App\Http\Controllers\Api\V1\Matches\CatchUpMatchController;
Route::get('/matches/catch-up', CatchUpMatchController::class)->name('matches.catch-up');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=CatchUpMatchTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/Api/V1/CatchUpMatchRequest.php app/Http/Controllers/Api/V1/Matches/CatchUpMatchController.php routes/api_v1.php database/factories tests/Feature/Api/V1/Matches/CatchUpMatchTest.php
git commit -m "feat(api): catch-up fetch — matches since last-seen version (correctness path)"
```

---

### Task 9: Card catalog endpoints (`IndexCardController`, `ShowCardController`) — free reference data

**Files:**
- Create: `app/Http/Resources/V1/CardResource.php`
- Create: `app/Http/Requests/Api/V1/IndexCardRequest.php`
- Create: `app/Http/Controllers/Api/V1/Cards/IndexCardController.php`, `ShowCardController.php`
- Modify: `routes/api_v1.php`
- Test: `tests/Feature/Api/V1/Cards/CardEndpointsTest.php`

**Interfaces:**
- Consumes: the global `cards` catalog (Scryfall/Goatbots — see [`../catalog/spec.md`](../catalog/spec.md)); **not user-scoped** (reference data), but still auth-gated (`auth:api`).
- Produces: `GET /api/v1/cards?ids=...|name=...` (batch resolve / search, paginated) and `GET /api/v1/cards/{card:oracle_id}`. Free endpoint. Consumed by clients to hydrate card display without shipping the whole catalog.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Api/V1/Cards/CardEndpointsTest.php
use App\Models\{Card, User};
use Laravel\Passport\Passport;

it('resolves a batch of cards by oracle id', function () {
    $a = Card::factory()->create();
    $b = Card::factory()->create();
    Card::factory()->create(); // noise

    Passport::actingAs(User::factory()->create(), ['read']);
    $res = $this->getJson('/api/v1/cards?ids='.$a->oracle_id.','.$b->oracle_id)->assertOk();
    expect($res->json('data'))->toHaveCount(2);
});

it('shows a single card by oracle id', function () {
    $card = Card::factory()->create(['name' => 'Ragavan, Nimble Pilferer']);
    Passport::actingAs(User::factory()->create(), ['read']);
    $this->getJson("/api/v1/cards/{$card->oracle_id}")
        ->assertOk()->assertJsonPath('data.name', 'Ragavan, Nimble Pilferer');
});

it('requires auth for the card catalog', function () {
    $this->getJson('/api/v1/cards')->assertStatus(401);
});
```

Add a `CardFactory` if absent (unique `oracle_id`, `mtgo_id`, `name`, `type`, `color_identity`, `mana_cost`, `image`).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=CardEndpointsTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php // app/Http/Requests/Api/V1/IndexCardRequest.php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class IndexCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['sometimes', 'string'],
            'name' => ['sometimes', 'string', 'max:128'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }

    /** @return array<int, string> */
    public function oracleIds(): array
    {
        return array_filter(explode(',', (string) $this->string('ids')));
    }
}
```

```php
<?php // app/Http/Controllers/Api/V1/Cards/IndexCardController.php

namespace App\Http\Controllers\Api\V1\Cards;

use App\Http\Requests\Api\V1\IndexCardRequest;
use App\Http\Resources\V1\CardResource;
use App\Models\Card;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class IndexCardController
{
    public function __invoke(IndexCardRequest $request): AnonymousResourceCollection
    {
        $cards = Card::query()
            ->when($request->oracleIds() !== [], fn ($q) => $q->whereIn('oracle_id', $request->oracleIds()))
            ->when($request->string('name')->isNotEmpty(), fn ($q) => $q->where('name', 'like', '%'.$request->string('name').'%'))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 100));

        return CardResource::collection($cards);
    }
}
```

```php
<?php // app/Http/Controllers/Api/V1/Cards/ShowCardController.php

namespace App\Http\Controllers\Api\V1\Cards;

use App\Http\Resources\V1\CardResource;
use App\Models\Card;

final class ShowCardController
{
    public function __invoke(Card $card): CardResource
    {
        return CardResource::make($card);
    }
}
```

Routes (bind `card` on `oracle_id`):

```php
use App\Http\Controllers\Api\V1\Cards\{IndexCardController, ShowCardController};
Route::get('/cards', IndexCardController::class)->name('cards.index');
Route::get('/cards/{card:oracle_id}', ShowCardController::class)->name('cards.show');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=CardEndpointsTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/Api/V1/IndexCardRequest.php app/Http/Resources/V1/CardResource.php app/Http/Controllers/Api/V1/Cards routes/api_v1.php database/factories/CardFactory.php tests/Feature/Api/V1/Cards/CardEndpointsTest.php
git commit -m "feat(api): GET /api/v1/cards batch-resolve + show (auth-gated reference data)"
```

---

### Task 10: Archetype catalog endpoint (`IndexArchetypeController`) — free reference data

**Files:**
- Create: `app/Http/Controllers/Api/V1/Archetypes/IndexArchetypeController.php` (reuses `ArchetypeResource` from Task 5)
- Modify: `routes/api_v1.php`
- Test: `tests/Feature/Api/V1/Archetypes/IndexArchetypeTest.php`

**Interfaces:**
- Consumes: `archetypes` where `user_id IS NULL` (global) **OR** `user_id = $request->user()->id` (owned) — see [`../catalog/spec.md`](../catalog/spec.md) (nullable-`user_id` global-vs-owned rule).
- Produces: `GET /api/v1/archetypes?format=...` → the user's visible archetype catalog (global + owned), for the client's local `archetype_catalog` mirror. Free endpoint.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Api/V1/Archetypes/IndexArchetypeTest.php
use App\Models\{Archetype, User};
use Laravel\Passport\Passport;

it('returns global archetypes plus the user\'s own, but not another user\'s', function () {
    $me = User::factory()->create();
    Archetype::factory()->create(['user_id' => null]);          // global
    Archetype::factory()->for($me)->create();                    // mine
    Archetype::factory()->for(User::factory())->create();        // someone else's

    Passport::actingAs($me, ['read']);
    expect($this->getJson('/api/v1/archetypes')->assertOk()->json('data'))->toHaveCount(2);
});

it('filters archetypes by format', function () {
    $me = User::factory()->create();
    Archetype::factory()->create(['user_id' => null, 'format' => 'CModern']);
    Archetype::factory()->create(['user_id' => null, 'format' => 'CLegacy']);

    Passport::actingAs($me, ['read']);
    expect($this->getJson('/api/v1/archetypes?format=CModern')->assertOk()->json('data'))->toHaveCount(1);
});
```

Add an `ArchetypeFactory` if absent (uuid, name, format, color_identity, nullable `user_id`).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=IndexArchetypeTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php // app/Http/Controllers/Api/V1/Archetypes/IndexArchetypeController.php

namespace App\Http\Controllers\Api\V1\Archetypes;

use App\Http\Resources\V1\ArchetypeResource;
use App\Models\Archetype;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class IndexArchetypeController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $archetypes = Archetype::query()
            ->where(function ($q) use ($request) {
                $q->whereNull('user_id')->orWhere('user_id', $request->user()->id);
            })
            ->when($request->string('format')->isNotEmpty(), fn ($q) => $q->where('format', $request->string('format')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 200));

        return ArchetypeResource::collection($archetypes);
    }
}
```

Route:

```php
use App\Http\Controllers\Api\V1\Archetypes\IndexArchetypeController;
Route::get('/archetypes', IndexArchetypeController::class)->name('archetypes.index');
```

Ensure `ArchetypeResource` (Task 5) exposes uuid, name, format, color_identity (and confidence when loaded via `match_archetypes`).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=IndexArchetypeTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/Archetypes/IndexArchetypeController.php routes/api_v1.php app/Http/Resources/V1/ArchetypeResource.php database/factories/ArchetypeFactory.php tests/Feature/Api/V1/Archetypes/IndexArchetypeTest.php
git commit -m "feat(api): GET /api/v1/archetypes (global + owned, format-filtered)"
```

---

### Task 11: Stats endpoint (`ShowStatsController`) — PAID-only, `plan`-gated

**Files:**
- Create: `app/Actions/Stats/ComputeUserStats.php`, `app/Http/Resources/V1/StatsResource.php`
- Create: `app/Http/Controllers/Api/V1/Stats/ShowStatsController.php`
- Modify: `routes/api_v1.php`
- Test: `tests/Feature/Api/V1/Stats/ShowStatsTest.php`

**Interfaces:**
- Consumes: `$request->user()`'s `matches` + `games` (aggregated in SQL, not hydrated per-row).
- Produces: `GET /api/v1/stats` → `StatsResource` (overall win-rate, match count, game win-rate, per-format + per-archetype breakdown). **Paid-only** — mounted behind `plan` middleware (402 for free). Consumed by the desktop/web stats page.

- [ ] **Step 1: Write the failing test (ownership scope + plan gate)**

```php
<?php // tests/Feature/Api/V1/Stats/ShowStatsTest.php
use App\Models\{MtgoMatch, User};
use Laravel\Passport\Passport;

it('rejects a free user with 402', function () {
    Passport::actingAs(User::factory()->create(['plan' => 'free']), ['read']);
    $this->getJson('/api/v1/stats')->assertStatus(402);
});

it('computes win-rate over only the paid user\'s matches', function () {
    $me = User::factory()->create(['plan' => 'paid']);
    MtgoMatch::factory()->for($me)->count(3)->create(['outcome' => 'Win']);
    MtgoMatch::factory()->for($me)->count(1)->create(['outcome' => 'Loss']);
    MtgoMatch::factory()->for(User::factory())->count(5)->create(['outcome' => 'Win']); // other user — must not count

    Passport::actingAs($me, ['read']);
    $res = $this->getJson('/api/v1/stats')->assertOk();

    $res->assertJsonPath('data.matches.total', 4);
    $res->assertJsonPath('data.matches.wins', 3);
    expect($res->json('data.matches.win_rate'))->toBe(0.75);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ShowStatsTest`
Expected: FAIL.

- [ ] **Step 3: Implement the aggregate action + resource + controller**

```php
<?php // app/Actions/Stats/ComputeUserStats.php

namespace App\Actions\Stats;

use App\Models\User;

final class ComputeUserStats
{
    /** @return array<string, mixed> */
    public function run(User $user): array
    {
        $matches = $user->matches();
        $total = (clone $matches)->count();
        $wins = (clone $matches)->where('outcome', 'Win')->count();
        $losses = (clone $matches)->where('outcome', 'Loss')->count();

        $byFormat = (clone $matches)
            ->selectRaw('format, count(*) as total, sum(case when outcome = ? then 1 else 0 end) as wins', ['Win'])
            ->groupBy('format')
            ->get()
            ->map(fn ($row) => [
                'format' => $row->format,
                'total' => (int) $row->total,
                'wins' => (int) $row->wins,
                'win_rate' => $row->total > 0 ? round($row->wins / $row->total, 4) : null,
            ])->all();

        return [
            'matches' => [
                'total' => $total,
                'wins' => $wins,
                'losses' => $losses,
                'win_rate' => $total > 0 ? round($wins / $total, 4) : null,
            ],
            'by_format' => $byFormat,
        ];
    }
}
```

```php
<?php // app/Http/Resources/V1/StatsResource.php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StatsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource; // already an array from ComputeUserStats
    }
}
```

```php
<?php // app/Http/Controllers/Api/V1/Stats/ShowStatsController.php

namespace App\Http\Controllers\Api\V1\Stats;

use App\Actions\Stats\ComputeUserStats;
use App\Http\Resources\V1\StatsResource;
use Illuminate\Http\Request;

final class ShowStatsController
{
    public function __construct(private ComputeUserStats $computeUserStats) {}

    public function __invoke(Request $request): StatsResource
    {
        return StatsResource::make($this->computeUserStats->run($request->user()));
    }
}
```

Route — **behind the `plan` gate**:

```php
use App\Http\Controllers\Api\V1\Stats\ShowStatsController;
Route::get('/stats', ShowStatsController::class)->middleware('plan')->name('stats.show');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ShowStatsTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Stats/ComputeUserStats.php app/Http/Resources/V1/StatsResource.php app/Http/Controllers/Api/V1/Stats/ShowStatsController.php routes/api_v1.php tests/Feature/Api/V1/Stats/ShowStatsTest.php
git commit -m "feat(api): GET /api/v1/stats aggregate win-rate (paid-only, plan-gated)"
```

---

### Task 12: Per-user private channel authorization

**Files:**
- Modify: `routes/channels.php`
- Test: `tests/Feature/Api/V1/Realtime/ChannelAuthTest.php`

**Interfaces:**
- Consumes: the `App.Models.User.{id}` private channel name + `broadcasting/auth` (Passport-guarded from Task 1).
- Produces: a channel-auth callback returning `true` only when `$user->id === (int) $id` — a user may subscribe to **their own** channel only. Consumed by Task 13's broadcast + all clients.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Api/V1/Realtime/ChannelAuthTest.php
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

it('authorizes a user for their own private channel', function () {
    $user = User::factory()->create();
    $granted = Broadcast::channel('App.Models.User.{id}', function ($authUser, $id) {
        return (int) $authUser->id === (int) $id;
    });
    // exercise the registered callback via the resolver
    $callback = fn ($u, $id) => (int) $u->id === (int) $id;
    expect($callback($user, $user->id))->toBeTrue();
    expect($callback($user, $user->id + 1))->toBeFalse();
});

it('denies channel auth for a stranger\'s channel over HTTP', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($me)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-App.Models.User.'.$other->id,
        ])->assertForbidden();
});

it('grants channel auth for the user\'s own channel over HTTP', function () {
    $me = User::factory()->create();

    $this->actingAs($me)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-App.Models.User.'.$me->id,
        ])->assertOk();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ChannelAuthTest`
Expected: FAIL — channel not registered / auth returns 403 for own channel.

- [ ] **Step 3: Register the channel**

```php
<?php // routes/channels.php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id): bool {
    return $user->id === $id;
});
```

Confirm `Broadcast::routes(['middleware' => ['auth:api']])` is applied (Task 1). For the HTTP test that uses `actingAs` (session), also allow the `web` guard for `broadcasting/auth` — register the broadcasting routes with both guards: `Broadcast::routes(['middleware' => ['auth:web,api']])` so desktop (Bearer) and web (session) both authenticate. Adjust the Task 1 `channels.php` accordingly.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ChannelAuthTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/channels.php tests/Feature/Api/V1/Realtime/ChannelAuthTest.php
git commit -m "feat(api): per-user private channel authorization (own channel only)"
```

---

### Task 13: `MatchLogged` broadcast event + `NotifyMatchLogged` worker hook (thin signal)

**Files:**
- Create: `app/Events/MatchLogged.php`, `app/Actions/Realtime/NotifyMatchLogged.php`
- Test: `tests/Feature/Api/V1/Realtime/MatchLoggedBroadcastTest.php`

**Interfaces:**
- Consumes: called by the [`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md) worker **after DB commit** — `NotifyMatchLogged::run(int $userId, string $matchKey, int $version)`.
- Produces: broadcasts `MatchLogged` on `private-App.Models.User.{userId}` with `as` name `match.logged` and payload **exactly** `{ matchKey, version }` — no match data. Clients receive it and refetch via Task 8's catch-up. Best-effort liveness only.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Api/V1/Realtime/MatchLoggedBroadcastTest.php
use App\Actions\Realtime\NotifyMatchLogged;
use App\Events\MatchLogged;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

it('broadcasts a thin match.logged signal on the user\'s private channel', function () {
    Event::fake([MatchLogged::class]);
    $user = User::factory()->create();

    app(NotifyMatchLogged::class)->run($user->id, 'match-key-1', 7);

    Event::assertDispatched(MatchLogged::class, function (MatchLogged $e) use ($user) {
        $channels = $e->broadcastOn();
        return $channels[0] instanceof PrivateChannel
            && $channels[0]->name === 'private-App.Models.User.'.$user->id
            && $e->broadcastAs() === 'match.logged'
            && $e->broadcastWith() === ['matchKey' => 'match-key-1', 'version' => 7];
    });
});

it('carries no match payload beyond key + version', function () {
    $event = new MatchLogged(1, 'k', 3);
    expect(array_keys($event->broadcastWith()))->toBe(['matchKey', 'version']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=MatchLoggedBroadcastTest`
Expected: FAIL — classes not defined.

- [ ] **Step 3: Implement the event + hook**

```php
<?php // app/Events/MatchLogged.php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class MatchLogged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $userId,
        public string $matchKey,
        public int $version,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'match.logged';
    }

    /** @return array{matchKey: string, version: int} */
    public function broadcastWith(): array
    {
        return ['matchKey' => $this->matchKey, 'version' => $this->version];
    }
}
```

```php
<?php // app/Actions/Realtime/NotifyMatchLogged.php

namespace App\Actions\Realtime;

use App\Events\MatchLogged;

final class NotifyMatchLogged
{
    public function run(int $userId, string $matchKey, int $version): void
    {
        MatchLogged::dispatch($userId, $matchKey, $version);
    }
}
```

The [`../cloud-pipeline/plan.md`](../cloud-pipeline/plan.md) worker calls `NotifyMatchLogged::run(...)` in its post-commit step (that wiring is a task in the pipeline plan; here we own the event + channel contract it targets). `MatchLogged implements ShouldBroadcast` (not `ShouldBroadcastNow`) so it enqueues — keeping the worker's commit path fast; the broadcast job pushes to Reverb.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=MatchLoggedBroadcastTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Events/MatchLogged.php app/Actions/Realtime/NotifyMatchLogged.php tests/Feature/Api/V1/Realtime/MatchLoggedBroadcastTest.php
git commit -m "feat(api): MatchLogged thin broadcast + NotifyMatchLogged worker hook"
```

---

## Self-Review checklist (run after fleshing 1–13)

1. **Spec coverage** — every [`spec.md`](./spec.md) bullet maps to a task: read API for matches/games/decks/stats/cards/archetypes (Tasks 4–7, 9–11); user-scoping + ownership (Tasks 3, 5, 6, 7 + `abort_unless(...404)`); per-endpoint `plan` gate (Task 2 middleware, applied in Task 11); catch-up fetch (Task 8); Reverb setup + per-user private channel + thin `match.logged` broadcast (Tasks 1, 12, 13); channel authorization (Task 12).
2. **Ownership everywhere** — no read endpoint returns another user's data; cross-user requests are **404** (never leak existence), verified by a stranger test in Tasks 4, 5, 6, 7, 8, 11.
3. **Entitlement** — paid-only endpoints (Task 11 stats) return **402** for `free`; free endpoints (matches, games, decks, cards, archetypes, catch-up) are auth-only. Confirm each route's middleware matches its intended gate.
4. **No N+1** — every list/show eager-loads; the query-count assertions in Tasks 4 and 5 hold independent of row count; `whenLoaded` guards every relation in the resources.
5. **Thin socket, fat fetch** — `MatchLogged::broadcastWith()` is exactly `{ matchKey, version }` (Task 13 asserts the key set); correctness is the catch-up fetch (Task 8), socket is best-effort liveness only.
6. **Placeholder scan** — no "TBD" / "handle edge cases" / "similar to Task N"; exact paths, complete controller/resource/event code.
7. **Route order** — `/matches/catch-up` (Task 8) is registered **before** `/matches/{match:match_key}` (Task 5) so the literal path is not swallowed by the wildcard.
8. **Sibling-plan assumptions** — every model/table this plan reads is owned by [`../cloud-pipeline/plan.md`](../cloud-pipeline/plan.md) / [`../cloud-auth/plan.md`](../cloud-auth/plan.md); factories created here are noted per task and should be reconciled (not duplicated) when those plans land.
