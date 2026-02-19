# Plan: Match Reporting (Client)

> **Related API plan**: `../api/plans/match-reporting.md`

---

## Overview

After a match is fully built and committed to the local database, optionally submit it to the API in the background. This is opt-in via a user setting. Submissions are fire-and-mark: on failure the match is flagged locally and retried on the next app launch.

---

## Opt-In Setting

- Key: `share_stats` (NativePHP `Settings::get/set`)
- Default: `false`
- Exposed in the settings UI as a toggle (label TBD — e.g. "Share match stats anonymously")
- When disabled, no submission is attempted and any previously unsubmitted matches remain unsubmitted (they are not retroactively submitted if the user later opts in — only new matches from that point forward)

---

## Local Schema Change

Add a `submitted_at` (nullable datetime) column to the `matches` table.

```
matches
  ...
  submitted_at  — datetime, nullable (null = not yet submitted or submission failed)
```

- `null` + opt-in enabled + archetypes known = eligible for submission
- `null` + opt-in disabled = never submitted, not retried
- Populated with `now()` on successful API response

---

## Trigger Point

Submission is triggered at the **end of `BuildMatch::run()`**, after `DB::commit()` and after the notification fires.

```php
// After DB::commit() and Notification::show()
SubmitMatch::dispatch($match->id);
```

The `SubmitMatch` job is queued and runs in the background — the user is never blocked.

---

## Submission Preconditions

`SubmitMatch` (or the action it calls) must verify all of the following before attempting submission. If any condition fails silently, the match remains with `submitted_at = null` and is eligible for the retry pass:

1. `Settings::get('share_stats')` is `true`
2. The match has both a **player archetype** and an **opponent archetype** resolved (via `match_archetypes`)
3. The match has a `deck_version_id` (deck was successfully determined)
4. The match is not already submitted (`submitted_at` is null)

If archetypes are not yet resolved at dispatch time (e.g. the archetype estimation job is still queued), the submission will simply be deferred to the retry pass on next launch.

---

## Action: `SubmitMatchToApi`

Single-responsibility action called by the job.

**Steps:**

1. Load the match with its `league`, `archetypes`, and `deckVersion`.
2. Resolve the player archetype UUID and opponent archetype UUID.
3. Determine `is_tournament`:
   - `true` if `match->league->phantom === false`
   - `false` if `match->league->phantom === true`
4. Determine `league_token`:
   - Send `match->league->token` only when `is_tournament === true`
   - Send `null` for phantom leagues
5. Build the deck payload from `match->deckVersion->cards` (the `cards` accessor decodes the signature into `oracle_id` + `quantity` + `sideboard`). **Note:** the client stores oracle IDs, but the API needs MTGO IDs. The deck payload must be built from the raw `deck_version_cards` joined to `mtgo_ids`, not the signature accessor.
6. POST to `{config('mymtgo_api.url')}/matches/report` with:
   - Header `X-App-Secret: {config('mymtgo_api.secret')}`
   - JSON payload (see below)
7. On `2xx` response: update `match->submitted_at = now()`
8. On any other response or exception: leave `submitted_at = null`, log the failure

---

## Payload Shape

```json
{
  "match_token": "string",
  "username": "string (Mtgo::getUsername())",
  "player_archetype_uuid": "uuid",
  "opponent_archetype_uuid": "uuid | null",
  "result": "win | loss",
  "format": "string",
  "is_tournament": true,
  "league_token": "string | null",
  "challenge_token": null,
  "played_at": "ISO 8601 (match->started_at)",
  "deck": [
    { "mtgo_id": 12345, "quantity": 4, "zone": "main" },
    { "mtgo_id": 67890, "quantity": 2, "zone": "side" }
  ]
}
```

**Deck note:** Cards are sourced by joining `deck_version_cards` (on the client's local `DeckVersion`) through to the MTGO ID. The client's `DeckVersion` already stores card data — the exact join path needs to be confirmed when implementing, as the client's `deck_version_cards` may store oracle IDs rather than MTGO IDs directly. If MTGO IDs are not available locally, a lookup via the `cards` table will be needed.

---

## Retry on Launch

On application boot (in `MtgoManager` or a dedicated boot action), query for unsubmitted matches and re-queue them:

```php
MtgoMatch::whereNull('submitted_at')
    ->whereHas('archetypes') // has at least one archetype
    ->whereNotNull('deck_version_id')
    ->get()
    ->each(fn ($match) => SubmitMatch::dispatch($match->id));
```

This handles:
- Matches that failed to submit due to network errors
- Matches where archetypes weren't resolved at the time of the initial dispatch
- Matches from before the user opted in (they remain permanently unsubmitted — the retry pass only queues jobs when opt-in is currently enabled)

**Guard:** The retry only runs if `Settings::get('share_stats')` is `true`.

---

## Config

Add a `config/mymtgo_api.php` config file:

```php
return [
    'url'    => env('MYMTGO_API_URL', 'https://api.mymtgo.com'),
    'secret' => env('MYMTGO_API_SECRET'),
];
```

Both values should be set in the bundled `.env` (not user-configurable).

---

## Job: `SubmitMatch`

```php
class SubmitMatch implements ShouldQueue
{
    public function __construct(protected int $matchId) {}

    public function handle(): void
    {
        SubmitMatchToApi::run($this->matchId);
    }
}
```

- Uses the standard Laravel queue (same as `BuildMatch`)
- No explicit retry count — the app-launch retry pass handles persistence across restarts
- Failure is silent from the user's perspective (no notification)

---

## Files to Create / Modify

| Type | Path | Change |
|------|------|--------|
| Migration | `database/migrations/..._add_submitted_at_to_matches_table.php` | New |
| Config | `config/mymtgo_api.php` | New |
| Action | `app/Actions/Matches/SubmitMatchToApi.php` | New |
| Job | `app/Jobs/SubmitMatch.php` | New |
| Modify | `app/Actions/Matches/BuildMatch.php` | Dispatch `SubmitMatch` after commit |
| Modify | `app/Managers/MtgoManager.php` | Add boot-time retry pass |
| Settings UI | TBD (settings page) | Add `share_stats` toggle |

---

## Notes & Edge Cases

- **No retroactive submission**: Matches played before opt-in was enabled are never submitted, even on the retry pass.
- **Phantom leagues**: Always sent as `is_tournament: false` with `league_token: null`. They are still submitted (so the API can track casual archetype match-ups) — just not associated to a league.
- **Match result**: Derived from `games_won` / `games_lost`. Win = `games_won > games_lost`.
- **Opponent archetype unknown**: If the API could not classify the opponent's archetype (null in `match_archetypes`), the match is held back and retried on next launch. It will remain unsubmitted indefinitely if the opponent archetype is never resolved. This is an acceptable trade-off — we want both archetypes for meaningful data.
