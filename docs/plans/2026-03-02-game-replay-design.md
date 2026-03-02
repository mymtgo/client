# Game Replay View — Design

## Summary

Redesign the game replay snapshot to mirror MTGO's battlefield layout. Opponent zones at top, player zones at bottom. Graveyard and exile as expandable badge counts. Timeline scrubber with visible tick marks at event positions.

## Board Layout

```
┌──────────────────────────────────────────────────┐
│ OpponentName          ♥ 20  Hand: 5  Library: 42 │
│ [Graveyard (3)] [Exile (1)]                      │
├──────────────────────────────────────────────────┤
│  Opponent Battlefield (card images, flex-wrap)    │
├─ ─ ─ ─ ─ ─ ─ THE STACK ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ┤
│  Stack cards (only if non-empty)                 │
├─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─┤
│  Your Battlefield (card images, flex-wrap)        │
├──────────────────────────────────────────────────┤
│ YourName              ♥ 18  Hand: 4  Library: 38 │
│ [Graveyard (5)] [Exile (2)]                      │
├──────────────────────────────────────────────────┤
│  Your Hand (card images)                         │
└──────────────────────────────────────────────────┘
│  Timeline with tick marks at event positions      │
│  Controls (play/pause, step, speed) — unchanged   │
└──────────────────────────────────────────────────┘
```

## Components

### Player info bar
Compact bar per player. Name left, life (heart icon) + hand count + library count right. Below: graveyard and exile as clickable badges that expand to show cards.

### Battlefields
Card images in flex-wrap rows. Small sizing (grid-cols-12 scale). Opponent top, player bottom.

### Stack
Horizontal row between battlefields. Only rendered when cards exist on it. Subtle label.

### Hand
Player's hand shown as card row at bottom. Opponent hand shown only as count in info bar.

### Timeline
Always show tick marks at each event position. Current = primary color, past = dimmed, future = muted.

### Controls
Keep current play/pause, step prev/next, speed selector as-is.

## Files

- Rewrite: `resources/js/pages/games/partials/GameReplaySnapshot.vue`
- Modify: `resources/js/pages/games/partials/GameReplayTimeline.vue`
- Keep: `GameReplay.vue`, `GameReplayControls.vue`

## Data

Each timeline event `content` has:
- `Players[]`: Id, Name, Life, HandCount, LibraryCount, IsLocal
- `Cards[]`: Id, CatalogID, Zone, Owner, Controller, image

Zones: Hand, Battlefield, Stack, Graveyard, Exile, Library
