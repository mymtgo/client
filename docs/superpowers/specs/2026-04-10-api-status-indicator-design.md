# API Status Indicator — Design Spec

## Problem

Users have no visibility into whether the app can reach the mymtgo API. When the API is unreachable (firewall, network issues) or authentication fails (expired/corrupted token), the app shows a raw 500 error page with no way to diagnose or fix the problem.

## Solution

Add an API connectivity indicator to the status bar with on-demand diagnostics and self-service re-authentication.

---

## API Project: `GET /api/status` Endpoint

A new endpoint **outside** the `device-key` middleware group that manually validates credentials and returns a JSON status.

### Route

In `routes/api.php`, add before the `device-key` middleware group:

```php
Route::get('/status', StatusController::class);
```

### Controller: `StatusController`

Accepts the same `X-Device-Id` and `X-Api-Key` headers as authenticated endpoints. Performs the same validation as `ValidateDeviceKey` middleware but returns structured JSON instead of aborting.

**Response cases:**

| Condition | HTTP Status | JSON Body |
|---|---|---|
| Valid device + valid key + not expired | 200 | `{"status": "ok"}` |
| Missing headers | 200 | `{"status": "noauth", "message": "Missing authentication credentials."}` |
| Unknown device | 200 | `{"status": "noauth", "message": "Device not recognized. Please re-authenticate."}` |
| Bad API key | 200 | `{"status": "noauth", "message": "Invalid API key. Please re-authenticate."}` |
| Expired key | 200 | `{"status": "noauth", "message": "API key has expired. Please re-authenticate."}` |
| Blacklisted device | 200 | `{"status": "noauth", "message": "Device has been blocked."}` |

All responses return HTTP 200 so the client can distinguish "server reachable but auth failed" from "server unreachable" (connection exception).

Updates `last_seen_at` on the device when status is `ok`, same as the middleware does.

### Test

A Pest feature test covering each response case.

---

## Client Project: `CheckApiStatus` Action

New action at `app/Actions/CheckApiStatus.php`.

### Behavior

1. Calls `Http::mymtgoApi()->get('/api/status')`
2. Maps the response to one of three states:
   - `connected` — response received, `status === "ok"`
   - `noauth` — response received, `status === "noauth"` (carries the message)
   - `unreachable` — connection exception (timeout, DNS failure, refused, etc.)
3. Caches the result in Laravel cache with a 5-minute TTL under key `api_status`
4. Returns the result as an array: `['state' => string, 'message' => string|null, 'checked_at' => string]`

### Cache Structure

```php
[
    'state' => 'connected' | 'noauth' | 'unreachable',
    'message' => null | 'Invalid API key. Please re-authenticate.',
    'checked_at' => '2026-04-10T12:00:00+00:00',
]
```

---

## Client Project: Shared Inertia Prop

In `HandleInertiaRequests::share()`, add:

```php
'apiStatus' => fn () => Cache::get('api_status', [
    'state' => 'unknown',
    'message' => null,
    'checked_at' => null,
]),
```

The `unknown` state handles the case where cache is empty (app just installed, cache cleared). The status bar treats `unknown` the same as needing a check.

---

## Client Project: Settings Routes & Controllers

Two new POST endpoints under the settings route group:

### `POST /settings/check-api` — `CheckApiStatusController`

1. Calls `CheckApiStatus::run()` (which caches the result)
2. Returns `back()`

### `POST /settings/reauthenticate` — `ReauthenticateController`

1. Calls `RegisterDevice::run()`
2. Calls `CheckApiStatus::run()` to refresh cached status
3. Returns `back()`

Both are single-action invokable controllers following the existing settings controller pattern.

---

## Client Project: App Boot Integration

In `MtgoManager::runInitialSetup()`, after the existing `RegisterDevice` block, add:

```php
CheckApiStatus::run();
```

This seeds the cache on app startup so the first page load has a status to show.

---

## Client Project: Status Bar UI

Update `StatusBar.vue` to add an API status indicator after the existing watcher indicator.

### Layout

```
[green dot] Watching | Last ingestion 5 min ago    [API indicator]    2 matches pending
```

The API indicator sits after the spacer, before the pending matches count (right-aligned area).

### States

| State | Indicator | Label |
|---|---|---|
| `connected` | Green dot | "API Connected" |
| `noauth` | Yellow dot | "API Auth Failed" |
| `unreachable` | Red dot | "API Unreachable" |
| `unknown` | Gray dot | "API Unknown" |

### Click Behavior

Clicking the indicator opens a `Popover` (using existing `components/ui/popover/`) anchored to the indicator, opening upward (`side="top"`).

**Popover content when `connected`:**

- Text: "Connected to mymtgo.com" with a check icon
- "Check again" text button that POSTs to `/settings/check-api`

**Popover content when `noauth`:**

- Text: the `message` from the API (e.g., "Invalid API key. Please re-authenticate.")
- Primary button: "Re-authenticate" that POSTs to `/settings/reauthenticate`
- Secondary text: "Check again" link that POSTs to `/settings/check-api`

**Popover content when `unreachable`:**

- Text: "Could not reach mymtgo.com. Check your internet connection or firewall settings."
- "Check again" text button that POSTs to `/settings/check-api`

**Popover content when `unknown`:**

- Text: "API status has not been checked yet."
- "Check now" text button that POSTs to `/settings/check-api`

All button actions use `router.post()` with `preserveState: true` and close the popover on `onFinish`.

### Styling

- Follow existing status bar conventions: `text-xs text-muted-foreground`
- Dot sizes match the existing watcher dot: `size-1.5 rounded-full`
- Color classes: `bg-success` (green), `bg-yellow-500` (yellow/amber), `bg-destructive` (red), `bg-muted-foreground` (gray for unknown)
- Popover uses existing `PopoverContent` component with `side="top"` and `align="end"`
- Popover width: `w-72` (default from PopoverContent)

---

## Files Changed

### API Project (`/Users/alecritson/Dev/mymtgo/api`)

| File | Action |
|---|---|
| `app/Http/Controllers/StatusController.php` | Create |
| `routes/api.php` | Add route |
| `tests/Feature/StatusEndpointTest.php` | Create |

### Client Project (`/Volumes/Dev/mymtgo/client`)

| File | Action |
|---|---|
| `app/Actions/CheckApiStatus.php` | Create |
| `app/Http/Controllers/Settings/CheckApiStatusController.php` | Create |
| `app/Http/Controllers/Settings/ReauthenticateController.php` | Create |
| `app/Http/Middleware/HandleInertiaRequests.php` | Add `apiStatus` shared prop |
| `app/Managers/MtgoManager.php` | Add `CheckApiStatus::run()` call in `runInitialSetup` |
| `routes/web.php` | Add 2 settings routes |
| `resources/js/components/StatusBar.vue` | Add API indicator with popover |
| `tests/Feature/CheckApiStatusTest.php` | Create |
| `tests/Feature/Settings/CheckApiStatusControllerTest.php` | Create |
| `tests/Feature/Settings/ReauthenticateControllerTest.php` | Create |

---

## Out of Scope

- Periodic background polling of API status (check on boot + on demand is sufficient)
- Retry logic in `CheckApiStatus` (single attempt, user can manually retry)
- Changes to the archetype sync error handling (already addressed separately)
