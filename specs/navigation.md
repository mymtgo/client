# Navigation

## Purpose

Defines the overall navigation structure of the app — what lives in the sidebar, how pages are linked, and the top-level routing model.

---

## Sidebar

The sidebar is simplified to top-level navigation only. Deck browsing moves entirely to the dedicated Deck listing page.

### Top-level nav items

| Label | Icon | Destination |
|-------|------|-------------|
| Dashboard | Home | `/` |
| Decks | Layers / Cards | `/decks` |
| Leagues | Trophy | `/leagues` |
| Settings | Gear | `/settings` |

Settings sits at the bottom of the sidebar, separated from the main nav items.

### What moves out of the sidebar

- Individual deck links — removed. All deck browsing happens on the Deck listing page.
- Format groups — removed. Format grouping lives on the Deck listing page.

---

## Page Routing Summary

| Page | Route | Notes |
|------|-------|-------|
| Dashboard | `/` | Home / landing screen |
| Deck listing | `/decks` | Full deck browser, grouped by format |
| Deck view | `/decks/{id}` | Single deck detail |
| Match view | `/matches/{id}` | Single match detail |
| Leagues listing | `/leagues` | All league runs |
| League run | `/leagues/{id}` | Single run detail (future) |
| Game replay | `/games/{id}` | Game replay viewer (future) |
| Settings | `/settings` | App configuration |

---

## Linking Conventions

- Deck names/cards throughout the app link to `/decks/{id}`
- Match rows throughout the app link to `/matches/{id}`
- League run rows link to `/leagues/{id}` (when that page exists)
- Back navigation uses browser history — no custom back button needed in most cases

---

## Active State

The sidebar highlights the currently active top-level section. If viewing `/decks/123`, the "Decks" nav item is active.
