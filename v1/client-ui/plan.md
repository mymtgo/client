# Client UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Format:** kept overlays are re-sourced + restyled (not rebuilt); new viewing pages get complete TDD steps. Full component code is given for new components — no placeholders.

**Goal:** Flip the desktop client's viewing surface from **display-from-local-DB** to **display-from-cloud-JSON-API**. Delete every page/controller/model that renders the (now-deleted) local display schema; stand up API-consumer viewing pages (dashboard, history, stats, decks, cards) that render data fetched from the cloud read API; add the local **needs-attention** outcome-edit UI (Unknown-outcome matches → manual edit → bakes into `{match}.json` → re-push) and an **account-management** page (plan status, sign out, delete account). **Keep the overlay windows** (deck-odds, opponent-scout, league overlay) — re-source them off the ingest agent's in-memory/local data (never the deleted display models) and restyle only.

**Non-goal (deferred):** visual polish. **The redesign is dead (decision 2026-07-09, see [`spec.md`](./spec.md)) — v1 keeps the 0.x visual design.** This plan ships **functional / near-raw** pages against the *final data shape* so each page is built once. A later pass restyles them to match the 0.x look (a known target — port from the `0.x` branch, consolidating each primitive to one canonical treatment). Do not invest in bespoke visual design here — reuse `components/ui/` primitives and the existing Tailwind theme.

**Tech Stack:** Inertia.js v2, Vue 3 `<script setup>` + TypeScript, Tailwind CSS v4, Laravel 12 (thin Inertia controllers), Laravel Wayfinder (typed routes), Pest v4 (Feature + browser/smoke tests), shadcn-vue primitives in `resources/js/components/ui/`.

## Global Constraints

- **The client holds NO queryable match data.** Every viewing page's data comes from the **cloud read API** ([`../cloud-api/spec.md`](../cloud-api/spec.md)), fetched **server-side inside the Inertia controller** via the existing `Http::mymtgoApi()` macro (`config/mymtgo_api.php`, base URL + `verify_ssl`) with the `Authorization: Bearer` token from the local `AppAccount` binding (from [`../client-agent/plan.md`](../client-agent/plan.md) Task 8 / [`../client-auth/spec.md`](../client-auth/spec.md)). Controllers are **thin API-proxy + `Inertia::render`** — no local Eloquent reads for display data.
- **Data flip is why pages get rebuilt, not the look.** Build each page against the **final `{match}.json`-derived shape** ([`../contract/spec.md`](../contract/spec.md)) so it is authored once; the later 0.x-restyle pass changes markup/tokens only, not the data contract.
- **KEEP overlays — re-source + restyle, never rebuild logic.** `ComputeDrawOdds` and the deck-odds/opponent-scout/league overlay windows stay. Their controllers currently read the **deleted** display models (`MtgoMatch`/`Game`/`League`) — re-source them off the ingest agent's live/local data ([`../client-agent/spec.md`](../client-agent/spec.md) §1: in-memory log events + MTGO XML on disk), keeping the `OverlayLayout` + window wiring intact. This plan only touches their **data source + styling**, not the overlay UX.
- **Auth-gated + plan-gated, server is source of truth.** Unauthenticated → main window never renders (auth window handled in [`../client-auth/spec.md`](../client-auth/spec.md)). Free-vs-paid is a **per-page binary** decided by the API's `plan` field ([`../ops/spec.md`](../ops/spec.md)); the API enforces it regardless of the client. The UI **locks** gated pages when the API says `plan: free` (or returns 402/403), showing an upsell state — never a blank page.
- **Empty / loading / error are first-class.** Every API-fed page renders: a **loading skeleton** (`components/ui/skeleton`) while a deferred prop resolves, an **empty state** (`components/ui/empty`) when the API returns zero rows, and a **reachability error** state when the API is unreachable (reuse the `CheckApiStatus` `unreachable`/`noauth` shape). Never a raw stack trace.
- **Reuse before create.** Prefer existing `components/ui/` primitives, `ManaSymbols`, `WinRateBar`, `useToast`, and the `AppLayout`/`AppNav` shell. Do not rebuild buttons/inputs/tables/cards. Follow `.claude/rules/vue.md`: lowercase directories, PascalCase `.vue` files, Composition API, typed `defineProps`.
- **Wayfinder for all routes.** Frontend links/reloads use generated imports from `@/actions/` (controllers) or `@/routes/`. Run `php artisan wayfinder:generate` after adding/removing routes. No hardcoded URL strings.
- **Realtime = liveness, catch-up = correctness.** Fresh-data refresh is driven by the Reverb `match.logged` thin signal ([`../cloud-api/spec.md`](../cloud-api/spec.md)) → the page **refetches via the read API** (`router.reload`); the socket never carries match data. A catch-up refetch also fires on app-foreground / reconnect.
- Run `vendor/bin/pint --dirty --format agent` before finalizing any task with PHP changes.
- Tests: Pest v4, folded into the task whose deliverable needs them. Page tests use `$this->get(route(...))->assertOk()` with `Http::fake()` stubbing the cloud API, asserting on `page.props`. Add a **browser/smoke test** (Pest 4 `visit()`) per viewing page asserting **no console/JS errors** and that key data renders. `Http::fake()` and the NativePHP `Settings`/`AppSettings`/`Window` fakes are already global in `tests/Pest.php`.

---

## File Structure

**New (API-consumer controllers — thin proxy + render):**
- `app/Support/CloudApi.php` — typed wrapper around `Http::mymtgoApi()->withToken(...)` (SRP: the cloud read-API client) with `get(string $path, array $query = []): CloudApiResult`.
- `app/Data/CloudApiResult.php` — `{ ok: bool, status: int, data: array, plan: string, locked: bool, unreachable: bool }` (Spatie Data).
- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/History/IndexController.php`, `app/Http/Controllers/History/ShowController.php`
- `app/Http/Controllers/Stats/IndexController.php`
- `app/Http/Controllers/Decks/IndexController.php` (rewritten), `app/Http/Controllers/Decks/ShowController.php`
- `app/Http/Controllers/Cards/IndexController.php` (rewritten)
- `app/Http/Controllers/Account/IndexController.php`, `app/Http/Controllers/Account/SignOutController.php`, `app/Http/Controllers/Account/DeleteController.php`
- `app/Http/Controllers/NeedsAttention/IndexController.php`, `app/Http/Controllers/NeedsAttention/UpdateOutcomeController.php`
- `app/Actions/Outbox/SetManualOutcome.php` — bakes a manual outcome into `{match}.json`, bumps `file_version`, re-enqueues (reuses `EnqueueMatch`).

**New (Inertia pages — functional/near-raw, `components/ui/` primitives only):**
- `resources/js/pages/Dashboard.vue`
- `resources/js/pages/history/Index.vue`, `resources/js/pages/history/Show.vue`
- `resources/js/pages/stats/Index.vue`
- `resources/js/pages/decks/Index.vue` (rewritten), `resources/js/pages/decks/Show.vue`
- `resources/js/pages/cards/Index.vue` (rewritten)
- `resources/js/pages/account/Index.vue`
- `resources/js/pages/needs-attention/Index.vue`
- `resources/js/components/PageState.vue` — shared loading/empty/unreachable/locked wrapper (SRP: page-level async state).
- `resources/js/components/PlanLock.vue` — gated-page upsell overlay.
- `resources/js/composables/useCloudRefresh.ts` — subscribes to the `match.logged` signal + app-foreground → `router.reload`.

**Refactored (shell — de-couple from deleted models):**
- `app/Http/Middleware/HandleInertiaRequests.php` — drop `MtgoMatch::submittable()` / `Account` / `Game::count()` shared props; share `auth` (from `AppAccount`), `plan`, and `needsAttentionCount` (from local `outbox`).
- `resources/js/components/AppNav.vue` — new nav (Dashboard / History / Stats / Decks / Cards / Account) + a "Needs attention" indicator.
- `resources/js/AppLayout.vue`, `resources/js/components/StatusBar.vue` — remove references to deleted local match/ingest-display props (keep ingest **status** props that still exist locally).

**KEEP — re-source data + restyle only (do NOT rebuild logic):**
- `app/Http/Controllers/Decks/PopoutController.php` + `resources/js/pages/decks/Popout.vue` + `resources/js/components/decks/DrawOddsPanel.vue` (deck-odds overlay; `ComputeDrawOdds` kept).
- `app/Http/Controllers/Leagues/OverlayController.php` + `resources/js/pages/leagues/Overlay.vue` (league/stream overlay).
- `app/Http/Controllers/Leagues/OpponentScoutWindowController.php` + `resources/js/pages/leagues/OpponentScout.vue` (opponent scout).
- `resources/js/Layouts/OverlayLayout.vue`, overlay window registration in `NativeAppServiceProvider`.

**Deleted (display-from-local-DB layer — pages):**
- `resources/js/pages/decks/{Dashboard,CardStats,GameStats,Decklist,Leagues,Matches,Matchups,Tournaments,Settings}.vue` + `resources/js/pages/decks/partials/*` (except anything the KEEP overlay imports).
- `resources/js/pages/{matches,games,leagues,opponents,reports,archetypes,import,debug}/**` (except `leagues/Overlay.vue` + `leagues/OpponentScout.vue`).
- `resources/js/pages/partials/Dashboard*.vue`, `resources/js/pages/partials/RecentMatches.vue`, `resources/js/pages/Index.vue`.

**Deleted (display-from-local-DB layer — controllers):**
- `app/Http/Controllers/{Matches,Games,Opponents,Reports,Archetypes,Import,Debug}/**`, and all `Decks/*` **except** `PopoutController`.
- `app/Http/Controllers/Leagues/**` **except** `OverlayController` + `OpponentScoutWindowController`.
- `app/Http/Controllers/IndexController.php` (replaced by `DashboardController`).
- `app/Http/Middleware/*` and settings controllers that read deleted models (assess per Task 1; settings that drive **ingest/overlay** stay).

> Model deletion (`MtgoMatch`, `Game`, `CardGameStat`, `Deck`, `League`, etc.) + their migrations is owned by [`../client-agent/plan.md`](../client-agent/plan.md) ("Deleted (display / local-DB layer)"). **This plan assumes those models/tables are gone** and removes only the *UI* that referenced them. Sequence this plan **after** the client-agent deletion, or coordinate the two deletions in one branch.

---

### Task 1: Prune the display layer + green the app shell

**Files:**
- Delete: the pages + controllers listed under "Deleted" above.
- Modify: `routes/web.php` (remove routes to deleted controllers; keep overlay routes `decks.popout`, `leagues.overlay`, `leagues.opponent-scout`), `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/components/AppNav.vue`, `resources/js/AppLayout.vue`, `resources/js/components/StatusBar.vue`.
- Test: `tests/Feature/Http/AppShellRendersTest.php`

**Interfaces:**
- Produces: a bootable app whose shell (`AppLayout` + `AppNav` + shared Inertia props) references **no deleted model**. Overlay routes still resolve. Consumed by every later task (they render inside this shell).

- [ ] **Step 1: Write the failing shell test**

```php
<?php // tests/Feature/Http/AppShellRendersTest.php
use Illuminate\Support\Facades\Http;

it('renders the shell without touching deleted display models', function () {
    Http::fake([config('mymtgo_api.url').'/api/*' => Http::response(['data' => []], 200)]);

    // the home route now points at the new DashboardController (Task 2 fills it in);
    // here we only assert the shared-prop shape no longer references deleted models.
    $middleware = app(App\Http\Middleware\HandleInertiaRequests::class);
    $shared = $middleware->share(request());

    expect($shared)->not->toHaveKey('accounts');       // old local Account list — gone
    expect($shared)->not->toHaveKey('activeAccount');
    expect($shared)->toHaveKey('auth');                // AppAccount-derived
    expect($shared)->toHaveKey('plan');                // free|paid from binding
    expect($shared)->toHaveKey('needsAttentionCount'); // local outbox Unknown count
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AppShellRendersTest`
Expected: FAIL — shared props still expose `accounts`/`activeAccount`; new keys missing.

- [ ] **Step 3: Delete the display pages + controllers**

Remove the files under "Deleted (display-from-local-DB layer)". In `routes/web.php`, delete every route + `use` import bound to a deleted controller. **Keep** the overlay routes (`decks/popout`, `leagues/overlay`, `leagues/opponent-scout`) and the settings/ingest routes that don't read deleted models. Confirm nothing imports a deleted page: `grep -rn "pages/matches\|pages/reports\|pages/archetypes\|pages/opponents\|DashboardDecks\|RecentMatches" resources/js` returns only files you're deleting.

- [ ] **Step 4: Rewrite the shared props**

In `HandleInertiaRequests::share()` remove `activeAccount`, `accounts`, and the `Game::count()` donation gate's dependency on deleted models. Add:

```php
'auth' => fn () => ($a = App\Models\AppAccount::on('mymtgo')->where('active', true)->first())
    ? ['mtgoUsername' => $a->mtgo_username, 'mtgoPlayerId' => $a->mtgo_player_id]
    : null,
'plan' => fn () => App\Models\AppAccount::on('mymtgo')->where('active', true)->value('plan') ?? 'free',
'needsAttentionCount' => fn () => App\Models\Outbox::on('mymtgo')
    ->whereJsonContains('payload->match->outcome', 'Unknown')->count(),
```

(Keep the existing ingest `status` block — `watcherRunning`/`lastIngestAt` still exist locally.)

- [ ] **Step 5: Rewrite `AppNav.vue`**

```vue
<script setup lang="ts">
import DashboardController from '@/actions/App/Http/Controllers/DashboardController';
import HistoryIndexController from '@/actions/App/Http/Controllers/History/IndexController';
import StatsIndexController from '@/actions/App/Http/Controllers/Stats/IndexController';
import DecksIndexController from '@/actions/App/Http/Controllers/Decks/IndexController';
import CardsIndexController from '@/actions/App/Http/Controllers/Cards/IndexController';
import AccountIndexController from '@/actions/App/Http/Controllers/Account/IndexController';
import NeedsAttentionIndexController from '@/actions/App/Http/Controllers/NeedsAttention/IndexController';
import { Link, usePage } from '@inertiajs/vue3';
import { AlertTriangle, BarChart3, Layers, Layers2Icon, LayoutDashboard, History, UserCog } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();

const nav = [
    { label: 'Dashboard', icon: LayoutDashboard, href: DashboardController.url() },
    { label: 'History', icon: History, href: HistoryIndexController.url() },
    { label: 'Stats', icon: BarChart3, href: StatsIndexController.url() },
    { label: 'Decks', icon: Layers, href: DecksIndexController.url() },
    { label: 'Cards', icon: Layers2Icon, href: CardsIndexController.url() },
    { label: 'Account', icon: UserCog, href: AccountIndexController.url() },
];

const needsAttention = computed(() => (page.props.needsAttentionCount as number) ?? 0);
const isActive = (href: string) => (href === '/' ? page.url === '/' : page.url.startsWith(href));
</script>

<template>
    <nav class="flex shrink-0 items-center gap-1 border-b border-black/60 bg-background px-4 py-2 shadow shadow-black/20">
        <Link
            v-for="item in nav"
            :key="item.label"
            :href="item.href"
            prefetch="hover"
            cache-for="10s"
            class="relative inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium transition-colors"
            :class="isActive(item.href)
                ? 'text-background-accent border-black shadow-inner shadow-black outline-[1px] outline-white/10'
                : 'bevel border-black/60 text-white hover:bg-accent/50 hover:text-accent-foreground'"
        >
            <component :is="item.icon" class="size-4" />
            {{ item.label }}
        </Link>
        <Link
            v-if="needsAttention > 0"
            :href="NeedsAttentionIndexController.url()"
            class="bevel ml-auto inline-flex items-center gap-1.5 rounded-md border border-amber-500/60 px-3 py-1.5 text-sm font-medium text-amber-400 hover:bg-amber-500/10"
        >
            <AlertTriangle class="size-4" />
            {{ needsAttention }} need{{ needsAttention === 1 ? 's' : '' }} attention
        </Link>
    </nav>
</template>
```

Strip the deleted-model references from `AppLayout.vue` + `StatusBar.vue` (remove `pendingMatchCount`, `activeAccount`, donation-modal game-count coupling; keep ingest status + update banner).

- [ ] **Step 6: Run test to verify it passes + wayfinder + lint**

Run: `php artisan wayfinder:generate` then `php artisan test --compact --filter=AppShellRendersTest`
Expected: PASS. Also `grep` confirms no `@/pages/...` import points at a deleted page.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/HandleInertiaRequests.php routes/web.php resources/js/components/AppNav.vue resources/js/AppLayout.vue resources/js/components/StatusBar.vue tests/Feature/Http/AppShellRendersTest.php
git add -A app/Http/Controllers resources/js/pages   # records the deletions
git commit -m "feat(client-ui): delete display-from-local-DB layer, green the app shell"
```

---

### Task 2: Cloud read-API client (`CloudApi` + `CloudApiResult`)

**Files:**
- Create: `app/Support/CloudApi.php`, `app/Data/CloudApiResult.php`
- Test: `tests/Feature/Support/CloudApiTest.php`

**Interfaces:**
- Consumes: `Http::mymtgoApi()` (existing macro), the Bearer token from the active `AppAccount` (from [`../client-agent/plan.md`](../client-agent/plan.md) Task 8).
- Produces: `app(CloudApi::class)->get(string $path, array $query = []): CloudApiResult` — every viewing controller calls this exactly once and passes the result straight into `Inertia::render`. Maps HTTP 402/403 → `locked: true`; connection failure / non-2xx → `unreachable: true`; success → `ok: true` + `data`. Reads the `plan` off the response envelope (or the shared binding). Consumed by Tasks 3–8.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Support/CloudApiTest.php
use App\Support\CloudApi;
use App\Models\AppAccount;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => AppAccount::on('mymtgo')->create([
    'user_id' => 1, 'mtgo_player_id' => 147160, 'mtgo_username' => 'Pro_MTG',
    'access_token' => 'tok-123', 'plan' => 'paid', 'active' => true,
]));

it('sends the bearer token and returns ok with data on 200', function () {
    Http::fake([config('mymtgo_api.url').'/api/matches*' => Http::response(['data' => [['match_key' => 'a']], 'plan' => 'paid'], 200)]);

    $result = app(CloudApi::class)->get('/api/matches', ['page' => 1]);

    expect($result->ok)->toBeTrue();
    expect($result->data['data'])->toHaveCount(1);
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok-123'));
});

it('flags locked on 402/403 (plan gate)', function () {
    Http::fake([config('mymtgo_api.url').'/api/stats*' => Http::response(['message' => 'upgrade'], 402)]);
    $result = app(CloudApi::class)->get('/api/stats');
    expect($result->locked)->toBeTrue();
    expect($result->ok)->toBeFalse();
});

it('flags unreachable when the API connection fails', function () {
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('down'));
    $result = app(CloudApi::class)->get('/api/matches');
    expect($result->unreachable)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=CloudApiTest`
Expected: FAIL — classes not defined.

- [ ] **Step 3: Implement `CloudApiResult` + `CloudApi`**

```php
<?php // app/Data/CloudApiResult.php
namespace App\Data;

use Spatie\LaravelData\Data;

class CloudApiResult extends Data
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public bool $ok,
        public int $status,
        public array $data,
        public string $plan,
        public bool $locked,
        public bool $unreachable,
    ) {}
}
```

```php
<?php // app/Support/CloudApi.php
namespace App\Support;

use App\Data\CloudApiResult;
use App\Models\AppAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class CloudApi
{
    /** @param array<string, mixed> $query */
    public function get(string $path, array $query = []): CloudApiResult
    {
        $account = AppAccount::on('mymtgo')->where('active', true)->first();
        $plan = $account?->plan ?? 'free';

        try {
            $response = Http::mymtgoApi()
                ->withToken($account?->access_token)
                ->timeout(10)
                ->connectTimeout(5)
                ->get($path, $query);
        } catch (ConnectionException|Throwable $e) {
            return new CloudApiResult(ok: false, status: 0, data: [], plan: $plan, locked: false, unreachable: true);
        }

        if (in_array($response->status(), [402, 403], true)) {
            return new CloudApiResult(ok: false, status: $response->status(), data: [], plan: $plan, locked: true, unreachable: false);
        }

        if (! $response->successful()) {
            return new CloudApiResult(ok: false, status: $response->status(), data: [], plan: $plan, locked: false, unreachable: true);
        }

        $body = $response->json() ?? [];

        return new CloudApiResult(
            ok: true,
            status: $response->status(),
            data: $body,
            plan: $body['plan'] ?? $plan,
            locked: false,
            unreachable: false,
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=CloudApiTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/CloudApi.php app/Data/CloudApiResult.php tests/Feature/Support/CloudApiTest.php
git commit -m "feat(client-ui): cloud read-API client (bearer + locked/unreachable mapping)"
```

---

### Task 3: Shared page-state + plan-lock components + cloud refresh composable

**Files:**
- Create: `resources/js/components/PageState.vue`, `resources/js/components/PlanLock.vue`, `resources/js/composables/useCloudRefresh.ts`
- Test: `tests/Feature/Pages/PageStateSmokeTest.php` (via the first page that uses them — folded into Task 4). No standalone PHP test; verified by the page smoke tests.

**Interfaces:**
- Produces:
  - `<PageState :state="'loading'|'empty'|'unreachable'|'ready'">` with named slots `#ready` / `#empty` — a viewing page wraps its body in this so loading/empty/unreachable are consistent everywhere.
  - `<PlanLock :locked="boolean" feature="Stats">` — renders an upsell overlay when `locked`, otherwise the default slot.
  - `useCloudRefresh(only?: string[])` — on mount, subscribes to the NativePHP-bridged `match.logged` signal + `visibilitychange` (app-foreground) and calls `router.reload({ only })`. Consumed by Tasks 4–8.

- [ ] **Step 1: Implement `PageState.vue`**

```vue
<script setup lang="ts">
import { Empty, EmptyContent, EmptyDescription, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { Skeleton } from '@/components/ui/skeleton';
import { CloudOff, Inbox } from 'lucide-vue-next';

defineProps<{
    state: 'loading' | 'empty' | 'unreachable' | 'ready';
    emptyTitle?: string;
    emptyDescription?: string;
}>();
</script>

<template>
    <div v-if="state === 'loading'" class="flex flex-col gap-3 p-4">
        <Skeleton v-for="n in 6" :key="n" class="h-10 w-full" />
    </div>

    <Empty v-else-if="state === 'unreachable'" class="m-4">
        <EmptyMedia variant="icon"><CloudOff /></EmptyMedia>
        <EmptyContent>
            <EmptyTitle>Can't reach mymtgo</EmptyTitle>
            <EmptyDescription>Your data lives in the cloud. Check your connection and try again.</EmptyDescription>
        </EmptyContent>
    </Empty>

    <Empty v-else-if="state === 'empty'" class="m-4">
        <EmptyMedia variant="icon"><Inbox /></EmptyMedia>
        <EmptyContent>
            <EmptyTitle>{{ emptyTitle ?? 'Nothing here yet' }}</EmptyTitle>
            <EmptyDescription>{{ emptyDescription ?? 'Play some matches and they will show up here.' }}</EmptyDescription>
        </EmptyContent>
        <slot name="empty" />
    </Empty>

    <slot v-else name="ready" />
</template>
```

- [ ] **Step 2: Implement `PlanLock.vue`**

```vue
<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Lock } from 'lucide-vue-next';
import AccountIndexController from '@/actions/App/Http/Controllers/Account/IndexController';
import { Link } from '@inertiajs/vue3';

defineProps<{ locked: boolean; feature: string }>();
</script>

<template>
    <div v-if="locked" class="flex flex-col items-center justify-center gap-4 p-12 text-center">
        <div class="flex size-12 items-center justify-center rounded-lg bg-muted text-muted-foreground">
            <Lock class="size-6" />
        </div>
        <div class="flex flex-col gap-1">
            <p class="font-medium">{{ feature }} is a paid feature</p>
            <p class="text-sm text-muted-foreground">Upgrade your plan to unlock {{ feature.toLowerCase() }}.</p>
        </div>
        <Button as-child size="sm"><Link :href="AccountIndexController.url()">Manage plan</Link></Button>
    </div>
    <slot v-else />
</template>
```

- [ ] **Step 3: Implement `useCloudRefresh.ts`**

```ts
import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

/**
 * Realtime = liveness, catch-up = correctness. The socket only signals; the page
 * refetches its data via the read API. Also refetches on app-foreground / reconnect.
 */
export function useCloudRefresh(only: string[] = []) {
    const reload = () => router.reload({ only });
    const onVisible = () => document.visibilityState === 'visible' && reload();

    onMounted(() => {
        window.Native?.on('App\\Events\\MatchLogged', reload);
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('online', reload);
    });
    onUnmounted(() => {
        document.removeEventListener('visibilitychange', onVisible);
        window.removeEventListener('online', reload);
    });
}
```

- [ ] **Step 4: No standalone test**

These are exercised by every page smoke test (Task 4 onward). Confirm they compile: `npm run build` (or rely on the Task 4 smoke test which imports them).

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/PageState.vue resources/js/components/PlanLock.vue resources/js/composables/useCloudRefresh.ts
git commit -m "feat(client-ui): shared page-state, plan-lock, and cloud-refresh primitives"
```

---

### Task 4: History pages (list + detail) — the reference API-consumer page

**Files:**
- Create: `app/Http/Controllers/History/IndexController.php`, `app/Http/Controllers/History/ShowController.php`, `resources/js/pages/history/Index.vue`, `resources/js/pages/history/Show.vue`
- Modify: `routes/web.php` (add `history.index`, `history.show`)
- Test: `tests/Feature/Pages/HistoryPageTest.php`, `tests/Feature/Pages/HistoryPageSmokeTest.php`

**Interfaces:**
- Consumes: `CloudApi` (Task 2), `PageState` (Task 3).
- Produces: `/history` → list of matches from `GET /api/matches` (paginated, filterable by format/outcome); `/history/{matchKey}` → one match + its games from `GET /api/matches/{matchKey}`. This task establishes the **controller pattern** every later viewing page copies (proxy → `Inertia::render` with `apiState` + `data`).

- [ ] **Step 1: Write the failing page test**

```php
<?php // tests/Feature/Pages/HistoryPageTest.php
use Illuminate\Support\Facades\Http;

it('renders the match history from the cloud API', function () {
    Http::fake([config('mymtgo_api.url').'/api/matches*' => Http::response([
        'data' => [[
            'match_key' => '95f4d09f', 'format' => 'CModern', 'match_type' => 'League',
            'outcome' => 'Win', 'started_at' => '2026-07-01T00:00:00Z',
            'opponent' => ['username' => 'anticloser'],
        ]],
        'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1],
        'plan' => 'paid',
    ], 200)]);

    $props = $this->get(route('history.index'))->assertOk()->original->getData()['page']['props'];

    expect($props['apiState'])->toBe('ready');
    expect($props['matches']['data'])->toHaveCount(1);
    expect($props['matches']['data'][0]['match_key'])->toBe('95f4d09f');
});

it('passes the unreachable state through when the API is down', function () {
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('down'));
    $props = $this->get(route('history.index'))->assertOk()->original->getData()['page']['props'];
    expect($props['apiState'])->toBe('unreachable');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=HistoryPageTest`
Expected: FAIL — route/controller not defined.

- [ ] **Step 3: Implement the controllers**

```php
<?php // app/Http/Controllers/History/IndexController.php
namespace App\Http\Controllers\History;

use App\Http\Controllers\Controller;
use App\Support\CloudApi;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __construct(private CloudApi $api) {}

    public function __invoke(Request $request): Response
    {
        $result = $this->api->get('/api/matches', $request->only(['page', 'format', 'outcome', 'search']));

        return Inertia::render('history/Index', [
            'apiState' => $result->ok ? 'ready' : ($result->unreachable ? 'unreachable' : 'locked'),
            'locked' => $result->locked,
            'matches' => [
                'data' => $result->data['data'] ?? [],
                'meta' => $result->data['meta'] ?? ['current_page' => 1, 'last_page' => 1, 'total' => 0],
            ],
            'filters' => $request->only(['format', 'outcome', 'search']),
        ]);
    }
}
```

```php
<?php // app/Http/Controllers/History/ShowController.php
namespace App\Http\Controllers\History;

use App\Http\Controllers\Controller;
use App\Support\CloudApi;
use Inertia\Inertia;
use Inertia\Response;

class ShowController extends Controller
{
    public function __construct(private CloudApi $api) {}

    public function __invoke(string $matchKey): Response
    {
        $result = $this->api->get("/api/matches/{$matchKey}");

        return Inertia::render('history/Show', [
            'apiState' => $result->ok ? 'ready' : ($result->unreachable ? 'unreachable' : 'locked'),
            'locked' => $result->locked,
            'match' => $result->data['data'] ?? null,
        ]);
    }
}
```

Register routes:

```php
$router->group(['prefix' => 'history'], function (Router $group) {
    $group->get('/', App\Http\Controllers\History\IndexController::class)->name('history.index');
    $group->get('{matchKey}', App\Http\Controllers\History\ShowController::class)->name('history.show');
});
```

- [ ] **Step 4: Implement `history/Index.vue`** (functional/near-raw — table of matches via `components/ui`)

```vue
<script setup lang="ts">
import PageState from '@/components/PageState.vue';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Link } from '@inertiajs/vue3';
import HistoryShowController from '@/actions/App/Http/Controllers/History/ShowController';
import { useCloudRefresh } from '@/composables/useCloudRefresh';

type Match = {
    match_key: string; format: string; match_type: string;
    outcome: 'Win' | 'Loss' | 'Draw' | 'Unknown';
    started_at: string; opponent: { username: string | null };
};

const props = defineProps<{
    apiState: 'ready' | 'empty' | 'unreachable' | 'locked';
    matches: { data: Match[]; meta: { current_page: number; last_page: number; total: number } };
    filters: { format?: string; outcome?: string; search?: string };
}>();

useCloudRefresh(['matches']);

const state = props.apiState === 'ready' && props.matches.data.length === 0 ? 'empty' : props.apiState;
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <h1 class="text-lg font-semibold">Match history</h1>
        <PageState :state="state" empty-title="No matches yet" empty-description="Once you play matches they will appear here.">
            <template #ready>
                <Card class="overflow-hidden">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Result</TableHead>
                                <TableHead>Opponent</TableHead>
                                <TableHead>Format</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Played</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="m in matches.data" :key="m.match_key">
                                <TableCell>
                                    <Link :href="HistoryShowController.url({ matchKey: m.match_key })" class="font-medium underline-offset-2 hover:underline">
                                        {{ m.outcome }}
                                    </Link>
                                </TableCell>
                                <TableCell>{{ m.opponent?.username ?? 'Unknown' }}</TableCell>
                                <TableCell>{{ m.format }}</TableCell>
                                <TableCell>{{ m.match_type }}</TableCell>
                                <TableCell class="whitespace-nowrap text-sm text-muted-foreground">{{ m.started_at }}</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </Card>
            </template>
        </PageState>
    </div>
</template>
```

- [ ] **Step 5: Implement `history/Show.vue`** — near-raw: match header (format/type/outcome/opponent) + a per-game list (games array from the contract) inside `PageState`. Reuse `Card`, `Table`. Wrap in `useCloudRefresh(['match'])`.

- [ ] **Step 6: Add the smoke test (no JS errors + key data renders)**

```php
<?php // tests/Feature/Pages/HistoryPageSmokeTest.php
use Illuminate\Support\Facades\Http;

it('loads /history with no JS errors and shows a match', function () {
    Http::fake([config('mymtgo_api.url').'/api/matches*' => Http::response([
        'data' => [['match_key' => 'abc', 'format' => 'CModern', 'match_type' => 'League', 'outcome' => 'Win', 'started_at' => '2026-07-01T00:00:00Z', 'opponent' => ['username' => 'anticloser']]],
        'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1], 'plan' => 'paid',
    ], 200)]);

    $page = visit(route('history.index'));
    $page->assertNoJavascriptErrors()->assertSee('anticloser')->assertSee('CModern');
});
```

- [ ] **Step 7: Run + wayfinder + Pint + commit**

Run: `php artisan wayfinder:generate && php artisan test --compact --filter=HistoryPage`
Expected: PASS (both tests).

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/History routes/web.php resources/js/pages/history tests/Feature/Pages/HistoryPage*
git commit -m "feat(client-ui): match history list + detail from cloud read API"
```

---

### Task 5: Dashboard page (home)

**Files:**
- Create: `app/Http/Controllers/DashboardController.php`, `resources/js/pages/Dashboard.vue`
- Modify: `routes/web.php` (point `home` / `/` at `DashboardController`)
- Test: `tests/Feature/Pages/DashboardPageTest.php`, `tests/Feature/Pages/DashboardPageSmokeTest.php`

**Interfaces:**
- Consumes: `CloudApi` (Task 2), `PageState`, `useCloudRefresh` (Task 3).
- Produces: `/` → summary KPIs (record, win rate) + recent matches from `GET /api/dashboard` (or compose `GET /api/stats/summary` + `GET /api/matches?limit=5`). Uses `Inertia::defer` for below-the-fold sections so first paint is fast.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Pages/DashboardPageTest.php
use Illuminate\Support\Facades\Http;

it('renders dashboard KPIs + recent matches from the API', function () {
    Http::fake([config('mymtgo_api.url').'/api/dashboard*' => Http::response([
        'data' => [
            'summary' => ['matches' => 42, 'wins' => 25, 'losses' => 17, 'match_winrate' => 59.5],
            'recent' => [['match_key' => 'x', 'outcome' => 'Win', 'opponent' => ['username' => 'y'], 'format' => 'CModern', 'started_at' => '2026-07-01T00:00:00Z']],
        ],
        'plan' => 'paid',
    ], 200)]);

    $props = $this->get(route('home'))->assertOk()->original->getData()['page']['props'];

    expect($props['apiState'])->toBe('ready');
    expect($props['summary']['wins'])->toBe(25);
    expect($props['recent'])->toHaveCount(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=DashboardPageTest`
Expected: FAIL.

- [ ] **Step 3: Implement `DashboardController`** — copy the Task 4 proxy pattern; call `$this->api->get('/api/dashboard')`, map to `apiState` + `summary` + `recent`. Point `/` at it in `routes/web.php` (remove the old `IndexController` route).

- [ ] **Step 4: Implement `Dashboard.vue`** — a KPI strip (`Card` per stat, `WinRateBar` for win rate) + a compact recent-matches list, all inside `PageState`. `useCloudRefresh(['summary','recent'])`. No bespoke design — plain `Card`/grid.

- [ ] **Step 5: Add the smoke test** (`tests/Feature/Pages/DashboardPageSmokeTest.php`) — `visit(route('home'))->assertNoJavascriptErrors()->assertSee('59')`.

- [ ] **Step 6: Run + wayfinder + Pint + commit**

Run: `php artisan wayfinder:generate && php artisan test --compact --filter=DashboardPage`
Expected: PASS.

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/DashboardController.php routes/web.php resources/js/pages/Dashboard.vue tests/Feature/Pages/DashboardPage*
git commit -m "feat(client-ui): API-fed dashboard (KPIs + recent matches)"
```

---

### Task 6: Stats + Decks + Cards viewing pages (gated where the API says so)

**Files:**
- Create: `app/Http/Controllers/Stats/IndexController.php`, `app/Http/Controllers/Decks/IndexController.php` (rewrite), `app/Http/Controllers/Decks/ShowController.php`, `app/Http/Controllers/Cards/IndexController.php` (rewrite)
- Create: `resources/js/pages/stats/Index.vue`, `resources/js/pages/decks/Index.vue` (rewrite), `resources/js/pages/decks/Show.vue`, `resources/js/pages/cards/Index.vue` (rewrite)
- Modify: `routes/web.php` (`stats.index`, `decks.index`, `decks.show`, `cards.index`; keep `decks.popout`)
- Test: `tests/Feature/Pages/StatsPageTest.php`, `tests/Feature/Pages/DecksPageTest.php`, `tests/Feature/Pages/CardsPageTest.php` + a smoke test each

**Interfaces:**
- Consumes: `CloudApi`, `PageState`, `PlanLock`, `useCloudRefresh`.
- Produces: three more viewing pages following the Task 4 pattern. **Stats is the plan-gated exemplar**: when `CloudApi` returns `locked`, the controller passes `locked: true` and the page renders `<PlanLock feature="Stats">`. `decks.index` lists decks (`GET /api/decks`); `decks.show` renders one deck's stats (`GET /api/decks/{id}`); `cards.index` lists card stats (`GET /api/cards`).

- [ ] **Step 1: Write the failing tests (ready + locked)**

```php
<?php // tests/Feature/Pages/StatsPageTest.php
use Illuminate\Support\Facades\Http;

it('renders stats when the plan allows it', function () {
    Http::fake([config('mymtgo_api.url').'/api/stats*' => Http::response(['data' => ['by_format' => []], 'plan' => 'paid'], 200)]);
    $props = $this->get(route('stats.index'))->assertOk()->original->getData()['page']['props'];
    expect($props['apiState'])->toBe('ready');
    expect($props['locked'])->toBeFalse();
});

it('locks stats for free plan (API 402)', function () {
    Http::fake([config('mymtgo_api.url').'/api/stats*' => Http::response(['message' => 'upgrade'], 402)]);
    $props = $this->get(route('stats.index'))->assertOk()->original->getData()['page']['props'];
    expect($props['locked'])->toBeTrue();
});
```

Mirror `DecksPageTest` (`/api/decks`) and `CardsPageTest` (`/api/cards`) on the `ready` + `empty` paths.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="StatsPage|DecksPage|CardsPage"`
Expected: FAIL — routes/controllers not defined.

- [ ] **Step 3: Implement the four controllers** — each is the Task 4 proxy (`CloudApi::get` → `apiState` + `locked` + payload). `Decks/IndexController` and `Cards/IndexController` **replace** the current local-DB versions; delete their old bodies. Keep the `decks.popout` route pointing at the untouched `PopoutController`.

- [ ] **Step 4: Implement the four pages** — all near-raw:
  - `stats/Index.vue`: wrap body in `<PlanLock :locked feature="Stats">` then `<PageState>`; render a table of per-format win rates.
  - `decks/Index.vue`: `Card` grid / table of decks (name, format, `WinRateBar`), each linking to `decks.show`.
  - `decks/Show.vue`: deck header + stats table (match/game win rate, matchup rows) from `GET /api/decks/{id}`.
  - `cards/Index.vue`: table of card stats (name, seen/played/won). Reuse `ManaSymbols` where colours are present.
  All call `useCloudRefresh([...])`.

- [ ] **Step 5: Add a smoke test per page** — `visit(route(...))->assertNoJavascriptErrors()` with a stubbed 200 (and for stats, a second `visit` with 402 asserting the lock copy is visible).

- [ ] **Step 6: Run + wayfinder + Pint + commit**

Run: `php artisan wayfinder:generate && php artisan test --compact --filter="StatsPage|DecksPage|CardsPage"`
Expected: PASS.

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Stats app/Http/Controllers/Decks/IndexController.php app/Http/Controllers/Decks/ShowController.php app/Http/Controllers/Cards/IndexController.php routes/web.php resources/js/pages/stats resources/js/pages/decks/Index.vue resources/js/pages/decks/Show.vue resources/js/pages/cards/Index.vue tests/Feature/Pages
git commit -m "feat(client-ui): API-fed stats (plan-gated), decks, and cards pages"
```

---

### Task 7: Needs-attention outcome-edit UI (local outbox → bake into {match}.json → re-push)

**Files:**
- Create: `app/Http/Controllers/NeedsAttention/IndexController.php`, `app/Http/Controllers/NeedsAttention/UpdateOutcomeController.php`, `app/Actions/Outbox/SetManualOutcome.php`, `resources/js/pages/needs-attention/Index.vue`
- Modify: `routes/web.php` (`needs-attention.index`, `needs-attention.update-outcome`)
- Test: `tests/Feature/Pages/NeedsAttentionPageTest.php`, `tests/Feature/Outbox/SetManualOutcomeTest.php`

**Interfaces:**
- Consumes: the local `Outbox` (from [`../client-agent/plan.md`](../client-agent/plan.md) Task 11) — rows whose compiled `{match}.json` has `outcome: Unknown`; `EnqueueMatch` (re-enqueue after edit).
- Produces: `/needs-attention` → list of `Unknown`-outcome matches read from the **local outbox** (NOT the cloud API — this is a local-only surface per [`../client-agent/spec.md`](../client-agent/spec.md) §2). Submitting an outcome calls `SetManualOutcome`, which sets `match.outcome` + `outcome_source: "manual"` inside the stored `{match}.json`, bumps `file_version`, and re-enqueues for push. Server re-derivation preserves the manual value.

- [ ] **Step 1: Write the failing action test**

```php
<?php // tests/Feature/Outbox/SetManualOutcomeTest.php
use App\Actions\Outbox\SetManualOutcome;
use App\Models\Outbox;

it('bakes a manual outcome into the payload, bumps version, and re-enqueues', function () {
    $row = Outbox::on('mymtgo')->create([
        'match_key' => 'tok-1', 'file_version' => 3, 'status' => 'synced', 'synced_version' => 3,
        'payload' => ['match_key' => 'tok-1', 'match' => ['token' => 'tok-1', 'outcome' => 'Unknown', 'outcome_source' => 'unknown']],
    ]);

    app(SetManualOutcome::class)->run('tok-1', 'Loss');

    $fresh = $row->fresh();
    expect($fresh->payload['match']['outcome'])->toBe('Loss');
    expect($fresh->payload['match']['outcome_source'])->toBe('manual');
    expect($fresh->file_version)->toBe(4);      // bumped
    expect($fresh->status)->toBe('pending');    // re-queued for push
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=SetManualOutcomeTest`
Expected: FAIL — action not defined.

- [ ] **Step 3: Implement `SetManualOutcome`** — load the outbox row by `match_key`, set `payload.match.outcome` + `payload.match.outcome_source = 'manual'`, persist through `EnqueueMatch` (which bumps `file_version` on change + sets `status = pending`). Reuse `EnqueueMatch`; do not duplicate the versioning logic.

- [ ] **Step 4: Write the failing page + controller tests**

```php
<?php // tests/Feature/Pages/NeedsAttentionPageTest.php
use App\Models\Outbox;

it('lists local Unknown-outcome matches', function () {
    Outbox::on('mymtgo')->create(['match_key' => 'tok-1', 'file_version' => 1, 'status' => 'synced',
        'payload' => ['match_key' => 'tok-1', 'match' => ['token' => 'tok-1', 'outcome' => 'Unknown', 'format' => 'CModern', 'opponent' => ['username' => 'anticloser']]]]);

    $props = $this->get(route('needs-attention.index'))->assertOk()->original->getData()['page']['props'];
    expect($props['matches'])->toHaveCount(1);
    expect($props['matches'][0]['match_key'])->toBe('tok-1');
});

it('updates an outcome via the controller', function () {
    Outbox::on('mymtgo')->create(['match_key' => 'tok-1', 'file_version' => 1, 'status' => 'synced',
        'payload' => ['match_key' => 'tok-1', 'match' => ['token' => 'tok-1', 'outcome' => 'Unknown']]]);

    $this->patch(route('needs-attention.update-outcome', 'tok-1'), ['outcome' => 'Win'])->assertRedirect();
    expect(Outbox::on('mymtgo')->where('match_key', 'tok-1')->first()->payload['match']['outcome'])->toBe('Win');
});
```

- [ ] **Step 5: Implement the controllers + route** — `IndexController` reads local outbox rows with `outcome: Unknown` (reuse the shared-prop query from Task 1) and renders `needs-attention/Index`. `UpdateOutcomeController` validates `outcome` ∈ `Win|Loss|Draw` via a Form Request, calls `SetManualOutcome`, dispatches `PushOutboxJob` (from client-agent Task 12), and redirects back with a `useToast` success flash.

- [ ] **Step 6: Implement `needs-attention/Index.vue`** — a `Card` list of Unknown matches (opponent, format, when), each with a `Select` (Win/Loss/Draw) + a submit `Button` that posts via `<Form>` / `useForm` to `needs-attention.update-outcome`. Empty state via `PageState` ("Nothing needs attention"). This is a **local** page — no `useCloudRefresh`.

- [ ] **Step 7: Run + wayfinder + Pint + commit**

Run: `php artisan wayfinder:generate && php artisan test --compact --filter="NeedsAttention|SetManualOutcome"`
Expected: PASS.

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/NeedsAttention app/Actions/Outbox/SetManualOutcome.php routes/web.php resources/js/pages/needs-attention tests/Feature/Pages/NeedsAttentionPageTest.php tests/Feature/Outbox/SetManualOutcomeTest.php
git commit -m "feat(client-ui): needs-attention outcome-edit (bakes manual outcome + re-pushes)"
```

---

### Task 8: Account-management page (plan status, sign out, delete account)

**Files:**
- Create: `app/Http/Controllers/Account/IndexController.php`, `app/Http/Controllers/Account/SignOutController.php`, `app/Http/Controllers/Account/DeleteController.php`, `resources/js/pages/account/Index.vue`
- Modify: `routes/web.php` (`account.index`, `account.sign-out`, `account.delete`)
- Test: `tests/Feature/Pages/AccountPageTest.php`, `tests/Feature/Pages/AccountPageSmokeTest.php`

**Interfaces:**
- Consumes: the local `AppAccount` binding (username, `mtgo_player_id`, `plan`, token), `CloudApi` (for the authoritative plan / account status via `GET /api/account`).
- Produces: `/account` → shows bound MTGO identity (non-editable), the **plan** (free/paid, server-authoritative), and actions: **Sign out** (clears local tokens / `AppAccount.active`, reopens the auth window — [`../client-auth/spec.md`](../client-auth/spec.md)) and **Delete account** (confirm dialog → `DELETE /api/account`, then local sign-out; deactivate+obfuscate per [`../ops/spec.md`](../ops/spec.md)).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Pages/AccountPageTest.php
use App\Models\AppAccount;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => AppAccount::on('mymtgo')->create([
    'user_id' => 1, 'mtgo_player_id' => 147160, 'mtgo_username' => 'Pro_MTG',
    'access_token' => 'tok-123', 'plan' => 'paid', 'active' => true,
]));

it('shows the bound identity and server-authoritative plan', function () {
    Http::fake([config('mymtgo_api.url').'/api/account*' => Http::response(['data' => ['plan' => 'paid', 'email' => 'a@b.c']], 200)]);

    $props = $this->get(route('account.index'))->assertOk()->original->getData()['page']['props'];
    expect($props['account']['mtgoUsername'])->toBe('Pro_MTG');
    expect($props['account']['plan'])->toBe('paid');
});

it('signs out by deactivating the local binding', function () {
    $this->post(route('account.sign-out'))->assertRedirect();
    expect(AppAccount::on('mymtgo')->where('active', true)->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AccountPageTest`
Expected: FAIL — routes/controllers not defined.

- [ ] **Step 3: Implement the controllers** — `IndexController` merges the local binding (non-editable identity) with `CloudApi::get('/api/account')` (server-authoritative plan/email); `SignOutController` sets `AppAccount.active = false`, clears tokens, and triggers the auth-window reopen (dispatch the same event client-auth uses; behind an `if (app()->runningInConsole())` guard so tests don't touch NativePHP). `DeleteController` calls `CloudApi` `DELETE /api/account`, then signs out locally.

- [ ] **Step 4: Implement `account/Index.vue`** — a `Card` showing MTGO username + player id (read-only), a plan badge (`Badge`), a **Sign out** `Button`, and a **Delete account** flow using the `Dialog` primitive for confirmation (`<Form>` to `account.delete`). Show an `unreachable` note (via `PageState`) if the API status couldn't be fetched, but still allow local sign-out.

- [ ] **Step 5: Add the smoke test** — `visit(route('account.index'))->assertNoJavascriptErrors()->assertSee('Pro_MTG')`.

- [ ] **Step 6: Run + wayfinder + Pint + commit**

Run: `php artisan wayfinder:generate && php artisan test --compact --filter=AccountPage`
Expected: PASS.

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Account routes/web.php resources/js/pages/account tests/Feature/Pages/AccountPage*
git commit -m "feat(client-ui): account management (plan status, sign out, delete account)"
```

---

### Task 9: Re-source the KEEP overlays off the ingest agent (restyle only)

**Files:**
- Modify: `app/Http/Controllers/Decks/PopoutController.php`, `app/Http/Controllers/Leagues/OverlayController.php`, `app/Http/Controllers/Leagues/OpponentScoutWindowController.php`
- Modify (restyle only): `resources/js/pages/decks/Popout.vue`, `resources/js/components/decks/DrawOddsPanel.vue`, `resources/js/pages/leagues/Overlay.vue`, `resources/js/pages/leagues/OpponentScout.vue`
- Test: `tests/Feature/Pages/OverlayControllersTest.php`

**Interfaces:**
- Consumes: the ingest agent's **live/local** data — `ComputeDrawOdds` (kept), the active-match in-memory state / current MTGO XML on disk ([`../client-agent/spec.md`](../client-agent/spec.md) §1), the local `archetype_catalog` mirror for the scout. **NOT** the deleted `MtgoMatch`/`Game`/`League` models and **NOT** the cloud API (overlays must work offline + instant).
- Produces: the three overlay windows keep rendering, sourced off surviving local data. UX/logic unchanged; only the data source is re-pointed and the visuals brought in line with the restyled theme.

- [ ] **Step 1: Write the failing controller test**

```php
<?php // tests/Feature/Pages/OverlayControllersTest.php
it('renders the deck-odds popout without the deleted MtgoMatch model', function () {
    $props = $this->get(route('decks.popout'))->assertOk()->original->getData()['page']['props'];
    expect(array_key_exists('drawOdds', $props))->toBeTrue();   // null when no active match is fine
});

it('renders the league overlay off surviving local data', function () {
    $props = $this->get(route('leagues.overlay'))->assertOk()->original->getData()['page']['props'];
    expect(array_key_exists('league', $props))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=OverlayControllersTest`
Expected: FAIL — the controllers still `use App\Models\MtgoMatch` / `League`, which no longer exist (fatal on autoload).

- [ ] **Step 3: Re-source `PopoutController`** — replace the `MtgoMatch::whereIn(...)` active-match lookup with the ingest agent's live active-match source (the in-memory/live-state provider the overlay already relies on for `GameCardsSnapshotChanged`). Pass the resolved live match into `ComputeDrawOdds::run(...)` unchanged. If no active match: `drawOdds => null` (page already handles null).

- [ ] **Step 4: Re-source `OverlayController` + `OpponentScoutWindowController`** — rebuild the league/current-match/games payloads from the surviving local source (live match state + `archetype_catalog` mirror for the scout's archetype guess). Preserve the existing output prop **shape** so the Vue overlays need only restyling, not rewiring. The 5-minute league grace window + background-image logic stay (background comes from `AppSettings`, not deleted models).

- [ ] **Step 5: Restyle the overlay Vue files** — bring `Popout.vue` / `DrawOddsPanel.vue` / `Overlay.vue` / `OpponentScout.vue` in line with the restyled theme tokens (spacing/colour only). Keep `OverlayLayout`, the `window.Native?.on('...GameCardsSnapshotChanged')` reload, `rememberState()`, and the window registration untouched. This is the ONLY visual work in this plan (the overlays are a differentiator; the later 0.x-restyle pass covers the main-window surfaces).

- [ ] **Step 6: Run test + manual verify**

Run: `php artisan test --compact --filter=OverlayControllersTest`
Expected: PASS. Overlay live behaviour is verified in-app on Windows (the snapshot-reload path isn't exercisable in the PHP suite) — note this in the commit.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Decks/PopoutController.php app/Http/Controllers/Leagues/OverlayController.php app/Http/Controllers/Leagues/OpponentScoutWindowController.php resources/js/pages/decks/Popout.vue resources/js/components/decks/DrawOddsPanel.vue resources/js/pages/leagues/Overlay.vue resources/js/pages/leagues/OpponentScout.vue tests/Feature/Pages/OverlayControllersTest.php
git commit -m "feat(client-ui): re-source KEEP overlays off ingest agent (restyle only)"
```

---

## Self-Review checklist (run after fleshing Tasks 1–9)

1. **Spec coverage** — every bullet in [`spec.md`](./spec.md) maps to a task: base-first JSON pages = Tasks 4–6; delete display-from-local-DB = Task 1; overlay KEEP (re-source + restyle) = Task 9; needs-attention outcome-edit ([`../client-agent/spec.md`](../client-agent/spec.md) §2) = Task 7; account management ([`../ops/spec.md`](../ops/spec.md)) = Task 8; entitlement gating (server source of truth, UI locks) = Tasks 2 + 6; realtime liveness / catch-up correctness = Task 3. Visual polish is **explicitly deferred** and NOT planned here (and the redesign itself is dead — the later pass restyles to the kept 0.x design; see [`spec.md`](./spec.md)).
2. **No display-from-local-DB survivors** — `grep -rn "App\\\\Models\\\\MtgoMatch\|App\\\\Models\\\\Game\|App\\\\Models\\\\League\|App\\\\Models\\\\Deck" app/Http` returns only the re-sourced KEEP overlays' *removed* references (i.e. nothing after Task 9). No Inertia page imports a deleted `pages/*` component.
3. **Every viewing page** wraps its body in `PageState` (loading/empty/unreachable) and gated pages in `PlanLock`; none renders a raw error. Every viewing page has a Feature test (props from a stubbed API) **and** a browser smoke test (`assertNoJavascriptErrors`).
4. **Placeholder scan** — no "TBD" / "handle edge cases" / "similar to Task N"; new components have complete code; controllers all follow the single Task-4 proxy pattern.
5. **Route + type consistency** — every frontend link uses a Wayfinder import; `apiState` / `locked` prop names identical across Tasks 4–8; `wayfinder:generate` run in each route-touching task.
6. **Overlays untouched in logic** — Task 9 changed only data source + theme tokens; `OverlayLayout`, window registration, and `GameCardsSnapshotChanged` reload are byte-for-byte preserved.
