# Deck Listing

## Purpose

A dedicated full-page browser for all recorded decks. Exists because the sidebar becomes unwieldy with many decks. This page is the primary way to browse and locate decks.

---

## Layout

Page is divided into sections by format. Within each format section, decks are shown as cards in a grid or list.

---

## Sections

### Format Groups

Decks are grouped under their format heading (e.g. Modern, Pioneer, Legacy, Standard, Pauper, etc.).

- Formats with no decks are hidden
- Formats are ordered by number of matches played (most active first) or alphabetically — TBD
- Each format heading shows an aggregate win rate for that format

### Deck Cards

Each deck is represented by a card showing:

- Deck name
- Format (redundant within section but useful if layout changes)
- Win rate % (e.g. 64%)
- Match count (e.g. 34 matches)
- Last played: relative timestamp (e.g. "Last played 4 months ago", "Last played yesterday")
- Clicking a card navigates to the Deck view

The "last played" date serves as a natural indicator of active vs. stale decks — no explicit archive section needed.

---

## Filtering & Sorting

- Filter by format (mirrors the section grouping, useful if viewing as a flat list)
- Sort options: last played, win rate, match count, name
- Default sort within each format: last played (most recent first)

---

## Navigation

Accessible via the top-level "Decks" link in the sidebar nav. There is no separate deck browser in the sidebar — all deck browsing happens on this page.

---

## Empty State

If no decks have been recorded yet, show a message explaining that decks are synced automatically from MTGO files once the watcher is running.
