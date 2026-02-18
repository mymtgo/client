# Plan: Deck View Redesign (Fake Data)

## Context

The existing `decks/Show.vue` was functional but cluttered — two rows of loose stat cards, a non-functional time filter dropdown, and `MatchHistoryChart.vue` existed but was never rendered. The spec calls for a cleaner side-by-side layout: sticky decklist on the right, a proper deck header + condensed stats + win rate chart + tabs on the left. All data replaced with fake constants.

Reference spec: `specs/deck-view.md`

---

## Layout

```
┌─ AppLayout (title = deck name) ─────────────────────────────────────┐
│  grid grid-cols-12                                                   │
│  ┌─ col-span-8 ───────────────────┐  ┌─ col-span-4 sticky ─────────┐│
│  │  p-4 lg:p-6                   │  │  overflow-y-auto p-4         ││
│  │  [Deck header]                 │  │  DeckList                    ││
│  │  name · [Standard] · 68% · 34 │  │                              ││
│  │  Last played 2 days ago        │  │                              ││
│  │                                │  │                              ││
│  │  [Stats Card: Match W/L |      │  │                              ││
│  │   Game W/L | OTP % | OTD %]   │  │                              ││
│  │                                │  │                              ││
│  │  [Win Rate Over Time chart]    │  │                              ││
│  │                                │  │                              ││
│  │  [Tabs: Matches | Matchups |   │  │                              ││
│  │         Leagues]               │  │                              ││
│  └────────────────────────────────┘  └──────────────────────────────┘│
└──────────────────────────────────────────────────────────────────────┘
```

---

## What Changed in `Show.vue`

**Removed:**
- Non-functional `NativeSelect` time filter
- Two rows of 4+3 `Card` stat blocks
- `import { home } from '@/routes'` (AppLayout no longer uses `back` prop)

**Added:**
- Deck header: name (h1), format `Badge`, win rate % (coloured), match count, last played (relative)
- Condensed stats row: 4 inline stat cells in single `Card` (Match W/L · Game W/L · OTP · OTD)
- `MatchHistoryChart` wired with fake monthly `{ date, winrate }` data
- Tabs reordered: **Matches** (default) | **Matchups** | **Leagues**
- All fake data constants at top of `<script setup>`

**Kept:**
- `col-span-8` / `col-span-4` grid split (from 9/3)
- `DeckList`, `DeckListCard`, `DeckLeagues`, `DeckMatches`, `MatchupSpread`, `MatchHistoryChart` — untouched

---

## Files Changed

| Action  | File |
|---------|------|
| Rewrite | `resources/js/Pages/decks/Show.vue` |
| Create  | `plans/deck-view.md` |
