# TypeScript Best-Practices Audit — MTGO Deck Tracker Frontend

Audit scope: `resources/js` (304 Vue SFCs, 54 hand-written TS files, plus generated `actions/`, `routes/`, `wayfinder/`, `types/generated.d.ts`). Audited 2026-06-11.

## Summary

The codebase has a solid typing foundation — `strict: true`, 100% type-based `defineProps`/`defineEmits` (0 runtime declarations across 285 components), zero `@ts-ignore`/`@ts-expect-error`, zero non-null assertions, no TS enums (union types used consistently), well-typed composables, and strong Wayfinder adoption (128 `@/actions` imports, no legacy `route()` helper).

However, **type checking is not enforced anywhere**: `npm run build` is `vite build` only, there is no `lint`/`typecheck` script in `package.json`, and CI (`.github/workflows/`: pest, pint, discord-release) never runs `vue-tsc` or ESLint. The result: **`npx vue-tsc --noEmit` currently fails with 68 errors across 30 files**, several of which are genuine latent bugs (a component importing from the Electron build-output directory, a paginator typed as a plain array, missing required props/fields). Meanwhile `@typescript-eslint/no-explicit-any` is explicitly disabled, and ~70 `any` annotations exist in app code — 14 of them inside the *generated* DTO types because backend Spatie `Lazy` properties are untyped, which punches `any` holes through otherwise end-to-end typing.

### Key metrics

| Metric | Value |
| --- | --- |
| `strict` mode | ON (`noUncheckedIndexedAccess`, `noImplicitOverride`, `exactOptionalPropertyTypes` OFF) |
| `vue-tsc --noEmit` | **68 errors / 30 files** (exit 2); not run in build or CI |
| `: any` annotations (app code, excl. generated routes) | 52 (2 are comments) |
| `any[]` | 20 |
| `as any` | 3 (all in `components/ui/calendar`) |
| `@ts-ignore` / `@ts-expect-error` / `@ts-nocheck` | 0 |
| Non-null assertions (`!.`) | 0 |
| `any` fields in generated `types/generated.d.ts` | 14 (from untyped PHP `Lazy` props) |
| Runtime (untyped) `defineProps({...})` / `defineEmits([...])` | 0 / 0 |
| Hardcoded URL strings in `router.*` / `fetch` / `href` | ~26 (almost all `pages/debug/*`) |
| TS enums | 0 (unions used throughout — good) |

---

## Critical

### C1. Type checking gates nothing — 68 standing compiler errors
- **Location**: `package.json` (scripts: only `build`/`dev`), `.github/workflows/` (pest.yml, pint.yml only). Full error list reproducible via `npx vue-tsc --noEmit`.
- **Issue**: `vue-tsc` is installed but never executed by any script, build step, or CI job. 68 errors have accumulated across 30 files (top offenders: `pages/games/partials/GameReplaySnapshot.vue` ×11, `pages/partials/RecentMatches.vue` ×6, `pages/debug/LogEvents.vue` ×4). Error codes: TS2322 ×17, TS2561 ×15, TS2345 ×11, TS2339 ×11, TS7006 ×8, others ×6.
- **Impact**: Every other finding in this report is a downstream consequence. The type system exists only as editor decoration; regressions land silently. Several of the 68 errors are real bugs (see C2, C3, H-findings).
- **Action points**:
  - Add a `typecheck` script (`vue-tsc --noEmit`) and a `lint` script to `package.json`.
  - Add a CI workflow (mirroring pest.yml/pint.yml) that runs both on PRs.
  - Burn down the 68 errors; the `preserveScroll`/`AcceptableValue` clusters (M1, M2) account for ~25 of them and are mechanical fixes.
- **Fix guidelines**: Fix the mechanical clusters first to shrink the list, then gate CI before tackling the structural ones, so the count can only go down.

### C2. Component imports from the Electron build-output directory
- **Location**: `resources/js/components/settings/ApiStatusCard.vue:8-10`
- **Issue**: `Badge` is imported from `'../../../../nativephp/electron/dist/win-unpacked/resources/build/app/resources/js/components/ui/badge'` — a path inside a previous NativePHP Windows build artifact (almost certainly an IDE auto-import accident). `vue-tsc` reports TS2307 (module not found).
- **Impact**: The build only resolves on a machine where that stale artifact directory exists; on a clean checkout or CI runner, `vite build` fails. Even when it resolves, it bundles a *second, stale copy* of the Badge component.
- **Action points**: Change the import to `@/components/ui/badge`. Consider adding `nativephp/` to ESLint/TS excludes and an ESLint `no-restricted-imports` pattern banning `nativephp/` paths.
- **Fix guidelines**: One-line import fix; the restriction rule prevents recurrence since auto-import will happily pick these paths again.

### C3. Prop types that lie about runtime shape (paginator vs array, keyed object vs array)
- **Location**:
  - `pages/partials/RecentMatches.vue:15-17` — props declare `matches: App.Data.Front.MatchData[]`, but the template reads `matches.total`, `matches.data`, `matches.per_page` (6 TS2339 errors). The controller actually sends a Laravel paginator.
  - `pages/decks/Index.vue:43,215` — `DeckGroupData.decks` is generated as `{ [key: number]: DeckData }` but the component calls `.length` on it and passes it where `DeckData[]` is required (TS2339/TS2740).
- **Issue**: The declared types do not describe the actual payloads, so all downstream property access is unverified. The keyed-object generation comes from PHP collections being serialized without re-indexing — if a filtered collection ever ships non-sequential keys, JSON becomes an object and array methods break at runtime, exactly the case the type is warning about.
- **Impact**: This is the most dangerous category: the code *appears* typed, reviewers trust it, and the compiler errors documenting the mismatch are invisible (C1). A single backend `->filter()` without `->values()` turns the decks page into a runtime crash.
- **Action points**:
  - Define a generic `Paginated<T>` type (`data`, `total`, `per_page`, `current_page`, links) in `types/` and use it for `RecentMatches.matches` and other paginated payloads.
  - On the backend, ensure DTO collections are re-indexed (`->values()`) so the transformer emits `Array<T>`, or change the PHP property types so Spatie emits arrays; then fix the frontend to match whichever shape is canonical.
- **Fix guidelines**: Pick one source of truth for collection shape (sequential JSON arrays) and make both sides agree; don't cast around it.

---

## High

### H1. Generated DTO types contain `any` holes from untyped PHP `Lazy` props
- **Location**: `resources/js/types/generated.d.ts` — 14 `any` fields, e.g. `DeckData.matches/identity/cards` (lines 55-57), `GameData.players/timeline` (102-103), `MatchData.deck/opponentArchetypes/opponentName/leagueName/games/gameResults` (137-142), `ExternalCardStatsResponse.stats` (90). Root cause in backend: `app/Data/Front/DeckData.php:29-31` (`public Lazy $matches` etc., no docblock), `app/Data/Front/GameData.php:15-16` (`Lazy|Collection` without generics), `app/Data/Front/MatchData.php`.
- **Issue**: Spatie's TypeScript Transformer emits `any` for `Lazy` properties that lack a `@var`/attribute describing the resolved type. The whole point of generating types from DTOs is defeated for precisely the most-used payloads (decks, matches, games).
- **Impact**: `MatchData.games: any` cascades — `pages/games/partials/GameReplaySnapshot.vue` accumulates 11 implicit-`any` errors and re-types card/player shapes ad hoc (`(p: any) => p.IsLocal`, lines 20-34). Renames on the backend (e.g. `IsLocal`) would never be caught.
- **Action points**: Annotate every `Lazy` property with its resolved type (PHPDoc `@var Lazy|Collection<int, PlayerData>` or `#[DataCollectionOf(...)]` / `Lazy|ArchetypeData|null` unions as appropriate), regenerate, then fix the frontend errors the real types reveal.
- **Fix guidelines**: Backend-first fix; do one DTO at a time (DeckData, MatchData, GameData) and let `vue-tsc` surface the consumers that were relying on `any`.

### H2. Ad-hoc `any[]` payload shapes where real types already exist
- **Location** (20 `any[]` sites; worst clusters):
  - Archetype card lists: `pages/archetypes/Create.vue:17,35`, `Edit.vue:58`, `partials/ArchetypeForm.vue:25,44,60,76,83`, `partials/ArchetypePreview.vue:8,14-31`, `partials/DekUploadButton.vue:9` — `cards: any[]` everywhere, while `App.Data.Front.CardData` exists and is even used in the same file (`ArchetypeForm.vue:33` types `initialCards` correctly).
  - `pages/decks/Dashboard.vue:33` + `pages/decks/partials/DeckDashboard.vue:27,37-46` + `pages/decks/partials/MatchupSpread.vue:8` — `matchupSpread: any[]`, while `types/decks.ts:23` defines `MatchupSpread` and `components/matchups/MatchupSpreadTable.vue:13` uses it for the *same payload*.
  - `pages/decks/Leagues.vue:19` `leagues: any[]` and `pages/decks/Matches.vue:19-20` `matches: any` despite `types/leagues.ts` defining the full league model.
  - `pages/decks/CardStats.vue:16` `cardStats?: any`.
- **Issue**: The same backend payload is typed in one component and `any` in its siblings, so reducers like `DeckDashboard.vue:39` (`reduce((best: any, m: any) => ...)`) get zero checking.
- **Impact**: Field renames (`match_winrate`, `quantity`, `sideboard`) silently break dashboards/forms; this is the classic drift the generated/manual types were created to prevent.
- **Action points**: Replace `any[]` with the existing types (`MatchupSpread[]`, `App.Data.Front.CardData[]`, `LeagueRun[]`); add the missing `DeckCardStats` payload type; re-enable `@typescript-eslint/no-explicit-any` (H4) to stop regressions.
- **Fix guidelines**: Pure substitution work — the types already exist; expect a handful of genuine mismatches to surface, which is the value.

### H3. No shared Inertia `PageProps` type — shared props typed by assertion, six different ways
- **Location**: `app/Http/Middleware/HandleInertiaRequests.php:29-51` shares `flash`, `status`, `debugMode`, `activeAccount`, `accounts`, `availableUpdate`, `support`, `donation` — but there is **no** `declare module '@inertiajs/core'` augmentation anywhere (grep: 0 hits). Consumers each invent their own typing:
  - `components/StatusBar.vue:9` — `page.props.status as { watcherRunning: boolean; ... }`
  - `components/AppNav.vue:23` — `(usePage().props as Record<string, unknown>).debugMode as boolean`
  - `components/UpdateBanner.vue:16`, `SupportPopover.vue:6`, `AppHeader.vue:11`, `DonationModal.vue:9` — four separate inline `usePage<{...}>` generic redeclarations
  - `pages/settings/Index.vue:57`, `pages/archetypes/partials/ArchetypeForm.vue:67` — `props.errors as Record<string, string>`
- **Issue**: The same middleware payload is described by at least six independent, unverified shapes. None are checked against the backend or against each other.
- **Impact**: Renaming a shared prop key (e.g. `availableUpdate`) breaks N components with zero compiler feedback; double-casting through `Record<string, unknown>` is a pure type hole.
- **Action points**: Create a single `SharedPageProps` interface in `types/index.ts` mirroring the middleware, and augment `@inertiajs/core`'s `PageProps` (standard Inertia v2 pattern) so bare `usePage()` is typed everywhere; delete all inline generics and casts.
- **Fix guidelines**: One interface + one `declare module` block; consider generating the shape from a Spatie DTO returned by `share()` for end-to-end safety.

### H4. ESLint: `no-explicit-any` disabled, non-type-aware preset, never executed
- **Location**: `eslint.config.js:15` (`'@typescript-eslint/no-explicit-any': 'off'`), config uses `vueTsConfigs.recommended` (syntactic only, not `recommendedTypeChecked`); `package.json` has no `lint` script; no CI lint job.
- **Issue**: The one rule that would have stopped the `any` spread (H2) is explicitly off, type-aware rules (`no-floating-promises`, `no-unsafe-*`) are unavailable, and the linter isn't wired into any workflow anyway.
- **Impact**: No automated backstop for the patterns this report documents; async `fetch` handlers (20+ in `pages/`) have no floating-promise detection.
- **Action points**: Add `lint` script + CI; switch to `vueTsConfigs.recommendedTypeChecked` (with `projectService`); re-enable `no-explicit-any` (start as `warn`); consider `@typescript-eslint/no-floating-promises`.
- **Fix guidelines**: Enable rules as `warn` first, ratchet to `error` after the H2 cleanup lands; keep `components/ui/*` (shadcn-vue vendored code) in the ignore list as today.

---

## Medium

### M1. Inertia v2 API drift: `preserveScroll` passed to `router.reload()` — 15 call sites
- **Location** (TS2561 cluster): `components/cards/CardStatsView.vue:100`, `components/cards/ExternalStatsToggle.vue:40`, `pages/archetypes/partials/ArchetypeDetail.vue:77`, `pages/decks/partials/DeckCardStats.vue:154`, `DeckMatches.vue:54`, `ArchetypeDetectionBanner.vue:62`, `pages/debug/{Cards,Decks,DeckVersions,Games,Leagues,LogCursors,LogEvents,Matches,PipelineLog}.vue`, plus `preserveState` in `pages/archetypes/partials/MatchSelect.vue:40`.
- **Issue**: `ReloadOptions` in Inertia v2 does not accept `preserveScroll`/`preserveState` (reload preserves both by default). The option is dead weight that the compiler flags but nobody sees.
- **Impact**: Harmless today, but it demonstrates API-contract drift going unnoticed; if Inertia changes defaults, intent is undocumented.
- **Action points**: Remove the dead options (mechanical, clears 16 of the 68 errors).
- **Fix guidelines**: Straight deletion; verify one or two pages still preserve scroll on reload.

### M2. Select handlers assume `string`, reka-ui delivers `AcceptableValue` (nullable) — ~10 sites
- **Location** (TS2322 cluster): `pages/Index.vue:142`, `pages/decks/GameStats.vue:69`, `pages/archetypes/partials/ArchetypeSidebar.vue:84`, `components/debug/EditableCell.vue:58`, `components/leagues/LeagueCreateDialog.vue:121`, `pages/debug/{Cards,Games,LogEvents×2,PipelineLog}.vue`.
- **Issue**: `@update:model-value` handlers typed `(val: string) => ...` where the component emits `string | number | null`. A null (deselect) would flow into URL builders/state setters as `"null"`-ish behavior or throw.
- **Impact**: Real edge-case bug class on clearable selects; also 10+ of the standing errors.
- **Action points**: Type handlers as `(val: AcceptableValue)` (or the component's emitted union) and narrow explicitly before use.
- **Fix guidelines**: Centralize a small `asString(val): string | null` narrowing helper if the pattern repeats.

### M3. Hand-maintained mirror types drift from backend payloads
- **Location**: `types/decks.ts` (148 lines), `types/leagues.ts` (101), `types/reports.ts`, `types/tournaments.ts` — manually written shapes for controller-array payloads not covered by Spatie DTOs. Drift already observable: `pages/settings/Index.vue:195` builds a `sampleOpponent: OpponentData` missing the now-required `source` field (TS2741); `pages/reports/CardStats.vue:102` renders `CardStatsView` without required `deckWinrateRate`/`trustValue` props (TS2345).
- **Issue**: Two type sources exist (generated `App.Data.Front.*` + manual `types/*.ts`) with no mechanism keeping the manual ones honest, and some payloads (matchup spread) are typed manually in one component and `any` in another (H2).
- **Impact**: Exactly the drift the task warns about — backend adds a field, frontend types silently stay stale; required-prop errors prove it is already happening.
- **Action points**: Fix the two concrete errors; longer-term, migrate high-traffic payloads (leagues, matchups, card stats) to Spatie DTOs so they join `generated.d.ts`; keep manual types only for purely frontend shapes.
- **Fix guidelines**: Prioritize payloads consumed by 3+ components; one DTO per PR to keep diffs reviewable.

### M4. NativePHP event payloads typed by unverifiable assertion
- **Location**: `types/index.ts:11-17` declares `window.Native.on(callback: (payload: unknown, ...))` — correctly `unknown` — but `AppLayout.vue:18` and `components/UpdateBanner.vue:27` pass callbacks with structurally-asserted payload params (`{ type: string; title: string; ... }`, `Record<string, unknown>`), which `strictFunctionTypes` rejects (2 of the 68 errors). `pages/decks/Popout.vue:24` ignores the payload (fine).
- **Issue**: Event payloads cross a serialization boundary (PHP event → Electron bridge → JS) with no validation and a typing pattern the compiler refuses.
- **Impact**: A renamed field in `App\Events\AppNotification` produces undefined notification titles, not a type error.
- **Action points**: Accept `unknown` in listeners and narrow inside (type guard per event), or build a typed `onNativeEvent<E extends keyof NativeEventMap>` wrapper with a central event-name → payload map mirroring the PHP events.
- **Fix guidelines**: The event-map wrapper is ~20 lines and gives autocomplete on event names (currently raw `'App\\Events\\...'` strings).

### M5. Hardcoded URL strings instead of Wayfinder — concentrated in debug pages
- **Location**: ~26 occurrences: `pages/debug/*.vue` (Cards, Decks, DeckVersions, Games, Leagues, LogCursors, LogEvents, Matches, PipelineLog — e.g. `router.patch(\`/debug/decks/${deckId}\`...)` at `Decks.vue:37`), plus `pages/Index.vue:109` (`router.get('/', ...)`), `pages/Error.vue:46` (`router.visit('/')`), `components/AppNav.vue:51` (`href="/debug/matches"`).
- **Issue**: `resources/js/routes/debug/` exists — typed route functions are generated for these exact endpoints but unused. String templates bypass param typing and survive route renames silently.
- **Impact**: Lower because they are debug tooling, but they are also the pages with the most mutation endpoints (patch/delete/restore).
- **Action points**: Sweep `pages/debug/*` to Wayfinder imports; fix the three non-debug stragglers.
- **Fix guidelines**: Mechanical; the `wayfinder-development` skill patterns (`.url()`, route-model-binding args) apply directly.

### M6. Strictness flags left at template defaults
- **Location**: `tsconfig.json` — `strict: true` is on, but `noUncheckedIndexedAccess`, `noImplicitOverride`, `noFallthroughCasesInSwitch`, `noUnusedLocals/Parameters`, `exactOptionalPropertyTypes` are all commented out; `allowJs: true` with no JS files needing it; the file is the unedited tsc-init template (~100 lines of comments).
- **Issue**: Generated types use index signatures heavily (`{ [key: number]: DeckData }`, `ExternalCardStatsResponse.stats`); without `noUncheckedIndexedAccess`, every indexed read is assumed present — directly adjacent to the C3 bug class.
- **Impact**: Missing-element bugs on keyed-object payloads type-check fine.
- **Action points**: After the 68-error burn-down, trial `noUncheckedIndexedAccess` (expect a wave of `?.`/guard additions); turn off `allowJs`; trim the template comments while touching the file.
- **Fix guidelines**: Enable one flag per PR; `noUncheckedIndexedAccess` is the only one likely to find real bugs here.

---

## Low

### L1. Duplicate layout directories with different casing
- **Location**: `resources/js/layouts/` (ReportsLayout.vue) and `resources/js/Layouts/` (DeckViewLayout.vue, OverlayLayout.vue).
- **Issue**: Two casings of the same conceptual folder. `forceConsistentCasingInFileNames` is on, so imports won't silently mismatch, but it invites wrong-folder placement and is a hazard on case-insensitive→sensitive filesystem moves (project memory notes the lowercase `pages` convention already).
- **Action**: Consolidate into lowercase `layouts/` and update the three import sites.

### L2. Residual `as any` / loose casts in vendored UI
- **Location**: `components/ui/calendar/Calendar.vue:57,80` (`(e?.target as any)?.value`), `components/ui/chart/utils.ts:18,31`; also `components/ui/date-time-picker/DateTimePicker.vue:110` and `native-select/*.vue:12` contribute 4 of the 68 errors.
- **Issue**: shadcn-vue vendored components; ESLint already ignores `components/ui/*` but vue-tsc does not.
- **Action**: Fix the four compiler errors (the `data-slot` attr ones are one-line); leave cosmetic casts. Don't exclude `components/ui` from vue-tsc — these files render real UI.

### L3. `as`-cast inventory is healthy but worth a periodic sweep
- **Location**: 72 non-`any` assertions in app code; most are legitimate DOM narrowing (`event.target as HTMLInputElement`, `$el as HTMLElement`). The ones worth replacing: `(await response.json()) as App.Data.Front.ArchetypeData[]` (`ReassignVariantDialog.vue:56`, `MergeArchetypeDialog.vue:53`) — unvalidated network casts; and the `page.props.* as ...` family resolved by H3.
- **Action**: Fold the JSON casts into a tiny typed-fetch helper for Wayfinder URLs; nothing urgent.

---

## Prioritized Action List

1. **Fix the Electron build-output import** in `components/settings/ApiStatusCard.vue:8-10` (C2) — one line, prevents clean-checkout build failures.
2. **Add `typecheck`/`lint` scripts + CI job** running `vue-tsc --noEmit` and ESLint (C1, H4) — gate before burn-down completes so the count only drops.
3. **Mechanical error burn-down**: remove `preserveScroll` from 16 `router.reload()` calls (M1) and fix ~10 `AcceptableValue` handlers (M2) — clears ~40% of the 68 errors in an afternoon.
4. **Fix the type lies**: `Paginated<T>` for `RecentMatches.vue`, reconcile `DeckGroupData.decks` keyed-object vs array with the backend (C3).
5. **Type the backend `Lazy` props** in `app/Data/Front/{DeckData,MatchData,GameData}.php`, regenerate `generated.d.ts`, fix revealed consumers — biggest systemic win (H1); unblocks de-`any`-ing `GameReplaySnapshot.vue`.
6. **Replace `any[]` payload props** with existing `MatchupSpread`/`CardData`/`LeagueRun` types across archetypes + decks pages (H2).
7. **Create `SharedPageProps` + `@inertiajs/core` module augmentation**; delete the six divergent inline `usePage` typings (H3).
8. **Re-enable `no-explicit-any` (warn → error)** and move to `recommendedTypeChecked` (H4).
9. **Sweep `pages/debug/*` to Wayfinder route functions** (M5); fix `OpponentData.source` and `CardStatsView` missing-props bugs (M3).
10. **Tighten tsconfig** (`noUncheckedIndexedAccess`, drop `allowJs`) and merge `layouts/`+`Layouts/` (M6, L1).
