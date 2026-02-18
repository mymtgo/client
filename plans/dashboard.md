# Plan: Dashboard Redesign (Fake Data)

## Context

The current dashboard is a basic stats summary with a timeframe selector, 4 metric cards, and tabs for recent matches / deck performance. The new spec calls for a personal MTGO snapshot: active league status, format win-rate trends over time, best/worst performing decks, and a recent matches list. All sections will use hardcoded fake data so the layout and flow can be validated before any backend work.

Reference spec: `specs/dashboard.md`

---

## Approach

Rewrite `Index.vue` with four clearly defined sections. Replace all existing partials with new ones. Each component defines its own fake data as a `const` at the top so it's easy to identify what needs replacing later. Preserve the Inertia page shell (no change to routing or controller) — the page just ignores its props for now.

---

## Sections & Components

### 1. Active League Card — `partials/DashboardLeague.vue` (new)

Fake data shape:
```ts
const league = {
  isActive: true,
  deck: { id: 1, name: 'Boros Energy', format: 'Standard' },
  record: { wins: 2, losses: 1 },
  matchesRemaining: 2,
  completedAt: null,
  isTrophy: false,
}
```
- Show pips or dots for each of the 5 matches (filled W / filled L / empty)
- "2-1 · 2 remaining" copy when active
- If inactive, show last run's final record with optional trophy icon

### 2. Format Performance Chart — `partials/DashboardFormatChart.vue` (new)

Fake data: array of monthly data points per format, covering ~6 months.

```ts
const formatData = [
  { month: '2025-09', Modern: 58, Pioneer: 62 },
  { month: '2025-10', Modern: 61, Pioneer: 55 },
  ...
]
```

Use **Unovis** matching the existing `MatchHistoryChart.vue` pattern.

### 3. Best & Worst Decks — `partials/DashboardDecks.vue` (new)

Two side-by-side `Card` components.

```ts
const bestDeck  = { id: 1, name: 'Boros Energy',   format: 'Standard', winRate: 68, matchCount: 34 }
const worstDeck = { id: 2, name: 'Azorius Control', format: 'Pioneer',  winRate: 38, matchCount: 12 }
```

Each card: deck name, format badge, win rate (large %), match count as subtext.

### 4. Recent Matches — simplified `partials/RecentMatches.vue`

Hardcoded fake match rows (5–8 entries). No pagination. "View all" static link.

---

## Files Changed

| Action | File |
|--------|------|
| Rewrite | `resources/js/Pages/Index.vue` |
| Create | `resources/js/Pages/partials/DashboardLeague.vue` |
| Create | `resources/js/Pages/partials/DashboardFormatChart.vue` |
| Create | `resources/js/Pages/partials/DashboardDecks.vue` |
| Modify | `resources/js/Pages/partials/RecentMatches.vue` |
| Keep | `resources/js/Pages/partials/DeckPerformance.vue`, `DashboardStats.vue` |

---

## Verification

1. `npm run dev` — open dashboard, confirm no console errors
2. League card renders with pip indicators and record copy
3. Format chart renders with multiple coloured lines
4. Best/worst deck cards side by side
5. Recent matches list renders without pagination
