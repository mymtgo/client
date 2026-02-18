# Plan: Match View (Fake Data)

## Context

The existing `matches/Show.vue` was a minimal skeleton — tabs with a card image grid, no structure. Rewritten to match the spec: a match summary header followed by per-game sections with cards seen + timeline.

Reference spec: `specs/match-view.md`

---

## Layout

```
┌─ Match summary card ───────────────────────────────────────────────┐
│  [Win 2–1]  vs Karadorinn                  Boros Energy [Standard] │
│             Dimir Midrange [U][B]  [✎]     Feb 17, 2026 at 7:34pm │
│                                             36m · League #5         │
└────────────────────────────────────────────────────────────────────┘
┌─ Game 1 ───[Win] · On the play · 12m · Karadorinn: 1 mulligan ─────┐
│  Cards Seen (col-1)     │  Timeline (col-2)                        │
│  Deep-Cavern Bat ×2     │  ● Karadorinn took a mulligan to 6       │
│  Preordain ×2           │  ○ T2: Deep-Cavern Bat (opponent)        │
│  Duress ×1              │  ○ T3: Phlage entered (you)              │
│  Memory Deluge ×1       │  ● Game 1 — Win (turn 7)                 │
└─────────────────────────────────────────────────────────────────────┘
... Game 2, Game 3 ...
```

---

## Files Changed

| Action  | File |
|---------|------|
| Rewrite | `resources/js/Pages/matches/Show.vue` |
| Rewrite | `resources/js/Pages/matches/partials/MatchGame.vue` |
| Rewrite | `resources/js/Pages/matches/partials/MatchGameTimelineEntry.vue` |
| Create  | `plans/match-view.md` |

---

## Timeline Entry Types

| Type | Dot | Meaning |
|------|-----|---------|
| `mulligan` | Amber | A player took a mulligan |
| `play` (local) | Faint green | Your play |
| `play` (opponent) | Muted grey | Opponent play |
| `end` | Green/Red (larger) | Game result with border-t separator |
