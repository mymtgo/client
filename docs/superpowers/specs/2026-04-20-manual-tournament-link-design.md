# Manual Tournament Link — Design

**Date:** 2026-04-20
**Status:** Design approved, pending implementation plan

## Background

Matches are auto-linked to tournaments by `App\Actions\Tournaments\LinkMatchToTournament`, which fires from `AdvanceMatchState` when a match join carries `gameMeta.Description` with `Tournament:{eventId} Round:{n}`. When that metadata is absent at join time (a known failure mode — see commit `8b7eeb4` "log when tournament join lacks gameMeta for linking"), the match is created without a tournament link and no retry path exists.

Retroactive backfill from `log_events` was previously deemed non-viable: participated matches in client logs have an empty `Description=` field. Source: `project_challenge_backfill.md`.

## Goal

Give users a manual path to link any match to a participated tournament, including matches whose auto-link failed and matches linked to the wrong tournament. No changes to the auto-link path or to the existing spectated-tournament storage (self-cleaning rolling window stays as-is).

## User Flow

1. User right-clicks a match row in `MatchesTable` (rendered on matches index, deck dashboard, and recent matches partial).
2. New context-menu item **"Link to tournament"** is always visible.
3. Modal opens:
   - Match unlinked: tournament picker + round input empty.
   - Match already linked: picker pre-selects current tournament, round input shows `tournament_round`, an **Unlink** button appears.
4. Tournament picker is a searchable combobox. Default list: `participated = true` tournaments whose `type` resolves from `matches.format` (via `TournamentType::fromPlayFormatCd`) and whose `started_at`/`scheduled_at` falls within ±12h of `matches.started_at`. A **"Show more"** toggle widens the list to all participated tournaments.
5. On save: match updates, modal closes, Inertia partial reload refreshes the table.
6. On unlink: `tournament_id`, `tournament_round`, `participant_login_ids` on the match are nulled.

## Backend

### Action — `App\Actions\Tournaments\ManuallyLinkMatchToTournament`

Separate from `LinkMatchToTournament` because the auto-flow is event-driven and needs `firstOrCreate` from `gameMeta`. SRP over DRY.

```php
public static function link(MtgoMatch $match, Tournament $tournament, int $round): void
public static function unlink(MtgoMatch $match): void
```

**`link()` behaviour:**
- Sets `matches.tournament_id = $tournament->id`.
- Sets `matches.tournament_round = $round`.
- Derives `participant_login_ids` from the match's own players' `login_id` values (local + opponent). Filters null values out. Writes the resulting array (may be empty).
- Dispatches `BackfillTournamentPlayerLoginIds` for `$tournament` so standings-to-username resolution stays consistent (same as auto-link path).

**`unlink()` behaviour:**
- Nulls all three columns on the match.
- Does not touch the tournament row (its `participated` flag stays true — other matches may still reference it).

### Controller — `App\Http\Controllers\Matches\LinkToTournamentController`

Single-action invokable.

- **POST** `/matches/{match}/tournament` — link or relink.
- **DELETE** `/matches/{match}/tournament` — unlink.

Form Request validation (`LinkMatchToTournamentRequest`):

| Field           | Rule                                                              |
| --------------- | ----------------------------------------------------------------- |
| `tournament_id` | `required`, `exists:tournaments,id`, must have `participated=true` (custom rule or scoped exists) |
| `round`         | `required`, `integer`, `min:1`, `lte:{tournament.max_rounds}` when tournament has one, otherwise `min:1` only |

Returns `back()` so Inertia reloads props from the calling page.

### Candidates endpoint — `App\Http\Controllers\Tournaments\CandidatesController`

Single-action invokable. `GET /tournaments/candidates?match_id={id}&all={0|1}`.

- Default: participated tournaments with matching **type**, within ±12h of match's `started_at`. Type comparison is `TournamentType::fromPlayFormatCd($match->format) === $tournament->type`, because `matches.format` stores MTGO play-format codes (`CMODERN`, `CPAUPER`, `CStandard`) while `tournaments.type` is the normalised enum. Do **not** compare against `tournaments.format` — that stores game-structure values (`Modern`, etc.) and won't match.
- If `started_at` is null, skip the time window (type filter only).
- `all=1`: every participated tournament, ordered by `scheduled_at`/`started_at` desc.
- Response: array of a new `App\Data\Front\TournamentCandidateData` DTO — `id`, `eventId`, `type`, `format`, `scheduledAt`, `startedAt`, `maxRounds`.

Fetch is triggered when the modal opens; list is not shipped with the matches table.

### Schema

No migrations. Columns already exist: `matches.tournament_id`, `matches.tournament_round`, `matches.participant_login_ids` (from migration `2026_04_14_000004_add_tournament_id_to_matches_table.php`).

### MatchData DTO

`App\Data\Front\MatchData` needs `tournament` (minimal shape `{ id, eventId, format }`) and `tournamentRound` so the context menu / modal can pre-populate state. Extend if absent; keep the shape narrow.

## Frontend

### Modal — `resources/js/components/matches/LinkTournamentDialog.vue`

Follows the imperative-ref pattern already used by `SetArchetypeDialog` and `MatchNotesDialog` in `MatchesTable`.

**Public API:**
```ts
openForMatch(matchId: number, currentTournamentId: number | null, currentRound: number | null): void
```

**Internal state:**
- `matchId`, `selectedTournamentId`, `round`, `showAll`, `candidates`, `loading`.

**On open:**
- Fetch `/tournaments/candidates?match_id={matchId}` via Wayfinder (`CandidatesController`).
- Pre-select `currentTournamentId` / `currentRound` if provided.

**Toggle `showAll`:** refetches with `all=1`.

**Controls:**
- Tournament combobox — reuse existing combobox primitive in `components/ui/` (check first; follow shadcn-vue conventions if a new one is needed). Search by `eventId` or date.
- Round — `<Input type="number" :min="1" :max="selectedTournament?.maxRounds ?? undefined" />`.
- Footer — **Unlink** (left, `variant="destructive"`, only when `currentTournamentId` is set), **Cancel** and **Save** (right).

**Submit:** `useForm` → `LinkToTournamentController(...)` POST. On unlink: `router.delete(LinkToTournamentController.url(...))`.

### MatchesTable changes

- Add `<LinkTournamentDialog ref="linkTournamentDialog" />` alongside existing dialog refs.
- Add `ContextMenuItem` labelled **"Link to tournament"** that calls `linkTournamentDialog?.openForMatch(...)` with the match's current link state.
- No visual or structural change to rows.

### Routing

Wayfinder regenerates on next `wayfinder:generate` — no manual route registration in the frontend.

## Edge Cases

| Case                                             | Behaviour                                                                 |
| ------------------------------------------------ | ------------------------------------------------------------------------- |
| Match had no previous link                       | Write columns, dispatch backfill.                                         |
| Match linked to different tournament             | Overwrite all three columns, dispatch backfill for the new tournament.    |
| Unlink                                           | Null all three columns; tournament row untouched.                         |
| Tournament deleted while modal open              | `exists` rule fails; toast; candidates refetched.                         |
| `round > max_rounds`                             | Validation rejects; inline form error.                                    |
| No candidates in default window                  | Empty state in combobox with prompt to toggle "Show more".                |
| Match with null `started_at`                     | Candidates endpoint skips time window, returns type-matching tournaments only. |
| Match with a `format` that doesn't map to a `TournamentType` | No default filter possible — candidates endpoint returns empty default list; user toggles "Show more". |
| Duplicate link (same tournament + round elsewhere) | **Allowed.** No uniqueness guarantee across matches.                      |

## Tests

- **Unit — `ManuallyLinkMatchToTournamentTest`** (Pest, `tests/Unit/`)
  - Links an unlinked match — columns populate, `participant_login_ids` derives from players' `login_id`.
  - Relinks a linked match — all three columns overwrite cleanly.
  - Links with all player `login_id` null — writes empty array, no crash.
  - Unlink — nulls all three columns, tournament row untouched.
  - Dispatches `BackfillTournamentPlayerLoginIds` on link.

- **Feature — `LinkToTournamentControllerTest`** (Pest, `tests/Feature/`)
  - POST happy path, DELETE unlink.
  - 422 when `tournament_id` missing / not participated.
  - 422 when `round` out of range or missing.
  - 404 when match doesn't exist.

- **Feature — `CandidatesControllerTest`** (Pest, `tests/Feature/`)
  - Default: filters by resolved `type`, ±12h window, excludes `participated=false`.
  - `all=1`: returns all participated regardless of type/time.
  - Match with null `started_at`: time filter skipped.
  - Match with `format` that doesn't map to a `TournamentType`: default list is empty; `all=1` still works.

Skip browser tests for `MatchesTable` unless an existing pattern exists.

## Out of Scope

- Retro-backfill heuristics (explicitly rejected per `project_challenge_backfill.md`).
- Bulk link (selecting multiple matches at once).
- Creating a new tournament from the modal.
- Changes to spectated tournament storage or the rolling-window cleanup.
- Changes to the auto-link path (`LinkMatchToTournament`).
