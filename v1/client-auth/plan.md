# Client Auth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Format:** PHP token-exchange/storage/refresh logic gets complete TDD steps (Pest, `Http::fake` for `/oauth/token`). The Electron/window pieces (auth window, `mymtgo://` deep-link watcher, quit-flow) are **manually/observed-verified on Windows**, not exercised by the PHP suite — each such task says so explicitly (mirrors client-agent Task 2b).

**Goal:** Give the client the auth half of v1: a dedicated NativePHP auth window that runs OAuth2 Authorization Code **+ PKCE** against the cloud API's Passport server (`/oauth/authorize` → `mymtgo://oauth/callback` → `/oauth/token`), stores the resulting **per-device access + refresh tokens** locally, refreshes them silently on expiry, and gates the main window behind a resolved session. No client secret ships (PKCE public client).

**Architecture:** PKCE crypto + token exchange + storage + refresh are pure PHP **invokable Actions** driven by tests. The auth window and the `mymtgo://` callback are the Electron/NativePHP seam: the window opens the API's authorize URL; NativePHP's existing `open-url`/`second-instance` deep-link plumbing fires the `OpenedFromURL` event; a listener hands the callback URL to `HandleOAuthCallback`, which exchanges the code and swaps the auth window for the main window. Tokens live in encrypted `AppSettings`; an outgoing `Http::macro` attaches `Authorization: Bearer` and refreshes on `401`/near-expiry. This plan is the **client side only** — the Passport server, Discord handshake, and `mtgo_accounts` binding live in [`../cloud-auth/spec.md`](../cloud-auth/spec.md). The `app_account` table + `ResolveLocalIdentity` (username-mismatch guard) are **owned by [`../client-agent/plan.md`](../client-agent/plan.md) Task 8** — this plan reads that table and does **not** create or migrate it.

**Tech Stack:** PHP 8.4, Laravel 12, NativePHP 2.0 (Electron), Inertia v2 / Vue 3, Pest v4, Pint.

## Global Constraints

- **`deeplink_scheme` = `mymtgo`** — set via `NATIVEPHP_DEEPLINK_SCHEME=mymtgo` in `.env` / `.env.example`. The callback is exactly **`mymtgo://oauth/callback?code=...&state=...`**. NativePHP registers the protocol (`app.setAsDefaultProtocolClient`) and emits `Native\Desktop\Events\App\OpenedFromURL` for it — do not re-implement the protocol registration.
- **PKCE public client — NO client secret ships.** `code_challenge_method=S256`; verifier is 43–128 chars of unreserved base64url; challenge = `base64url(sha256(verifier))` with no padding. Never persist a client secret; never send one to `/oauth/token`.
- **OAuth endpoints live on the cloud API** at `config('mymtgo_api.url')` — `{url}/oauth/authorize` and `{url}/oauth/token`. The OAuth **client id** is a public identifier, shipped via `config('mymtgo_api.oauth_client_id')` (`MYMTGO_OAUTH_CLIENT_ID`). The redirect URI is the constant `mymtgo://oauth/callback`.
- **`state` is CSRF protection** — a random token generated with the verifier, stashed, and asserted equal on callback. A mismatched/absent `state` aborts the exchange (log + surface an error, never exchange the code).
- **Tokens are per-device, refreshable, revocable.** Store `access_token`, `refresh_token`, and `access_token_expires_at` **encrypted** via `AppSettings` (the concrete `App\Settings\AppSettings` already uses `Crypt`). Never log token values.
- **Silent refresh, no re-login.** On a `401` from the API or when the access token is within the skew window of expiry, POST `grant_type=refresh_token` to `/oauth/token`, replace the stored tokens, and retry once. A failed refresh (invalid/revoked refresh token) clears tokens and forces re-auth (reopen the auth window).
- **Main window is never shown to an unauthenticated user.** At boot, if no valid session resolves, open **only** the auth window; open the main window (and any overlays) **only after** a successful callback. This replaces the unconditional `Window::open()->title('mymtgo')` in `NativeAppServiceProvider::boot()`.
- **Reuse the existing HTTP conventions.** Follow the `Http::macro('mymtgoApi', ...)` pattern already in `AppServiceProvider::boot()`; add a Bearer-aware macro rather than a new HTTP service class.
- Use **invokable Actions** (single responsibility), not service classes. PHP 8 constructor promotion, explicit return types, curly braces on all control structures.
- Run `vendor/bin/pint --dirty --format agent` before finalizing any task.
- Tests: Pest v4, `Http::fake` for `/oauth/token`. `AppSettings` is already swapped for an in-memory subclass in `tests/Pest.php beforeEach`, and `Window::fake()` + `Http::fake()` run globally — new tests inherit these. Do **not** add a real Passport/Discord round-trip to the suite.
- **`AppAccount` / `ResolveLocalIdentity` are out of scope** — owned by [`../client-agent/plan.md`](../client-agent/plan.md) Task 8. This plan links a resolved OAuth session to the local identity by reading, never writing, that table.

---

## File Structure

**New (client auth — PHP):**
- `app/Actions/Auth/GeneratePkcePair.php` — `{verifier, challenge, state}` generator (S256).
- `app/Actions/Auth/BuildAuthorizeUrl.php` — assembles `{api}/oauth/authorize?...` and stashes verifier+state.
- `app/Actions/Auth/ExchangeAuthorizationCode.php` — POST `/oauth/token` (`grant_type=authorization_code` + PKCE verifier) → stores tokens.
- `app/Actions/Auth/RefreshAccessToken.php` — POST `/oauth/token` (`grant_type=refresh_token`) → replaces tokens; clears + returns false on failure.
- `app/Actions/Auth/HandleOAuthCallback.php` — parse `mymtgo://oauth/callback` URL → validate `state` → exchange → open main window + close auth window.
- `app/Actions/Auth/ResolveSession.php` — read stored tokens; return a session state (`authenticated` / `needs_refresh` / `unauthenticated`).
- `app/Actions/Auth/ClearSession.php` — wipe stored tokens (logout / revoked / refresh-failed).
- `app/Actions/Auth/OpenAuthWindow.php` — open the dedicated auth window at the authorize URL.
- `app/Actions/Auth/CloseAuthWindowOpenMain.php` — close `auth`, open `main` (+ overlays per settings).
- `app/Data/OAuthTokens.php` — typed DTO for the token set (`accessToken`, `refreshToken`, `expiresAt`).
- `app/Listeners/Auth/HandleAuthCallback.php` — listens for `OpenedFromURL`, routes `mymtgo://oauth/callback` to `HandleOAuthCallback`.
- `app/Http/Controllers/Auth/CallbackController.php` — thin controller only if the auth window loads a local landing route (see Task 8 note); otherwise omit.

**New (client auth — config / env):**
- `config/mymtgo_api.php` (modify) — add `oauth_client_id`.
- `.env.example` (modify) — add `NATIVEPHP_DEEPLINK_SCHEME=mymtgo`, `MYMTGO_OAUTH_CLIENT_ID=`.
- `app/Facades/AppSettings.php` (modify) — add token accessor docblocks (`oauthAccessToken`, `oauthRefreshToken`, `oauthAccessTokenExpiresAt`, and their setters + `pkceVerifier`/`oauthState`).
- `app/Settings/AppSettings.php` (modify) — add the typed token/PKCE accessors backing those methods.

**Modified (Electron / NativePHP seam — observed-verified):**
- `app/Providers/NativeAppServiceProvider.php` — gate window opening behind `ResolveSession`; open the auth window when unauthenticated, main window only after callback.
- `app/Providers/AppServiceProvider.php` — register the `OpenedFromURL` → `HandleAuthCallback` listener; add the Bearer `Http::macro` + silent-refresh middleware.

**Out of scope (owned elsewhere — reference only):**
- `app/Models/AppAccount.php`, `app/Actions/Auth/ResolveLocalIdentity.php`, the `app_account` migration — [`../client-agent/plan.md`](../client-agent/plan.md) Task 8.
- Passport server, Discord handshake, `/oauth/authorize` + `/oauth/token` responses — [`../cloud-auth/spec.md`](../cloud-auth/spec.md) (the `../api` project).

---

### Task 1: PKCE pair + `state` generator

**Files:**
- Create: `app/Actions/Auth/GeneratePkcePair.php`
- Test: `tests/Unit/Actions/Auth/GeneratePkcePairTest.php`

**Interfaces:**
- Produces: `GeneratePkcePair::run(): array{verifier: string, challenge: string, state: string}` — pure, no storage. Consumed by Task 3 (`BuildAuthorizeUrl`).

- [ ] **Step 1: Write the failing test (S256 correctness + shape)**

```php
<?php // tests/Unit/Actions/Auth/GeneratePkcePairTest.php
use App\Actions\Auth\GeneratePkcePair;

it('produces an RFC 7636 S256 verifier/challenge and a state token', function () {
    $pair = app(GeneratePkcePair::class)->run();

    // verifier: 43–128 unreserved base64url chars
    expect(strlen($pair['verifier']))->toBeGreaterThanOrEqual(43)->toBeLessThanOrEqual(128);
    expect($pair['verifier'])->toMatch('/^[A-Za-z0-9\-._~]+$/');

    // challenge = base64url(sha256(verifier)), no padding
    $expected = rtrim(strtr(base64_encode(hash('sha256', $pair['verifier'], true)), '+/', '-_'), '=');
    expect($pair['challenge'])->toBe($expected);
    expect($pair['challenge'])->not->toContain('=');

    // state: non-empty, unpredictable
    expect($pair['state'])->not->toBeEmpty();
    expect(app(GeneratePkcePair::class)->run()['verifier'])->not->toBe($pair['verifier']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=GeneratePkcePairTest`
Expected: FAIL — `GeneratePkcePair` not defined.

- [ ] **Step 3: Implement**

```php
<?php // app/Actions/Auth/GeneratePkcePair.php
namespace App\Actions\Auth;

use Illuminate\Support\Str;

final class GeneratePkcePair
{
    /** @return array{verifier: string, challenge: string, state: string} */
    public function run(): array
    {
        // 64 random bytes → 86 base64url chars (within the 43–128 range).
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [
            'verifier' => $verifier,
            'challenge' => $challenge,
            'state' => Str::random(40),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=GeneratePkcePairTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Auth/GeneratePkcePair.php tests/Unit/Actions/Auth/GeneratePkcePairTest.php
git commit -m "feat(client): PKCE verifier/challenge + state generator (S256)"
```

---

### Task 2: Token storage — `OAuthTokens` DTO + encrypted `AppSettings` accessors

**Files:**
- Create: `app/Data/OAuthTokens.php`
- Modify: `app/Settings/AppSettings.php` (add typed token + PKCE accessors), `app/Facades/AppSettings.php` (add docblocks)
- Test: `tests/Feature/Auth/TokenStorageTest.php`

**Interfaces:**
- Produces: `AppSettings::setOauthTokens(OAuthTokens $t)` / `AppSettings::oauthTokens(): ?OAuthTokens`, plus transient `pkceVerifier`/`oauthState` accessors. Backing store is the existing encrypted `settings.json`. Consumed by Tasks 4, 5, 6, 7.

- [ ] **Step 1: Write the failing test (round-trip, encrypted at rest)**

```php
<?php // tests/Feature/Auth/TokenStorageTest.php
use App\Data\OAuthTokens;
use App\Facades\AppSettings;

it('round-trips an OAuthTokens set through AppSettings', function () {
    $tokens = new OAuthTokens(
        accessToken: 'acc-123',
        refreshToken: 'ref-456',
        expiresAt: now()->addHour()->toIso8601String(),
    );

    AppSettings::setOauthTokens($tokens);
    $out = AppSettings::oauthTokens();

    expect($out)->toBeInstanceOf(OAuthTokens::class);
    expect($out->accessToken)->toBe('acc-123');
    expect($out->refreshToken)->toBe('ref-456');
});

it('returns null when no tokens are stored', function () {
    expect(AppSettings::oauthTokens())->toBeNull();
});

it('stashes and reads the transient PKCE verifier + state', function () {
    AppSettings::setPkceVerifier('the-verifier');
    AppSettings::setOauthState('the-state');
    expect(AppSettings::pkceVerifier())->toBe('the-verifier');
    expect(AppSettings::oauthState())->toBe('the-state');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=TokenStorageTest`
Expected: FAIL — `OAuthTokens` / accessors not defined.

- [ ] **Step 3: Implement the DTO + accessors**

```php
<?php // app/Data/OAuthTokens.php
namespace App\Data;

final class OAuthTokens
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public string $expiresAt,
    ) {}

    /** @param array{access_token: string, refresh_token: string, expires_in: int} $response */
    public static function fromTokenResponse(array $response): self
    {
        return new self(
            accessToken: $response['access_token'],
            refreshToken: $response['refresh_token'],
            expiresAt: now()->addSeconds((int) $response['expires_in'])->toIso8601String(),
        );
    }
}
```

Add to `app/Settings/AppSettings.php` (values flow through the existing encrypted `get`/`set` primitives — do not add a second store):

```php
public function oauthTokens(): ?\App\Data\OAuthTokens
{
    $access = $this->get('oauth_access_token');
    $refresh = $this->get('oauth_refresh_token');
    $expiresAt = $this->get('oauth_access_token_expires_at');

    if (! $access || ! $refresh || ! $expiresAt) {
        return null;
    }

    return new \App\Data\OAuthTokens($access, $refresh, $expiresAt);
}

public function setOauthTokens(\App\Data\OAuthTokens $tokens): void
{
    $this->set('oauth_access_token', $tokens->accessToken);
    $this->set('oauth_refresh_token', $tokens->refreshToken);
    $this->set('oauth_access_token_expires_at', $tokens->expiresAt);
}

public function clearOauthTokens(): void
{
    $this->forget('oauth_access_token');
    $this->forget('oauth_refresh_token');
    $this->forget('oauth_access_token_expires_at');
}

public function pkceVerifier(): ?string { return $this->get('pkce_verifier'); }
public function setPkceVerifier(?string $v): void { $this->set('pkce_verifier', $v); }
public function oauthState(): ?string { return $this->get('oauth_state'); }
public function setOauthState(?string $v): void { $this->set('oauth_state', $v); }
```

Add matching `@method static` docblocks to `app/Facades/AppSettings.php` for: `oauthTokens`, `setOauthTokens`, `clearOauthTokens`, `pkceVerifier`, `setPkceVerifier`, `oauthState`, `setOauthState`.

> **Note:** the concrete `App\Settings\AppSettings` persists to encrypted `settings.json` via `Crypt`; in tests the in-memory subclass from `tests/Pest.php` overrides `get`/`set`, so no plaintext hits disk in CI. On-disk encryption is a production property of the existing store — assert the round-trip here, not the ciphertext.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=TokenStorageTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/OAuthTokens.php app/Settings/AppSettings.php app/Facades/AppSettings.php tests/Feature/Auth/TokenStorageTest.php
git commit -m "feat(client): OAuthTokens DTO + encrypted token/PKCE storage accessors"
```

---

### Task 3: Build the authorize URL (stash verifier + state)

**Files:**
- Modify: `config/mymtgo_api.php` (add `oauth_client_id`), `.env.example`
- Create: `app/Actions/Auth/BuildAuthorizeUrl.php`
- Test: `tests/Feature/Auth/BuildAuthorizeUrlTest.php`

**Interfaces:**
- Consumes: `GeneratePkcePair` (Task 1), `AppSettings` (Task 2).
- Produces: `BuildAuthorizeUrl::run(): string` — the fully-formed `{api}/oauth/authorize?...` URL; **side effect:** stashes the verifier + state in `AppSettings` for the later exchange. Consumed by Task 5 (`OpenAuthWindow`).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Auth/BuildAuthorizeUrlTest.php
use App\Actions\Auth\BuildAuthorizeUrl;
use App\Facades\AppSettings;

it('builds an authorize URL with PKCE params and stashes the verifier + state', function () {
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');

    $url = app(BuildAuthorizeUrl::class)->run();

    expect($url)->toStartWith('https://mymtgo.test/oauth/authorize?');

    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    expect($q['client_id'])->toBe('client-abc');
    expect($q['response_type'])->toBe('code');
    expect($q['redirect_uri'])->toBe('mymtgo://oauth/callback');
    expect($q['code_challenge_method'])->toBe('S256');
    expect($q['code_challenge'])->not->toBeEmpty();
    expect($q['state'])->toBe(AppSettings::oauthState());

    // the verifier matching this challenge was stashed for the exchange
    $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', AppSettings::pkceVerifier(), true)), '+/', '-_'), '=');
    expect($q['code_challenge'])->toBe($expectedChallenge);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=BuildAuthorizeUrlTest`
Expected: FAIL — `BuildAuthorizeUrl` not defined / `oauth_client_id` config missing.

- [ ] **Step 3: Implement + wire config**

Add to `config/mymtgo_api.php`:

```php
'oauth_client_id' => env('MYMTGO_OAUTH_CLIENT_ID'),
```

Add to `.env.example`:

```
NATIVEPHP_DEEPLINK_SCHEME=mymtgo
MYMTGO_OAUTH_CLIENT_ID=
```

```php
<?php // app/Actions/Auth/BuildAuthorizeUrl.php
namespace App\Actions\Auth;

use App\Facades\AppSettings;

final class BuildAuthorizeUrl
{
    public const REDIRECT_URI = 'mymtgo://oauth/callback';

    public function __construct(private GeneratePkcePair $pkce) {}

    public function run(): string
    {
        $pair = $this->pkce->run();

        AppSettings::setPkceVerifier($pair['verifier']);
        AppSettings::setOauthState($pair['state']);

        $query = http_build_query([
            'client_id' => config('mymtgo_api.oauth_client_id'),
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => '',
            'state' => $pair['state'],
            'code_challenge' => $pair['challenge'],
            'code_challenge_method' => 'S256',
        ]);

        return rtrim(config('mymtgo_api.url'), '/').'/oauth/authorize?'.$query;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=BuildAuthorizeUrlTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/mymtgo_api.php .env.example app/Actions/Auth/BuildAuthorizeUrl.php tests/Feature/Auth/BuildAuthorizeUrlTest.php
git commit -m "feat(client): build /oauth/authorize PKCE URL + stash verifier/state"
```

---

### Task 4: Exchange authorization code for tokens

**Files:**
- Create: `app/Actions/Auth/ExchangeAuthorizationCode.php`
- Test: `tests/Feature/Auth/ExchangeAuthorizationCodeTest.php`

**Interfaces:**
- Consumes: the stashed `pkceVerifier` (Task 3), `config('mymtgo_api.url')`.
- Produces: `ExchangeAuthorizationCode::run(string $code): OAuthTokens` — POST `/oauth/token` with `grant_type=authorization_code` + verifier + `client_id` (no secret); on success stores tokens (Task 2) and clears the transient verifier/state; on failure throws `AuthExchangeException`. Consumed by Task 8 (`HandleOAuthCallback`).

- [ ] **Step 1: Write the failing test (`Http::fake` the token endpoint)**

```php
<?php // tests/Feature/Auth/ExchangeAuthorizationCodeTest.php
use App\Actions\Auth\ExchangeAuthorizationCode;
use App\Exceptions\AuthExchangeException;
use App\Facades\AppSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');
    AppSettings::setPkceVerifier('stashed-verifier');
});

it('exchanges a code for tokens and stores them (no client secret sent)', function () {
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response([
        'access_token' => 'acc-1', 'refresh_token' => 'ref-1', 'expires_in' => 3600,
    ], 200)]);

    $tokens = app(ExchangeAuthorizationCode::class)->run('the-code');

    expect($tokens->accessToken)->toBe('acc-1');
    expect(AppSettings::oauthTokens()->refreshToken)->toBe('ref-1');
    expect(AppSettings::pkceVerifier())->toBeNull(); // one-time verifier cleared

    Http::assertSent(function ($request) {
        $body = $request->data();
        return $request->url() === 'https://mymtgo.test/oauth/token'
            && $body['grant_type'] === 'authorization_code'
            && $body['code'] === 'the-code'
            && $body['code_verifier'] === 'stashed-verifier'
            && $body['client_id'] === 'client-abc'
            && ! array_key_exists('client_secret', $body);
    });
});

it('throws AuthExchangeException on a non-2xx token response', function () {
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    app(ExchangeAuthorizationCode::class)->run('bad-code');
})->throws(AuthExchangeException::class);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ExchangeAuthorizationCodeTest`
Expected: FAIL — class/exception not defined.

- [ ] **Step 3: Implement (create `app/Exceptions/AuthExchangeException.php` too)**

```php
<?php // app/Exceptions/AuthExchangeException.php
namespace App\Exceptions;

class AuthExchangeException extends \RuntimeException {}
```

```php
<?php // app/Actions/Auth/ExchangeAuthorizationCode.php
namespace App\Actions\Auth;

use App\Data\OAuthTokens;
use App\Exceptions\AuthExchangeException;
use App\Facades\AppSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ExchangeAuthorizationCode
{
    public function run(string $code): OAuthTokens
    {
        $verifier = AppSettings::pkceVerifier();

        if (! $verifier) {
            throw new AuthExchangeException('Missing PKCE verifier for token exchange.');
        }

        $response = Http::asForm()->post(rtrim(config('mymtgo_api.url'), '/').'/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => config('mymtgo_api.oauth_client_id'),
            'redirect_uri' => BuildAuthorizeUrl::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $verifier,
        ]);

        if (! $response->successful()) {
            Log::error('OAuth code exchange failed', ['status' => $response->status()]);
            throw new AuthExchangeException('Token exchange returned '.$response->status());
        }

        $tokens = OAuthTokens::fromTokenResponse($response->json());
        AppSettings::setOauthTokens($tokens);
        AppSettings::setPkceVerifier(null);
        AppSettings::setOauthState(null);

        return $tokens;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ExchangeAuthorizationCodeTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Auth/ExchangeAuthorizationCode.php app/Exceptions/AuthExchangeException.php tests/Feature/Auth/ExchangeAuthorizationCodeTest.php
git commit -m "feat(client): exchange auth code + PKCE verifier for tokens (no secret)"
```

---

### Task 5: Silent refresh

**Files:**
- Create: `app/Actions/Auth/RefreshAccessToken.php`, `app/Actions/Auth/ClearSession.php`
- Test: `tests/Feature/Auth/RefreshAccessTokenTest.php`

**Interfaces:**
- Consumes: the stored `refresh_token` (Task 2).
- Produces: `RefreshAccessToken::run(): bool` — POST `/oauth/token` (`grant_type=refresh_token`); **true** + replaces stored tokens on success; **false** + `ClearSession` on failure (revoked/expired refresh token → forces re-auth). `ClearSession::run(): void` wipes stored tokens. Consumed by Tasks 6 (`ResolveSession`) + 7 (Bearer macro retry).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Auth/RefreshAccessTokenTest.php
use App\Actions\Auth\RefreshAccessToken;
use App\Data\OAuthTokens;
use App\Facades\AppSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');
    AppSettings::setOauthTokens(new OAuthTokens('old-acc', 'ref-1', now()->subMinute()->toIso8601String()));
});

it('refreshes and replaces the stored tokens on success', function () {
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response([
        'access_token' => 'new-acc', 'refresh_token' => 'ref-2', 'expires_in' => 3600,
    ], 200)]);

    expect(app(RefreshAccessToken::class)->run())->toBeTrue();
    expect(AppSettings::oauthTokens()->accessToken)->toBe('new-acc');
    expect(AppSettings::oauthTokens()->refreshToken)->toBe('ref-2');

    Http::assertSent(fn ($r) => $r->data()['grant_type'] === 'refresh_token'
        && $r->data()['refresh_token'] === 'ref-1');
});

it('clears the session and returns false when the refresh token is rejected', function () {
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response(['error' => 'invalid_grant'], 401)]);

    expect(app(RefreshAccessToken::class)->run())->toBeFalse();
    expect(AppSettings::oauthTokens())->toBeNull();
});

it('returns false with no round trip when no refresh token is stored', function () {
    AppSettings::clearOauthTokens();
    Http::fake();

    expect(app(RefreshAccessToken::class)->run())->toBeFalse();
    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=RefreshAccessTokenTest`
Expected: FAIL — classes not defined.

- [ ] **Step 3: Implement**

```php
<?php // app/Actions/Auth/ClearSession.php
namespace App\Actions\Auth;

use App\Facades\AppSettings;

final class ClearSession
{
    public function run(): void
    {
        AppSettings::clearOauthTokens();
        AppSettings::setPkceVerifier(null);
        AppSettings::setOauthState(null);
    }
}
```

```php
<?php // app/Actions/Auth/RefreshAccessToken.php
namespace App\Actions\Auth;

use App\Data\OAuthTokens;
use App\Facades\AppSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class RefreshAccessToken
{
    public function __construct(private ClearSession $clearSession) {}

    public function run(): bool
    {
        $tokens = AppSettings::oauthTokens();

        if ($tokens === null) {
            return false;
        }

        $response = Http::asForm()->post(rtrim(config('mymtgo_api.url'), '/').'/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => config('mymtgo_api.oauth_client_id'),
            'refresh_token' => $tokens->refreshToken,
            'scope' => '',
        ]);

        if (! $response->successful()) {
            Log::warning('OAuth refresh failed — clearing session', ['status' => $response->status()]);
            $this->clearSession->run();

            return false;
        }

        AppSettings::setOauthTokens(OAuthTokens::fromTokenResponse($response->json()));

        return true;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=RefreshAccessTokenTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Auth/RefreshAccessToken.php app/Actions/Auth/ClearSession.php tests/Feature/Auth/RefreshAccessTokenTest.php
git commit -m "feat(client): silent refresh (grant_type=refresh_token) + clear-on-reject"
```

---

### Task 6: Resolve session state (boot gate)

**Files:**
- Create: `app/Actions/Auth/ResolveSession.php`, `app/Enums/SessionState.php`
- Test: `tests/Feature/Auth/ResolveSessionTest.php`

**Interfaces:**
- Consumes: stored tokens (Task 2), `RefreshAccessToken` (Task 5).
- Produces: `ResolveSession::run(): SessionState` — `Unauthenticated` (no tokens), `Authenticated` (valid + not near-expiry), or refreshes near-expiry/expired tokens in place → `Authenticated`/`Unauthenticated` based on the refresh result. Consumed by Task 9 (boot window gate).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Auth/ResolveSessionTest.php
use App\Actions\Auth\ResolveSession;
use App\Data\OAuthTokens;
use App\Enums\SessionState;
use App\Facades\AppSettings;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config()->set('mymtgo_api.url', 'https://mymtgo.test'));

it('is Unauthenticated with no stored tokens', function () {
    expect(app(ResolveSession::class)->run())->toBe(SessionState::Unauthenticated);
});

it('is Authenticated when the access token is comfortably valid', function () {
    AppSettings::setOauthTokens(new OAuthTokens('acc', 'ref', now()->addHour()->toIso8601String()));
    expect(app(ResolveSession::class)->run())->toBe(SessionState::Authenticated);
});

it('silently refreshes a near-expiry token and stays Authenticated', function () {
    AppSettings::setOauthTokens(new OAuthTokens('acc', 'ref', now()->addSeconds(10)->toIso8601String()));
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response([
        'access_token' => 'fresh', 'refresh_token' => 'ref2', 'expires_in' => 3600,
    ], 200)]);

    expect(app(ResolveSession::class)->run())->toBe(SessionState::Authenticated);
    expect(AppSettings::oauthTokens()->accessToken)->toBe('fresh');
});

it('is Unauthenticated when a near-expiry token fails to refresh', function () {
    AppSettings::setOauthTokens(new OAuthTokens('acc', 'ref', now()->subMinute()->toIso8601String()));
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response([], 401)]);

    expect(app(ResolveSession::class)->run())->toBe(SessionState::Unauthenticated);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ResolveSessionTest`
Expected: FAIL — classes not defined.

- [ ] **Step 3: Implement**

```php
<?php // app/Enums/SessionState.php
namespace App\Enums;

enum SessionState: string
{
    case Authenticated = 'authenticated';
    case Unauthenticated = 'unauthenticated';
}
```

```php
<?php // app/Actions/Auth/ResolveSession.php
namespace App\Actions\Auth;

use App\Enums\SessionState;
use App\Facades\AppSettings;
use Illuminate\Support\Carbon;

final class ResolveSession
{
    /** Refresh proactively when the token is within this many seconds of expiry. */
    private const EXPIRY_SKEW_SECONDS = 60;

    public function __construct(private RefreshAccessToken $refresh) {}

    public function run(): SessionState
    {
        $tokens = AppSettings::oauthTokens();

        if ($tokens === null) {
            return SessionState::Unauthenticated;
        }

        $expiresSoon = Carbon::parse($tokens->expiresAt)
            ->subSeconds(self::EXPIRY_SKEW_SECONDS)
            ->isPast();

        if (! $expiresSoon) {
            return SessionState::Authenticated;
        }

        return $this->refresh->run()
            ? SessionState::Authenticated
            : SessionState::Unauthenticated;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ResolveSessionTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Auth/ResolveSession.php app/Enums/SessionState.php tests/Feature/Auth/ResolveSessionTest.php
git commit -m "feat(client): resolve session state with proactive near-expiry refresh"
```

---

### Task 7: Bearer HTTP macro + 401 silent-refresh-retry

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (add the `mymtgoAuthed` macro)
- Test: `tests/Feature/Auth/BearerMacroTest.php`

**Interfaces:**
- Consumes: stored access token (Task 2), `RefreshAccessToken` (Task 5).
- Produces: `Http::mymtgoAuthed()` — a pending request pre-set with `Authorization: Bearer {access_token}` and `baseUrl(config('mymtgo_api.url'))`; a response middleware that, on a `401`, runs `RefreshAccessToken` once and retries the request with the new token. Consumed by the push client in [`../client-agent/plan.md`](../client-agent/plan.md) Task 12 (it uses this macro instead of a raw `Http::post`).

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Auth/BearerMacroTest.php
use App\Data\OAuthTokens;
use App\Facades\AppSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');
    AppSettings::setOauthTokens(new OAuthTokens('acc-1', 'ref-1', now()->addHour()->toIso8601String()));
});

it('attaches the Bearer access token', function () {
    Http::fake(['https://mymtgo.test/*' => Http::response([], 200)]);

    Http::mymtgoAuthed()->post('/api/matches', ['x' => 1]);

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer acc-1'));
});

it('refreshes once and retries on a 401', function () {
    Http::fakeSequence('https://mymtgo.test/api/matches')
        ->push([], 401)
        ->push([], 200);
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response([
        'access_token' => 'acc-2', 'refresh_token' => 'ref-2', 'expires_in' => 3600,
    ], 200)]);

    $response = Http::mymtgoAuthed()->post('/api/matches', ['x' => 1]);

    expect($response->status())->toBe(200);
    expect(AppSettings::oauthTokens()->accessToken)->toBe('acc-2');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=BearerMacroTest`
Expected: FAIL — `mymtgoAuthed` macro not defined.

- [ ] **Step 3: Implement the macro in `AppServiceProvider::boot()`**

Add alongside the existing `Http::macro('mymtgoApi', ...)`:

```php
Http::macro('mymtgoAuthed', function () {
    $token = AppSettings::oauthTokens()?->accessToken;

    return Http::baseUrl(config('mymtgo_api.url'))
        ->withToken($token)
        ->withResponseMiddleware(function (\Psr\Http\Message\ResponseInterface $response, $options) {
            if ($response->getStatusCode() !== 401) {
                return $response;
            }

            if (! app(\App\Actions\Auth\RefreshAccessToken::class)->run()) {
                return $response;
            }

            // Retry once with the refreshed token.
            $fresh = AppSettings::oauthTokens()?->accessToken;

            return Http::baseUrl(config('mymtgo_api.url'))
                ->withToken($fresh)
                ->send($options['request_method'] ?? 'GET', (string) ($options['uri'] ?? ''), $options)
                ->toPsrResponse();
        });
});
```

> **Note:** Guzzle's `withResponseMiddleware` retry ergonomics vary by Http-client version. If a clean single-retry cannot be expressed as middleware, implement the macro to return a small wrapper action (`AuthedRequest`) whose `post()/get()` call the request, and on a `401` run `RefreshAccessToken` + re-issue once — the **test in Step 1 is the contract**; keep the public surface `Http::mymtgoAuthed()->post(...)` and the "refresh once, retry once" behaviour regardless of the internal mechanism.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=BearerMacroTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Providers/AppServiceProvider.php tests/Feature/Auth/BearerMacroTest.php
git commit -m "feat(client): Http::mymtgoAuthed Bearer macro with 401 refresh-and-retry"
```

---

### Task 8: Handle the `mymtgo://oauth/callback` deep link

**Files:**
- Create: `app/Actions/Auth/HandleOAuthCallback.php`
- Create: `app/Listeners/Auth/HandleAuthCallback.php`
- Modify: `app/Providers/AppServiceProvider.php` (register the `OpenedFromURL` → listener)
- Test: `tests/Feature/Auth/HandleOAuthCallbackTest.php`

**Interfaces:**
- Consumes: the callback URL from `Native\Desktop\Events\App\OpenedFromURL`, the stashed `state` (Task 3), `ExchangeAuthorizationCode` (Task 4), `CloseAuthWindowOpenMain` (Task 9).
- Produces: `HandleOAuthCallback::run(string $url): bool` — parse the URL; ignore any URL that is not `mymtgo://oauth/callback`; assert `state` equals the stash (abort on mismatch); exchange the `code`; on success trigger the window swap and return true. The listener adapts the NativePHP event to this action.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Auth/HandleOAuthCallbackTest.php
use App\Actions\Auth\HandleOAuthCallback;
use App\Facades\AppSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');
    AppSettings::setPkceVerifier('verifier-1');
    AppSettings::setOauthState('state-1');
});

it('ignores deep links that are not the oauth callback', function () {
    expect(app(HandleOAuthCallback::class)->run('mymtgo://decks/open/5'))->toBeFalse();
    expect(AppSettings::pkceVerifier())->toBe('verifier-1'); // untouched
});

it('rejects a callback whose state does not match the stash', function () {
    Http::fake();
    $ok = app(HandleOAuthCallback::class)->run('mymtgo://oauth/callback?code=c&state=WRONG');
    expect($ok)->toBeFalse();
    Http::assertNothingSent(); // never exchanged the code
});

it('exchanges the code and stores tokens on a matching-state callback', function () {
    Http::fake(['https://mymtgo.test/oauth/token' => Http::response([
        'access_token' => 'acc', 'refresh_token' => 'ref', 'expires_in' => 3600,
    ], 200)]);

    $ok = app(HandleOAuthCallback::class)->run('mymtgo://oauth/callback?code=the-code&state=state-1');

    expect($ok)->toBeTrue();
    expect(AppSettings::oauthTokens()->accessToken)->toBe('acc');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=HandleOAuthCallbackTest`
Expected: FAIL — classes not defined.

- [ ] **Step 3: Implement the action + listener + registration**

```php
<?php // app/Actions/Auth/HandleOAuthCallback.php
namespace App\Actions\Auth;

use App\Exceptions\AuthExchangeException;
use App\Facades\AppSettings;
use Illuminate\Support\Facades\Log;

final class HandleOAuthCallback
{
    public function __construct(
        private ExchangeAuthorizationCode $exchange,
        private CloseAuthWindowOpenMain $swapWindows,
    ) {}

    public function run(string $url): bool
    {
        $parts = parse_url($url);

        // Only handle mymtgo://oauth/callback — ignore any other deep link.
        if (($parts['scheme'] ?? null) !== 'mymtgo'
            || trim(($parts['host'] ?? '').($parts['path'] ?? ''), '/') !== 'oauth/callback') {
            return false;
        }

        parse_str($parts['query'] ?? '', $query);
        $code = $query['code'] ?? null;
        $state = $query['state'] ?? null;

        if (! $code || ! $state || ! hash_equals((string) AppSettings::oauthState(), (string) $state)) {
            Log::warning('OAuth callback rejected: missing code or state mismatch.');

            return false;
        }

        try {
            $this->exchange->run($code);
        } catch (AuthExchangeException $e) {
            Log::error('OAuth callback exchange failed', ['message' => $e->getMessage()]);

            return false;
        }

        $this->swapWindows->run();

        return true;
    }
}
```

```php
<?php // app/Listeners/Auth/HandleAuthCallback.php
namespace App\Listeners\Auth;

use App\Actions\Auth\HandleOAuthCallback;
use Native\Desktop\Events\App\OpenedFromURL;

class HandleAuthCallback
{
    public function __construct(private HandleOAuthCallback $handler) {}

    public function handle(OpenedFromURL $event): void
    {
        $this->handler->run($event->url);
    }
}
```

Register in `AppServiceProvider::boot()` next to the existing `Event::listen(MenuBarClicked::class, ...)`:

```php
Event::listen(
    \Native\Desktop\Events\App\OpenedFromURL::class,
    \App\Listeners\Auth\HandleAuthCallback::class,
);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=HandleOAuthCallbackTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Auth/HandleOAuthCallback.php app/Listeners/Auth/HandleAuthCallback.php app/Providers/AppServiceProvider.php tests/Feature/Auth/HandleOAuthCallbackTest.php
git commit -m "feat(client): handle mymtgo://oauth/callback deep link (state check + exchange)"
```

> **Manual/observed verification (Windows):** `Task 3` sets `NATIVEPHP_DEEPLINK_SCHEME=mymtgo`, so NativePHP registers the protocol and emits `OpenedFromURL` for real `mymtgo://` links (via `open-url` on macOS, `second-instance` on Windows/Linux — see `vendor/nativephp/.../electron-plugin/src/index.ts`). Confirm the round trip in-app: click Discord/login in the auth window → API redirects to `mymtgo://oauth/callback?code=...` → the OS reactivates the app → the listener fires → the main window opens. This full path is exercised in-app, not by the PHP test suite.

---

### Task 9: Dedicated auth window + window swap (Electron seam — observed-verified)

**Files:**
- Create: `app/Actions/Auth/OpenAuthWindow.php`, `app/Actions/Auth/CloseAuthWindowOpenMain.php`
- Modify: `app/Providers/NativeAppServiceProvider.php` (gate boot on `ResolveSession`)
- Test: `tests/Feature/Auth/AuthWindowTest.php`

**Interfaces:**
- Consumes: `BuildAuthorizeUrl` (Task 3), `ResolveSession` (Task 6).
- Produces: `OpenAuthWindow::run(): void` — opens the `auth` window (id `auth`) pointed at the API's authorize URL. `CloseAuthWindowOpenMain::run(): void` — closes `auth`, opens `main` (+ overlays per `AppSettings`). Boot opens the auth window when `ResolveSession` is `Unauthenticated`, main + overlays when `Authenticated`.

- [ ] **Step 1: Write the failing test (window facade fake asserts intent)**

```php
<?php // tests/Feature/Auth/AuthWindowTest.php
use App\Actions\Auth\OpenAuthWindow;
use App\Facades\AppSettings;

it('opens an auth window pointed at the authorize URL and stashes the verifier', function () {
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');

    app(OpenAuthWindow::class)->run();

    // side effect of BuildAuthorizeUrl: a verifier + state are now stashed
    expect(AppSettings::pkceVerifier())->not->toBeNull();
    expect(AppSettings::oauthState())->not->toBeNull();
})->group('window');
```

> Window opening is faked globally (`Window::fake()` in `tests/Pest.php`). The PHP-assertable contract is the **side effect** (verifier/state stashed via `BuildAuthorizeUrl`) and that `run()` does not throw. The actual on-screen window is observed-verified below.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AuthWindowTest`
Expected: FAIL — `OpenAuthWindow` not defined.

- [ ] **Step 3: Implement the two window actions**

```php
<?php // app/Actions/Auth/OpenAuthWindow.php
namespace App\Actions\Auth;

use Native\Desktop\Facades\Window;

final class OpenAuthWindow
{
    public function __construct(private BuildAuthorizeUrl $buildUrl) {}

    public function run(): void
    {
        $alreadyOpen = collect(Window::all())->contains(fn ($w) => $w->getId() === 'auth');

        if ($alreadyOpen) {
            return;
        }

        Window::open('auth')
            ->url($this->buildUrl->run())
            ->width(480)
            ->height(720)
            ->minWidth(400)
            ->minHeight(600)
            ->movable()
            ->resizable(false)
            ->maximizable(false)
            ->title('Sign in to mymtgo');
    }
}
```

```php
<?php // app/Actions/Auth/CloseAuthWindowOpenMain.php
namespace App\Actions\Auth;

use App\Actions\Decks\OpenDeckPopoutWindow;
use App\Actions\Leagues\OpenOpponentScoutWindow;
use App\Actions\Leagues\OpenOverlayWindow;
use App\Facades\AppSettings;
use Native\Desktop\Facades\Window;

final class CloseAuthWindowOpenMain
{
    public function run(): void
    {
        $mainOpen = collect(Window::all())->contains(fn ($w) => $w->getId() === 'main');

        if (! $mainOpen) {
            Window::open()
                ->width(1600)->height(900)
                ->minHeight(800)->minWidth(1200)
                ->movable()->hideOnClose()->title('mymtgo');
        }

        if (AppSettings::showLeagueWindow()) {
            OpenOverlayWindow::run();
        }
        if (AppSettings::showOpponentWindow()) {
            OpenOpponentScoutWindow::run();
        }
        if (AppSettings::showDeckWindow()) {
            OpenDeckPopoutWindow::run();
        }

        Window::close('auth');
    }
}
```

- [ ] **Step 4: Gate `NativeAppServiceProvider::boot()`**

Replace the unconditional `Window::open()->title('mymtgo')` block (and the three overlay `if (...)` opens) with a session gate. Keep tray/updates/timezone setup before the gate:

```php
use App\Actions\Auth\OpenAuthWindow;
use App\Actions\Auth\CloseAuthWindowOpenMain;
use App\Actions\Auth\ResolveSession;
use App\Enums\SessionState;
// ...
if (app(ResolveSession::class)->run() === SessionState::Authenticated) {
    app(CloseAuthWindowOpenMain::class)->run(); // opens main + overlays; no 'auth' window to close
} else {
    app(OpenAuthWindow::class)->run();          // main window never shown to an unauth user
}

Mtgo::runInitialSetup();
Mtgo::retryUnsubmittedMatches();
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=AuthWindowTest`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Auth/OpenAuthWindow.php app/Actions/Auth/CloseAuthWindowOpenMain.php app/Providers/NativeAppServiceProvider.php tests/Feature/Auth/AuthWindowTest.php
git commit -m "feat(client): dedicated auth window + session-gated boot (main hidden until auth)"
```

> **Manual/observed verification (Windows):** launch the packaged app signed out → **only** the auth window appears (no main window). Complete Discord + email/password logins → after the `mymtgo://` callback the auth window closes and the main window (+ enabled overlays) opens. Relaunch → `ResolveSession` returns `Authenticated` from stored tokens → straight to the main window, no auth window. This window lifecycle is verified in-app, not by the PHP suite.

---

### Task 10: Logout + revoked-device re-auth

**Files:**
- Create: `app/Actions/Auth/Logout.php`
- Test: `tests/Feature/Auth/LogoutTest.php`

**Interfaces:**
- Consumes: `ClearSession` (Task 5), `OpenAuthWindow` (Task 9).
- Produces: `Logout::run(): void` — clear tokens, close all non-auth windows, open the auth window. Also the recovery path when the server revokes a device: `RefreshAccessToken` fails (Task 5 already clears tokens), and the next `ResolveSession` at boot — or a user-initiated logout — routes back to the auth window.

- [ ] **Step 1: Write the failing test**

```php
<?php // tests/Feature/Auth/LogoutTest.php
use App\Actions\Auth\Logout;
use App\Data\OAuthTokens;
use App\Facades\AppSettings;

it('clears stored tokens on logout', function () {
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');
    AppSettings::setOauthTokens(new OAuthTokens('acc', 'ref', now()->addHour()->toIso8601String()));

    app(Logout::class)->run();

    expect(AppSettings::oauthTokens())->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=LogoutTest`
Expected: FAIL — `Logout` not defined.

- [ ] **Step 3: Implement**

```php
<?php // app/Actions/Auth/Logout.php
namespace App\Actions\Auth;

use Native\Desktop\Facades\Window;

final class Logout
{
    public function __construct(
        private ClearSession $clearSession,
        private OpenAuthWindow $openAuthWindow,
    ) {}

    public function run(): void
    {
        $this->clearSession->run();

        collect(Window::all())
            ->filter(fn ($w) => $w->getId() !== 'auth')
            ->each(fn ($w) => Window::close($w->getId()));

        $this->openAuthWindow->run();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=LogoutTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Auth/Logout.php tests/Feature/Auth/LogoutTest.php
git commit -m "feat(client): logout + revoked-device recovery back to auth window"
```

> **Manual/observed verification (Windows):** with the app authenticated, revoke the device server-side, then trigger any authed request → the `401` refresh fails → tokens clear → on next boot (or on user logout) the auth window reappears. Verified in-app.

---

## Self-Review checklist (run after Tasks 1–10)

1. **Spec coverage** — every bullet in [`spec.md`](./spec.md) maps to a task: dedicated auth window = Task 9; OAuth2 Auth-Code + PKCE flow = Tasks 1,3,4 (`/oauth/token` exchange with verifier, no secret); `mymtgo://oauth/callback` deep-link = Task 8; per-device refreshable/revocable tokens + silent refresh = Tasks 2,5,6,7; revoke → re-auth = Task 10. Discord vs email/password is **server-rendered** on `/oauth/authorize` (cloud-auth) — the client only opens the window and handles the callback.
2. **Boundary with client-agent** — this plan does **not** create `app_account`, `AppAccount`, or `ResolveLocalIdentity` (client-agent Task 8). The push client's Bearer auth uses `Http::mymtgoAuthed()` from Task 7. MTGO username-mismatch guard is client-agent's, not here.
3. **No secret leakage** — `client_secret` is never sent (asserted in Tasks 4 + the refresh in 5). Only the public `oauth_client_id` ships.
4. **`state` CSRF** — generated in Task 1, stashed in Task 3, `hash_equals`-checked in Task 8; mismatch aborts before any code exchange (asserted).
5. **Tokens never logged** — every `Log::` call in Tasks 4/5/8 logs status/message only, never token values.
6. **Placeholder scan** — no "TBD" / "handle edge cases" / "similar to Task N". The one implementation-variance note (Task 7 Guzzle middleware) pins the test as the contract and states the required behaviour explicitly.
7. **Electron seam labelling** — Tasks 8, 9, 10 each carry an explicit "Manual/observed verification (Windows)" note (mirrors client-agent Task 2b); their PHP tests assert only the PHP-side contract.
8. **Conventions** — invokable Actions (no service classes), PHP 8 promoted constructors, explicit return types, curly braces; `vendor/bin/pint --dirty --format agent` in every commit step.
