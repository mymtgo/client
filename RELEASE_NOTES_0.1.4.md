# v0.1.4 Release Notes

## Performance

- **Consolidated dashboard queries** — The home page now fetches match stats (wins, losses, games won, games lost) in a single database query instead of four separate queries.
- **Removed per-request deck loading** — Eliminated an expensive query with three subqueries that ran on every page load via shared Inertia data, even though the data was no longer displayed.
- **Added database indexes** — New indexes on `game_player`, `match_archetypes`, and `game_timelines` tables for faster joins and filtering on commonly queried columns.
- **Fixed deck version ordering** — `Deck::latestVersion()` now correctly returns the most recently modified version using `latestOfMany()` instead of an arbitrary record.

## Bug Fixes

- **Fixed job crash** — Removed a stray `dd()` call in `PopulateMissingCardData` that would crash the job on error instead of reporting it.
- **Fixed null identity handling** — Resolved an issue with null color identity values on decks.

## Code Cleanup

- **Removed 10 unused PHP files** — Deleted orphaned models (`User`, `GameDeck`, `GameDeckCard`), unused actions (`CreateMatch`, `CreateMatchGames`, `MatchGameDeck`, `StoreMatchResults`), and unreferenced DTOs (`GameEntryData`, `GameDeckData`, `GameCardData`).
- **Removed 20+ unused frontend files** — Cleared out unused Vue components (sidebar, breadcrumb, navigation menu, input OTP, chart, data table, drag/drop), composables (`useInitials`, `useTwoFactorAuth`, `useCurrentUrl`), and the unused bootstrap.js file.
- **Removed 7 unused npm packages** — Dropped `@phosphor-icons/vue`, `@tabler/icons-vue`, `chart.js`, `vue-chartjs`, `axios`, `chokidar`, and `lodash`. Moved 10 dev-only packages out of production dependencies.
- **Replaced lodash with native JS** — All lodash/lodash-es usage replaced with native `Array.filter`, `Array.find`, `Array.reduce`, and `Array.map`.

## Refactoring

- **Centralized format display** — Introduced `MtgoMatch::displayFormat()` to replace 7 duplicate `Str::title(strtolower(substr(...)))` calls across controllers and DTOs.
- **Reusable match scope** — Added `MtgoMatch::scopeSubmittable()` to replace 4 duplicate query chains for finding submittable matches.

## Frontend Improvements

- **Persistent Inertia layouts** — Pages now use a default layout set in `app.ts` instead of wrapping each page template in `<AppLayout>`, following Inertia.js best practices for layout persistence across navigation.
- **Button hover cursor** — All button variants now show `cursor-pointer` on hover when not disabled.
- **Scroll preservation** — Match deletion no longer jumps to the top of the page.
- **Stable list keys** — Match tables now use `match.id` for v-for keys instead of array index.
- **Removed dead SSR config** — Cleaned up a non-functional `ssr` entry from `vite.config.ts`.

## Bundle Impact

Net reduction of ~3,260 lines of code. Frontend bundle is leaner with unused libraries and components removed from the dependency tree.
