# API Status Indicator — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an API connectivity indicator to the status bar so users can see whether the app can reach mymtgo.com, diagnose auth failures, and re-authenticate on demand.

**Architecture:** New `/api/status` endpoint on the API project performs auth validation and returns structured JSON. Client-side `CheckApiStatus` action calls it, caches the result, and exposes it via shared Inertia props. The status bar reads the cached state and shows a popover with diagnostics and a re-authenticate button.

**Tech Stack:** Laravel 12 (both projects), Pest v4, Vue 3 + Inertia v2, Tailwind v4, reka-ui Popover

---

## File Map

### API Project (`/Users/alecritson/Dev/mymtgo/api`)

| File | Action | Responsibility |
|---|---|---|
| `app/Http/Controllers/StatusController.php` | Create | Validates device credentials, returns JSON status |
| `routes/api.php` | Modify (line 14) | Add `GET /status` route before middleware group |
| `tests/Feature/StatusEndpointTest.php` | Create | Tests all 6 response cases |

### Client Project (`/Volumes/Dev/mymtgo/client`)

| File | Action | Responsibility |
|---|---|---|
| `app/Actions/CheckApiStatus.php` | Create | Calls `/api/status`, caches result |
| `app/Http/Controllers/Settings/CheckApiStatusController.php` | Create | Runs check, redirects back |
| `app/Http/Controllers/Settings/ReauthenticateController.php` | Create | Registers device, runs check, redirects back |
| `app/Http/Middleware/HandleInertiaRequests.php` | Modify (line 28) | Add `apiStatus` shared prop from cache |
| `app/Managers/MtgoManager.php` | Modify (line 142) | Call `CheckApiStatus::run()` on boot |
| `routes/web.php` | Modify (line 161) | Add 2 settings routes |
| `resources/js/components/StatusBar.vue` | Modify (full rewrite) | Add API indicator with popover |
| `tests/Feature/CheckApiStatusTest.php` | Create | Tests the action with faked HTTP |
| `tests/Feature/Settings/CheckApiStatusControllerTest.php` | Create | Tests the controller endpoint |
| `tests/Feature/Settings/ReauthenticateControllerTest.php` | Create | Tests the reauthenticate endpoint |

---

### Task 1: API — Create `StatusController` and Route

**Project:** `/Users/alecritson/Dev/mymtgo/api`

**Files:**
- Create: `app/Http/Controllers/StatusController.php`
- Modify: `routes/api.php:14`
- Test: `tests/Feature/StatusEndpointTest.php`

- [ ] **Step 1: Write the test file**

Create `tests/Feature/StatusEndpointTest.php`:

```php
<?php

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->artisan('migrate:fresh');
    DB::statement('PRAGMA foreign_keys = OFF');
});

it('returns ok for valid authenticated device', function () {
    $plain = 'test-api-key';
    Device::create([
        'device_id' => 'test-device',
        'api_key' => Hash::make($plain),
        'is_blacklisted' => false,
        'last_seen_at' => now(),
    ]);

    $response = $this->getJson('/api/status', [
        'X-Device-Id' => 'test-device',
        'X-Api-Key' => $plain,
    ]);

    $response->assertOk()->assertJson(['status' => 'ok']);
});

it('returns noauth when headers are missing', function () {
    $response = $this->getJson('/api/status');

    $response->assertOk()->assertJson([
        'status' => 'noauth',
        'message' => 'Missing authentication credentials.',
    ]);
});

it('returns noauth for unknown device', function () {
    $response = $this->getJson('/api/status', [
        'X-Device-Id' => 'nonexistent',
        'X-Api-Key' => 'some-key',
    ]);

    $response->assertOk()->assertJson([
        'status' => 'noauth',
        'message' => 'Device not recognized. Please re-authenticate.',
    ]);
});

it('returns noauth for invalid api key', function () {
    Device::create([
        'device_id' => 'test-device',
        'api_key' => Hash::make('correct-key'),
        'is_blacklisted' => false,
        'last_seen_at' => now(),
    ]);

    $response = $this->getJson('/api/status', [
        'X-Device-Id' => 'test-device',
        'X-Api-Key' => 'wrong-key',
    ]);

    $response->assertOk()->assertJson([
        'status' => 'noauth',
        'message' => 'Invalid API key. Please re-authenticate.',
    ]);
});

it('returns noauth for expired device key', function () {
    $plain = 'test-api-key';
    Device::create([
        'device_id' => 'test-device',
        'api_key' => Hash::make($plain),
        'is_blacklisted' => false,
        'expires_at' => now()->subHour(),
        'last_seen_at' => now(),
    ]);

    $response = $this->getJson('/api/status', [
        'X-Device-Id' => 'test-device',
        'X-Api-Key' => $plain,
    ]);

    $response->assertOk()->assertJson([
        'status' => 'noauth',
        'message' => 'API key has expired. Please re-authenticate.',
    ]);
});

it('returns noauth for blacklisted device', function () {
    $plain = 'test-api-key';
    Device::create([
        'device_id' => 'test-device',
        'api_key' => Hash::make($plain),
        'is_blacklisted' => true,
        'last_seen_at' => now(),
    ]);

    $response = $this->getJson('/api/status', [
        'X-Device-Id' => 'test-device',
        'X-Api-Key' => $plain,
    ]);

    $response->assertOk()->assertJson([
        'status' => 'noauth',
        'message' => 'Device has been blocked.',
    ]);
});

it('updates last_seen_at on successful status check', function () {
    $plain = 'test-api-key';
    $device = Device::create([
        'device_id' => 'test-device',
        'api_key' => Hash::make($plain),
        'is_blacklisted' => false,
        'last_seen_at' => now()->subDay(),
    ]);

    $this->getJson('/api/status', [
        'X-Device-Id' => 'test-device',
        'X-Api-Key' => $plain,
    ]);

    expect($device->fresh()->last_seen_at->isToday())->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/alecritson/Dev/mymtgo/api && php artisan test --compact --filter=StatusEndpoint`
Expected: All tests fail (route not found / 404)

- [ ] **Step 3: Create the controller**

Run: `cd /Users/alecritson/Dev/mymtgo/api && php artisan make:class Http/Controllers/StatusController --no-interaction`

Then replace the file contents with:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatusController
{
    public function __invoke(Request $request): JsonResponse
    {
        $deviceId = $request->header('X-Device-Id');
        $apiKey = $request->header('X-Api-Key');

        if (! $deviceId || ! $apiKey) {
            return response()->json([
                'status' => 'noauth',
                'message' => 'Missing authentication credentials.',
            ]);
        }

        $device = Device::where('device_id', $deviceId)->first();

        if (! $device) {
            return response()->json([
                'status' => 'noauth',
                'message' => 'Device not recognized. Please re-authenticate.',
            ]);
        }

        if ($device->is_blacklisted) {
            return response()->json([
                'status' => 'noauth',
                'message' => 'Device has been blocked.',
            ]);
        }

        if (! $device->verifyKey($apiKey)) {
            return response()->json([
                'status' => 'noauth',
                'message' => 'Invalid API key. Please re-authenticate.',
            ]);
        }

        if ($device->expires_at && $device->expires_at->isPast()) {
            return response()->json([
                'status' => 'noauth',
                'message' => 'API key has expired. Please re-authenticate.',
            ]);
        }

        $device->update(['last_seen_at' => now()]);

        return response()->json(['status' => 'ok']);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/api.php`, add after `Route::post('/devices/register', RegisterDeviceController::class);` and before the `Route::middleware('device-key')` group:

```php
Route::get('/status', \App\Http\Controllers\StatusController::class);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd /Users/alecritson/Dev/mymtgo/api && php artisan test --compact --filter=StatusEndpoint`
Expected: All 7 tests pass

- [ ] **Step 6: Run Pint**

Run: `cd /Users/alecritson/Dev/mymtgo/api && vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Commit**

```bash
cd /Users/alecritson/Dev/mymtgo/api
git add app/Http/Controllers/StatusController.php routes/api.php tests/Feature/StatusEndpointTest.php
git commit -m "Add GET /api/status endpoint for client connectivity checks"
```

---

### Task 2: Client — Create `CheckApiStatus` Action

**Project:** `/Volumes/Dev/mymtgo/client`

**Files:**
- Create: `app/Actions/CheckApiStatus.php`
- Test: `tests/Feature/CheckApiStatusTest.php`

- [ ] **Step 1: Write the test file**

Create `tests/Feature/CheckApiStatusTest.php`:

```php
<?php

use App\Actions\CheckApiStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('returns connected when API responds with ok', function () {
    Http::fake([
        '*/api/status' => Http::response(['status' => 'ok']),
    ]);

    $result = CheckApiStatus::run();

    expect($result['state'])->toBe('connected')
        ->and($result['message'])->toBeNull()
        ->and($result['checked_at'])->not->toBeNull();
});

it('returns noauth when API responds with noauth', function () {
    Http::fake([
        '*/api/status' => Http::response([
            'status' => 'noauth',
            'message' => 'API key has expired. Please re-authenticate.',
        ]),
    ]);

    $result = CheckApiStatus::run();

    expect($result['state'])->toBe('noauth')
        ->and($result['message'])->toBe('API key has expired. Please re-authenticate.');
});

it('returns unreachable when API connection fails', function () {
    Http::fake([
        '*/api/status' => fn () => throw new ConnectionException('Connection refused'),
    ]);

    $result = CheckApiStatus::run();

    expect($result['state'])->toBe('unreachable')
        ->and($result['message'])->toBe('Could not reach mymtgo.com. Check your internet connection or firewall settings.');
});

it('caches the result', function () {
    Http::fake([
        '*/api/status' => Http::response(['status' => 'ok']),
    ]);

    CheckApiStatus::run();

    $cached = Cache::get('api_status');
    expect($cached)->not->toBeNull()
        ->and($cached['state'])->toBe('connected');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=CheckApiStatusTest`
Expected: FAIL — class not found

- [ ] **Step 3: Create the action**

Create `app/Actions/CheckApiStatus.php`:

```php
<?php

namespace App\Actions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CheckApiStatus
{
    /**
     * @return array{state: string, message: string|null, checked_at: string}
     */
    public static function run(): array
    {
        try {
            $response = Http::mymtgoApi()->get('/api/status');
            $json = $response->json();

            $result = match ($json['status'] ?? null) {
                'ok' => [
                    'state' => 'connected',
                    'message' => null,
                ],
                'noauth' => [
                    'state' => 'noauth',
                    'message' => $json['message'] ?? 'Authentication failed.',
                ],
                default => [
                    'state' => 'unreachable',
                    'message' => 'Unexpected response from API.',
                ],
            };
        } catch (ConnectionException) {
            $result = [
                'state' => 'unreachable',
                'message' => 'Could not reach mymtgo.com. Check your internet connection or firewall settings.',
            ];
        }

        $result['checked_at'] = now()->toIso8601String();

        Cache::put('api_status', $result, now()->addMinutes(5));

        return $result;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=CheckApiStatusTest`
Expected: All 4 tests pass

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 6: Commit**

```bash
git add app/Actions/CheckApiStatus.php tests/Feature/CheckApiStatusTest.php
git commit -m "Add CheckApiStatus action with caching"
```

---

### Task 3: Client — Create Settings Controllers and Routes

**Project:** `/Volumes/Dev/mymtgo/client`

**Files:**
- Create: `app/Http/Controllers/Settings/CheckApiStatusController.php`
- Create: `app/Http/Controllers/Settings/ReauthenticateController.php`
- Modify: `routes/web.php:161`
- Test: `tests/Feature/Settings/CheckApiStatusControllerTest.php`
- Test: `tests/Feature/Settings/ReauthenticateControllerTest.php`

- [ ] **Step 1: Write test for CheckApiStatusController**

Create `tests/Feature/Settings/CheckApiStatusControllerTest.php`:

```php
<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('runs api status check and redirects back', function () {
    Http::fake([
        '*/api/status' => Http::response(['status' => 'ok']),
    ]);

    $this->post('/settings/check-api')
        ->assertRedirect();

    expect(Cache::get('api_status')['state'])->toBe('connected');
});
```

- [ ] **Step 2: Write test for ReauthenticateController**

Create `tests/Feature/Settings/ReauthenticateControllerTest.php`:

```php
<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('re-registers device and checks api status', function () {
    Http::fake([
        '*/api/devices/register' => Http::response(['api_key' => 'new-key']),
        '*/api/status' => Http::response(['status' => 'ok']),
    ]);

    $this->post('/settings/reauthenticate')
        ->assertRedirect();

    expect(Cache::get('api_status')['state'])->toBe('connected');
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact --filter=CheckApiStatusControllerTest && php artisan test --compact --filter=ReauthenticateControllerTest`
Expected: FAIL — 404 (routes don't exist)

- [ ] **Step 4: Create CheckApiStatusController**

Run: `php artisan make:class Http/Controllers/Settings/CheckApiStatusController --no-interaction`

Replace contents with:

```php
<?php

namespace App\Http\Controllers\Settings;

use App\Actions\CheckApiStatus;
use Illuminate\Http\RedirectResponse;

class CheckApiStatusController
{
    public function __invoke(): RedirectResponse
    {
        CheckApiStatus::run();

        return back();
    }
}
```

- [ ] **Step 5: Create ReauthenticateController**

Run: `php artisan make:class Http/Controllers/Settings/ReauthenticateController --no-interaction`

Replace contents with:

```php
<?php

namespace App\Http\Controllers\Settings;

use App\Actions\CheckApiStatus;
use App\Actions\RegisterDevice;
use Illuminate\Http\RedirectResponse;

class ReauthenticateController
{
    public function __invoke(): RedirectResponse
    {
        RegisterDevice::run();
        CheckApiStatus::run();

        return back();
    }
}
```

- [ ] **Step 6: Add routes**

In `routes/web.php`, add the two use statements at the top with the other settings imports (around line 50-65):

```php
use App\Http\Controllers\Settings\CheckApiStatusController;
use App\Http\Controllers\Settings\ReauthenticateController;
```

Then inside the settings group, after line 161 (`$group->patch('local-images', ...)`), add:

```php
        $group->post('check-api', CheckApiStatusController::class)->name('settings.check-api');
        $group->post('reauthenticate', ReauthenticateController::class)->name('settings.reauthenticate');
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --compact --filter=CheckApiStatusControllerTest && php artisan test --compact --filter=ReauthenticateControllerTest`
Expected: All tests pass

- [ ] **Step 8: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Settings/CheckApiStatusController.php app/Http/Controllers/Settings/ReauthenticateController.php routes/web.php tests/Feature/Settings/CheckApiStatusControllerTest.php tests/Feature/Settings/ReauthenticateControllerTest.php
git commit -m "Add check-api and reauthenticate settings controllers"
```

---

### Task 4: Client — Wire Up Shared Inertia Prop and Boot Integration

**Project:** `/Volumes/Dev/mymtgo/client`

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php:28`
- Modify: `app/Managers/MtgoManager.php:142`

- [ ] **Step 1: Add apiStatus to shared Inertia props**

In `app/Http/Middleware/HandleInertiaRequests.php`, add the `Cache` import at the top:

```php
use Illuminate\Support\Facades\Cache;
```

Then in the `share()` method, add after the `'flash'` entry (around line 32) and before `'status'`:

```php
            'apiStatus' => fn () => Cache::get('api_status', [
                'state' => 'unknown',
                'message' => null,
                'checked_at' => null,
            ]),
```

- [ ] **Step 2: Add CheckApiStatus to boot sequence**

In `app/Managers/MtgoManager.php`, add the import at the top of the file:

```php
use App\Actions\CheckApiStatus;
```

Then in `runInitialSetup()`, after the `RegisterDevice` block (after line 142 `}`), add:

```php
        CheckApiStatus::run();
```

- [ ] **Step 3: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 4: Run existing tests to verify nothing broke**

Run: `php artisan test --compact`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/HandleInertiaRequests.php app/Managers/MtgoManager.php
git commit -m "Share apiStatus Inertia prop and check on boot"
```

---

### Task 5: Client — Generate Wayfinder Routes

**Project:** `/Volumes/Dev/mymtgo/client`

- [ ] **Step 1: Generate Wayfinder types**

Run: `php artisan wayfinder:generate`

This generates TypeScript functions for the two new controllers so the frontend can import:
- `@/actions/App/Http/Controllers/Settings/CheckApiStatusController`
- `@/actions/App/Http/Controllers/Settings/ReauthenticateController`

- [ ] **Step 2: Verify the generated files exist**

Check that these files were created:
- `resources/js/actions/App/Http/Controllers/Settings/CheckApiStatusController.ts`
- `resources/js/actions/App/Http/Controllers/Settings/ReauthenticateController.ts`

- [ ] **Step 3: Commit**

```bash
git add resources/js/actions/
git commit -m "Generate Wayfinder routes for API status controllers"
```

---

### Task 6: Client — Update StatusBar with API Indicator and Popover

**Project:** `/Volumes/Dev/mymtgo/client`

**Files:**
- Modify: `resources/js/components/StatusBar.vue` (full rewrite)

- [ ] **Step 1: Rewrite StatusBar.vue**

Replace the entire contents of `resources/js/components/StatusBar.vue` with:

```vue
<script setup lang="ts">
import CheckApiStatusController from '@/actions/App/Http/Controllers/Settings/CheckApiStatusController';
import ReauthenticateController from '@/actions/App/Http/Controllers/Settings/ReauthenticateController';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Button } from '@/components/ui/button';
import { router, usePage } from '@inertiajs/vue3';
import { CircleCheck, CircleAlert, CircleX, CircleHelp, LoaderCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const page = usePage();

const status = computed(() => page.props.status as {
    watcherRunning: boolean;
    lastIngestAt: string | null;
    lastIngestAtHuman: string | null;
    pendingMatchCount: number;
});

const apiStatus = computed(() => page.props.apiStatus as {
    state: string;
    message: string | null;
    checked_at: string | null;
});

const checking = ref(false);
const reauthenticating = ref(false);
const popoverOpen = ref(false);

const dotClass = computed(() => {
    switch (apiStatus.value.state) {
        case 'connected': return 'bg-success';
        case 'noauth': return 'bg-yellow-500';
        case 'unreachable': return 'bg-destructive';
        default: return 'bg-muted-foreground';
    }
});

const label = computed(() => {
    switch (apiStatus.value.state) {
        case 'connected': return 'API Connected';
        case 'noauth': return 'API Auth Failed';
        case 'unreachable': return 'API Unreachable';
        default: return 'API Unknown';
    }
});

const StatusIcon = computed(() => {
    switch (apiStatus.value.state) {
        case 'connected': return CircleCheck;
        case 'noauth': return CircleAlert;
        case 'unreachable': return CircleX;
        default: return CircleHelp;
    }
});

const statusIconClass = computed(() => {
    switch (apiStatus.value.state) {
        case 'connected': return 'text-success';
        case 'noauth': return 'text-yellow-500';
        case 'unreachable': return 'text-destructive';
        default: return 'text-muted-foreground';
    }
});

const popoverMessage = computed(() => {
    switch (apiStatus.value.state) {
        case 'connected': return 'Connected to mymtgo.com';
        case 'noauth': return apiStatus.value.message ?? 'Authentication failed.';
        case 'unreachable': return 'Could not reach mymtgo.com. Check your internet connection or firewall settings.';
        default: return 'API status has not been checked yet.';
    }
});

function checkApi() {
    checking.value = true;
    router.post(CheckApiStatusController.url(), {}, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => { checking.value = false; },
    });
}

function reauthenticate() {
    reauthenticating.value = true;
    router.post(ReauthenticateController.url(), {}, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
            reauthenticating.value = false;
        },
    });
}
</script>

<template>
    <footer class="flex h-7 shrink-0 items-center gap-4 border-t bg-muted/30 px-3 text-xs text-muted-foreground">
        <!-- Watcher status -->
        <div class="flex items-center gap-1.5">
            <div
                class="size-1.5 rounded-full"
                :class="status.watcherRunning ? 'bg-success' : 'bg-destructive'"
            />
            <span>{{ status.watcherRunning ? 'Watching' : 'Stopped' }}</span>
        </div>

        <div class="h-3 w-px bg-border" />

        <!-- Last ingestion -->
        <span v-if="status.lastIngestAtHuman">
            Last ingestion {{ status.lastIngestAtHuman }}
        </span>
        <span v-else>Never ingested</span>

        <!-- Spacer -->
        <div class="flex-1" />

        <!-- API status -->
        <Popover v-model:open="popoverOpen">
            <PopoverTrigger as-child>
                <button class="flex items-center gap-1.5 rounded px-1.5 py-0.5 transition-colors hover:bg-accent/50">
                    <div class="size-1.5 rounded-full" :class="dotClass" />
                    <span>{{ label }}</span>
                </button>
            </PopoverTrigger>
            <PopoverContent side="top" align="end" class="w-72">
                <div class="flex flex-col gap-3">
                    <div class="flex items-start gap-2">
                        <component :is="StatusIcon" class="mt-0.5 size-4 shrink-0" :class="statusIconClass" />
                        <p class="text-sm text-foreground">{{ popoverMessage }}</p>
                    </div>

                    <!-- Re-authenticate button (noauth only) -->
                    <Button
                        v-if="apiStatus.state === 'noauth'"
                        size="sm"
                        :disabled="reauthenticating"
                        @click="reauthenticate"
                    >
                        <LoaderCircle v-if="reauthenticating" class="mr-1.5 size-3.5 animate-spin" />
                        Re-authenticate
                    </Button>

                    <!-- Check again link -->
                    <button
                        class="text-left text-xs text-muted-foreground transition-colors hover:text-foreground"
                        :disabled="checking"
                        @click="checkApi"
                    >
                        <LoaderCircle v-if="checking" class="mr-1 inline size-3 animate-spin" />
                        {{ apiStatus.state === 'unknown' ? 'Check now' : 'Check again' }}
                    </button>
                </div>
            </PopoverContent>
        </Popover>

        <div v-if="status.pendingMatchCount > 0" class="h-3 w-px bg-border" />

        <!-- Pending matches -->
        <span v-if="status.pendingMatchCount > 0">
            {{ status.pendingMatchCount }} match{{ status.pendingMatchCount === 1 ? '' : 'es' }} pending
        </span>
    </footer>
</template>
```

- [ ] **Step 2: Build and verify**

Run: `npx vite build`
Expected: Build completes without errors

- [ ] **Step 3: Commit**

```bash
git add resources/js/components/StatusBar.vue
git commit -m "Add API status indicator with popover to status bar"
```

---

### Task 7: Integration Verification

**Project:** `/Volumes/Dev/mymtgo/client`

- [ ] **Step 1: Run full client test suite**

Run: `php artisan test --compact`
Expected: All tests pass

- [ ] **Step 2: Run full API test suite**

Run: `cd /Users/alecritson/Dev/mymtgo/api && php artisan test --compact`
Expected: All tests pass

- [ ] **Step 3: Build frontend**

Run: `cd /Volumes/Dev/mymtgo/client && npx vite build`
Expected: Build succeeds

- [ ] **Step 4: Run Pint on both projects**

Run: `cd /Volumes/Dev/mymtgo/client && vendor/bin/pint --dirty --format agent`
Run: `cd /Users/alecritson/Dev/mymtgo/api && vendor/bin/pint --dirty --format agent`
Expected: Both pass
