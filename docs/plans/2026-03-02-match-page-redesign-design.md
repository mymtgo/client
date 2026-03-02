# Match Page Redesign — Design

## Summary

Redesign the match show page for clear hierarchy and navigation. Add back-link navigation to all detail pages. Slim down per-game sections by removing cards played/seen (the replay covers that).

## Back Link Component

Reusable `BackLink.vue` for detail pages:

```
← Modern Burn          (match page → deck)
← vs OpponentName      (game replay → match)
```

Small ChevronLeft icon + link text. Sits at the top of page content, muted styling.

Used on: matches/Show, games/Show.

## Match Summary — Result-First

```
← Modern Burn

  WIN   2–1        vs OpponentName
                   Archetype Name ⬡⬡⬡  ✏️

  Modern Burn · Modern · Jan 15, 2026 at 3:45pm · 28m · League Name
```

- Result badge + score prominent
- Opponent name as primary heading
- Archetype + edit on second line
- Metadata line: deck link, format, date, duration, league

## Per-Game Cards — Collapsible

**Always visible header:**
```
Game 1    Win    On the play · 12m · 8 turns
```

**Collapsible sections:**
- Opening Hand / Kept Hand — open by default
- Mulliganed Hands — collapsed, only if mulligans happened
- Sideboard Changes — collapsed, only for games 2+
- View Replay button

**Removed:** Cards Played, Opponent Cards Seen (replay covers this).

## Bug Fix

Remove stray `{{ game.id }}` from MatchGame.vue kept hand label.

## Files

- Create: `resources/js/components/BackLink.vue`
- Modify: `resources/js/pages/matches/Show.vue`
- Modify: `resources/js/pages/matches/partials/MatchGame.vue`
- Modify: `resources/js/pages/games/Show.vue`
