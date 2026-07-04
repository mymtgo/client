# Client Auth — Auth Window, OAuth2 PKCE, Tokens

> Extracted from the v1 architecture brainstorm (2026-06-30). The client side of auth. Server side (Passport OAuth2 server, Discord, email/password) in [`../cloud-auth/spec.md`](../cloud-auth/spec.md).

## Methods

- **Two auth methods only: Discord OAuth and email/password.** No other providers. Each account uses one method — log in with Discord and that *is* the account's identity; no linking multiple methods to one account.

## Dedicated auth window (not a modal)

If the user is not logged in, the app opens a **separate authentication window** (its own NativePHP/Electron window, not an overlay covering the app). The user completes login there; on the post-auth redirect the app **closes the auth window and opens the main app window**. The main window is never shown to an unauthenticated user.

## Desktop auth = OAuth2 Authorization Code + PKCE

The client is a public client on a *different domain* than the API, so **Sanctum/cookie auth won't work**, and **no OAuth client secret can ship in the client** (it's extractable from shipped `.env`). PKCE solves both — no secret needed.

Flow:
1. Client generates a PKCE verifier + challenge.
2. Client opens the auth window at the API's `/oauth/authorize?...&code_challenge=...`.
3. The API renders login — **Discord button or email/password, both served by the *cloud* API, not the local Laravel**. (Discord's own OAuth handshake happens server-side; the Discord secret lives only on the API.)
4. On success the API redirects to **`mymtgo://oauth/callback?code=...`**.
5. NativePHP deep-linking (config `deeplink_scheme` / `NATIVEPHP_DEEPLINK_SCHEME` = `mymtgo`) routes the callback to the client.
6. Client exchanges `code` + PKCE verifier at `/oauth/token` for **access + refresh tokens**.
7. Close the auth window, open the main window.

## Tokens

- **Per-device, refreshable, revocable.** Client stores its tokens locally and sends `Authorization: Bearer` on every push/read.
- Access-token expiry → **silent refresh** (no re-login, outbox preserved).
- Server can revoke a device → forces re-auth.
- The **Discord client secret lives only on the API server**, never on the device.

## MTGO identity binding

- On the gaming machine, read the **MTGO username + `mtgo_player_id` from the logs** (`PlayerIds`) and send them alongside pushes, **non-editable** by the user.
- **Strict 1:1 binding: one MTGO username ↔ one app account.** People do run multiple MTGO accounts — each gets its **own separate app account** (its own registration/auth). Two machines gaming under the *same* username resolve to the same app account (union of matches). Identity is the stable `mtgo_player_id`.
- **Username-mismatch guard.** If the signed-in account is bound to username A and the client sees a *different* username B in the logs, it must **stop and prompt**: re-authenticate / create a new account for B. Until the user does, the client **logs and pushes nothing** for games played under B — never attach B's matches to A's account. (Extends the "unresolved username → hold" rule.)
- **Stable local identity via `PlayerIds`.** The log's `PlayerIds` field carries both players' stable numeric MTGO ids (verified identical + same order on both sides). Store the **local player's MTGO id** on the account binding → "which side am I / who is the opponent" resolves deterministically. Order is canonical (not "me-first"), so disambiguate self via the stored id (or the username→id map decoded from MetaMessage), never by position.
- **Flaky username:** `Mtgo::getUsername()` is known-flaky. Never bind an account or attach a push to a garbage/empty read. If unresolved, hold pushes in the outbox. The stable player id is the more reliable primary identity; username is a display attribute.
