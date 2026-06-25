# Security Audit — MTGO Deck Tracker (NativePHP / Electron, Windows)

**Date:** 2026-06-11
**Scope:** Authorized defensive audit of the owner's own desktop app. Single-user, local-first, no multi-tenant server. Windows-only hardening (macOS/Linux ignored).
**Auditor model:** static review of `app/`, `routes/`, `config/`, `resources/js`, `bootstrap/`.

## Overall Posture

The application is in **good defensive shape for a single-user local desktop app**. The two attack surfaces that matter most — semi-hostile MTGO log input (opponent names, chat, deck names) and the localhost HTTP bridge — are largely handled correctly:

- **Hostile MTGO input does NOT reach a code-exec sink.** Opponent names, deck names and chat are rendered through Vue's auto-escaping interpolation (no `v-html` on any user/log-derived field), persisted via Eloquent/parameterized queries, and never passed to shell execution (no `exec`/`Process`/`proc_open` anywhere in `app/`). All `selectRaw`/`whereRaw`/`orderByRaw` usages I reviewed are static SQL or interpolate only sanitized values (`asc`/`desc`, integer-cast indices). **No SQL injection, no command injection, and no log-driven XSS were found.** This is the single most important result of the audit.
- State-changing routes run under Laravel 12's `web` middleware group, so **CSRF protection is active** (Inertia sends the XSRF token); a malicious web page cannot drive writes against the local server.
- Outbound API calls default to **HTTPS with TLS verification on** (`config/mymtgo_api.php`), the device API key is **encrypted at rest** (`Crypt::encrypt`), and production secrets (`GITHUB_*`, `AWS_*`, `AZURE_*`, `*_SECRET`) are stripped from the bundle via `cleanup_env_keys`.
- `APP_DEBUG` defaults to `false`, `APP_ENV` defaults to `production`, and the exception handler explicitly avoids leaking raw exception messages (SQL/paths) to the UI (`bootstrap/app.php:52-79`).

The findings below are mostly **defense-in-depth and supply-chain hardening**, plus one genuine renderer-side injection sink. **No Critical (other-player-triggerable code exec / secret theft) issues were identified.**

> **Note / limitation:** `.env` and `.env.example` could not be read (sandbox permission denial). Claims about packaged secrets are inferred from `config/*.php` and `config/nativephp.php` `cleanup_env_keys`. The owner should manually confirm the production build's `.env` values (see Low-4).

---

## High

### H-1. Unsanitized `v-html` of remotely-sourced release notes (renderer XSS sink)

- **Location:** `resources/js/components/UpdateBanner.vue:94` (`v-html="update.releaseNotes"`); data path `app/Listeners/StoreAvailableUpdate.php:15` ← `Native\Desktop\Events\AutoUpdater\UpdateDownloaded` (GitHub release notes).
- **Issue:** The auto-update banner renders the GitHub release "notes" field as **raw HTML** with no sanitization. The string originates from the update feed (GitHub releases for `mymtgo/client`), not from the app build. There is also **no Content-Security-Policy** in the renderer (see M-2), so an injected `<script>`/`<img onerror>` executes with full access to the renderer context.
- **Attack scenario:** If the GitHub release publishing path is ever compromised (account/token compromise, or a release authored with embedded HTML), every client that polls the update feed renders attacker HTML. Because the renderer has direct access to the local Inertia app and the localhost API, the payload can exfiltrate the entire local match database — **including third-party opponent usernames and notes** — to a remote server, or drive same-origin requests against the local server. (Note: this is *not* other-player-triggerable via MTGO logs, hence High rather than Critical; it requires control of the update feed/release.)
- **Action points:**
  1. Stop rendering release notes as raw HTML. Render as plain text, or pass through a strict allow-list HTML sanitizer (or a Markdown renderer with HTML disabled) before binding.
  2. Add a renderer CSP (M-2) as a backstop so any future HTML sink cannot load/exec remote script.
  3. Confirm the NativePHP/electron-updater path enforces HTTPS + signature verification for the *binary* (it does for code, but release-note *metadata* is the gap here).
- **Fix guidance (approach only):** Treat all update-feed metadata as untrusted display data; sanitize at the boundary, render as text/escaped Markdown, never `v-html`.

---

## Medium

### M-1. No Content-Security-Policy on the Electron renderer

- **Location:** `resources/views/app.blade.php` (no `Content-Security-Policy` meta tag / header anywhere in `resources/` or middleware).
- **Issue:** The renderer ships with no CSP. The page also pulls a remote stylesheet/fonts (`fonts.bunny.net`, `app.blade.php:19-20`). Without CSP there is no backstop against XSS (see H-1): injected markup can load remote scripts and exfiltrate data freely.
- **Attack scenario:** Any HTML/JS injection sink (today: release notes; tomorrow: any new `v-html`) escalates to full data exfiltration and local-API abuse because nothing restricts script sources or connect targets.
- **Action points:** Add a strict CSP (`default-src 'self'`; explicit `connect-src` for the mymtgo API + Sentry + Scryfall image hosts; `style-src`/`font-src` for the font host or self-host the fonts to drop the remote dependency; no `unsafe-inline`/`unsafe-eval` for scripts). Deliver via a `<meta http-equiv>` in `app.blade.php` or a response header. Validate Vite's inline-style needs and tighten iteratively.
- **Fix guidance:** Define CSP once at the document boundary; prefer self-hosting fonts so `style-src`/`font-src` can stay `'self'`.

### M-2. Local HTTP server has no Host/Origin validation (DNS-rebinding read of local data)

- **Location:** `bootstrap/app.php` middleware stack (only `encryptCookies`, `web`, `HandleAppearance`, `HandleInertiaRequests`); `routes/web.php` — all GET data routes (`/decks/*`, `/opponents`, `/matches/*`, `/reports/*`) are unauthenticated by design.
- **Issue:** The embedded PHP server (NativePHP binds `127.0.0.1`) serves the SPA with no Host-header or Origin allow-listing. State changes are protected by CSRF, but **read-only GET routes are not** and return the full local dataset (matches, opponent names, notes, decklists). A web page using DNS rebinding (rebinding a hostname to `127.0.0.1` after the user visits) could issue same-origin GETs to the local port and read this data — *if it can discover the runtime port*.
- **Attack scenario:** User browses a malicious site in a normal browser while the app runs; the site rebinds DNS to loopback, brute-forces/guesses the local port, and reads `/opponents` or `/decks/*` JSON, exfiltrating the user's match history and the usernames of players they faced.
- **Mitigations already present:** Random high port (hard to discover), CSRF blocks all writes, the app runs in its own Electron window. This keeps it Medium, not High.
- **Action points:** Add a middleware that rejects requests whose `Host` header is not the expected loopback host/port (or that carry a cross-origin `Origin`/`Sec-Fetch-Site: cross-site`). Confirm whether the NativePHP fork already binds a per-session secret/token on app routes; if so, document it and this finding drops to Low.
- **Fix guidance:** Enforce a Host/Origin allow-list at the middleware boundary for all routes, not just write routes.

### M-3. Outbound shipment of raw MTGO log text containing third-party PII

- **Location:** `app/Jobs/SubmitMatchLogSample.php:38-44` (ships `raw_text` + `username`); dispatched from `app/Actions/Matches/AdvanceMatchState.php:138-144`. Related: `app/Actions/Matches/SubmitMatchToApi.php:63-77` ships local `username` + opponent archetype; `app/Jobs/ShipCardStats.php`.
- **Issue:** `SubmitMatchLogSample` transmits the **raw MTGO match-join log text** to `mymtgo.com/api/match-log-samples`. Raw MTGO log lines can contain opponent usernames and other in-client text — i.e. **personal data about third parties** who never consented. It is sent with the local player's `username`.
- **Mitigations present:** Gated behind `AppSettings::shouldTransmitMatches()` (user opt setting), sent over HTTPS with TLS verification (default), authenticated with a per-device key.
- **Attack scenario:** Not an exploit per se — a privacy/compliance exposure. The breadth of `raw_text` means more PII than necessary leaves the machine; if the receiving API is ever breached, opponent identities are exposed.
- **Action points:** (1) Confirm the opt-in/consent UX clearly states raw log samples (including opponent names) are uploaded. (2) Minimize: scrub or hash opponent usernames in `raw_text` before upload unless they are genuinely required for parser-adaptation. (3) Apply server-side retention limits.
- **Fix guidance:** Data-minimize at the source; ship only the fields the API needs, redacting third-party identifiers.

### M-4. App-wide TLS-verification kill switch

- **Location:** `app/Providers/AppServiceProvider.php:49-53` — `if (! config('mymtgo_api.verify_ssl')) { Http::globalOptions(['verify' => false]); }`; config `config/mymtgo_api.php` (`MYMTGO_API_VERIFY_SSL`, default `true`).
- **Issue:** A single env flag disables TLS certificate verification for **all** outbound `Http` calls process-wide — not just the mymtgo API, but card image fetches and any other client request. If ever set false (debugging that leaks into a build, or a support workaround), all submissions carrying the device API key and PII become MITM-interceptable.
- **Mitigations present:** Default is `true` (verification on).
- **Action points:** Keep the default; ensure the production build never ships `MYMTGO_API_VERIFY_SSL=false`. If a relaxed-TLS path is ever needed, scope it to a single named client rather than `Http::globalOptions`.
- **Fix guidance:** Avoid global TLS opt-out; scope any exception to one request builder and never to the credential-bearing API client.

---

## Low

### L-1. Dependency on a personal-fork dev branch of the Electron layer

- **Location:** `composer.json:11-16,24` — `"nativephp/desktop": "dev-feat/always-on-top-level as 2.1.1"` from VCS repo `https://github.com/alecritson/desktop`.
- **Issue:** The component that owns the entire Electron security posture (BrowserWindow `webPreferences`, `contextIsolation`/`nodeIntegration`, the auto-updater, the localhost server) is a **personal fork's feature branch**, not a tagged upstream release. `dev-` constraints float to branch HEAD. This is a supply-chain and assurance gap: renderer security (and H-1's blast radius) depends on settings inside this fork that the app code cannot see. The app's use of a `window.Native` contextBridge API *suggests* `contextIsolation` is on / `nodeIntegration` off (the safe posture), but this is unverified here.
- **Action points:** (1) Verify the fork keeps `contextIsolation: true`, `nodeIntegration: false`, `sandbox: true` on all windows. (2) Pin to an immutable commit/tag rather than a moving branch. (3) Track upstream and merge to a released version when the feature lands.
- **Fix guidance:** Pin immutable refs for security-critical native deps; verify Electron hardening flags directly in the fork.

### L-2. `APP_KEY` ships in the build → settings.json "encryption at rest" is recoverable

- **Location:** `config/nativephp.php:63-78` (`cleanup_env_keys` strips `GITHUB_*`/`AWS_*`/`*_SECRET` but not `APP_KEY`); `app/Settings/AppSettings.php:347-371` (`Crypt::encrypt` of the API key).
- **Issue:** `APP_KEY` is (necessarily) bundled so Laravel `Crypt`/cookies work. The device API key stored encrypted in `settings.json` is therefore decryptable by anyone who extracts `APP_KEY` from the packaged app. If the same `APP_KEY` is baked for every install, the "encryption at rest" provides limited confidentiality.
- **Mitigations present:** The API key is device-scoped and short-lived (`now()->addHours(47)`, `RegisterDevice.php`), limiting value.
- **Action points:** Accept as a known trade-off, or generate `APP_KEY` per-install on first run so the bundled key is not shared across users. Document the threat model (local file read implies game over for a single-user app anyway).

### L-3. Debug routes expose destructive/ingest operations behind a locally-toggleable flag

- **Location:** `routes/web.php:258-305` (`/debug/*`, middleware `debug`); `app/Http/Middleware/EnsureDebugMode.php` gates on `AppSettings::isDebugMode()`, toggled by `UpdateDebugModeController` (`settings/debug-mode`).
- **Issue:** When debug mode is on, routes allow deleting matches/leagues, mutating log events, and **ingesting arbitrary log events** (`Debug\LogEvents\IngestController`). The gate is a user-settable boolean, not a build-time guard. On a single-user localhost app with CSRF this is not remotely reachable, but it widens the blast radius of any renderer XSS (H-1) when debug mode happens to be enabled.
- **Action points:** Compile debug routes out of production builds (or gate on `app()->isLocal()` in addition to the setting); ensure debug mode cannot be silently enabled by a forged request (it is CSRF-protected today — keep it that way).

### L-4. Unverified `.env` contents in production build

- **Location:** `.env` / `.env.example` (could not be read in this environment).
- **Issue:** I could not confirm the packaged values of `APP_DEBUG`, `APP_ENV`, `SENTRY_DSN`, or that no unexpected secret ships. Config defaults are safe (`APP_DEBUG=false`, `APP_ENV=production`) and `cleanup_env_keys` strips the obvious secret families, but the actual bundled file should be verified.
- **Action points:** Manually confirm the production `.env`: `APP_DEBUG=false`, `APP_ENV=production`, no `MYMTGO_API_VERIFY_SSL=false`, no stray tokens outside the `cleanup_env_keys` patterns. Consider adding `SENTRY_*` review (DSN is a write-only client key, generally safe to ship).

### L-5. `ImageBase64Controller` fetches stored URLs server-side (`file_get_contents($url)`)

- **Location:** `app/Http/Controllers/Cards/ImageBase64Controller.php:41`.
- **Issue:** Reads `card.image` (a stored Scryfall URL) via `file_get_contents`. The `oracleId` route param is used only in a parameterized query (safe), and the URL comes from the trusted card-catalog import, so this is **not** request-controllable SSRF today. Flagged for defense-in-depth: if the card catalog source were ever poisoned, this becomes an SSRF/local-file-read primitive (`file_get_contents` honors `file://`/`php://` wrappers).
- **Action points:** Restrict to `https://` and an allow-listed image host; prefer the Laravel HTTP client (no stream wrappers) over `file_get_contents`.

---

## Prioritized Action List

1. **(H-1)** Stop `v-html`-rendering GitHub release notes in `UpdateBanner.vue:94`; sanitize or render as text.
2. **(M-1)** Add a strict Content-Security-Policy to the renderer (`app.blade.php`); self-host fonts to keep it tight. Backstops H-1.
3. **(M-2)** Add Host/Origin validation middleware to the local server; confirm whether the NativePHP fork already binds a per-session token.
4. **(M-4 / L-4)** Verify production `.env`: `APP_DEBUG=false`, `APP_ENV=production`, `MYMTGO_API_VERIFY_SSL` unset/true, no stray secrets.
5. **(M-3)** Review consent UX and minimize/redact opponent PII in uploaded raw log samples (`SubmitMatchLogSample`).
6. **(L-1)** Verify Electron hardening flags in `alecritson/desktop` (`contextIsolation`/`nodeIntegration`/`sandbox`) and pin to an immutable ref.
7. **(L-3)** Compile `/debug/*` routes out of production (or add `app()->isLocal()` guard).
8. **(L-2, L-5)** Optional hardening: per-install `APP_KEY`; restrict `ImageBase64Controller` to allow-listed HTTPS hosts.

*No Critical findings. The high-value reassurance: semi-hostile MTGO log content (opponent names, chat, deck names) is correctly escaped/parameterized end-to-end and reaches no XSS, SQL-injection, or command-execution sink.*
