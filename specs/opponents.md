# Opponents

## Purpose

A full listing of every opponent the player has faced, with their known deck history, win/loss record, and notable tags. Useful for recognising a recurring opponent and getting a sense of what they might be playing.

---

## Layout

Search bar + sort controls at the top, then a list/grid of opponent cards.

---

## Opponent Card

Each card represents one unique MTGO username.

- **Username** — prominently displayed
- **Decks seen** — archetypes observed across all matches against them (e.g. "Dimir Midrange", "Azorius Control"). If unknown, show "Unknown" in muted text.
- **Record** — W/L against this opponent (e.g. 3W – 5L)
- **Win rate %** — coloured green ≥ 50%, red < 50%
- **Last played** — relative timestamp
- **Tags** — see below
- Clicking the card opens a detail view (future page) or expands to show the match list

---

## Tags

Tags appear as badges on the card and are automatically assigned based on thresholds. Minimum match count (3) required before any tag is assigned.

| Tag | Condition | Style |
|-----|-----------|-------|
| 👹 Nemesis | Win rate < 40% vs this opponent (3+ matches) | Destructive/red badge |
| ⚔️ Rival | Win rate 40–49% (3+ matches) | Warning/amber badge |
| 🎯 Favourite Victim | Win rate > 65% (3+ matches) | Win/green badge |

Only one tag shown per opponent (most extreme takes priority).

---

## Toolbar

- **Search** — filter by opponent username (client-side)
- **Sort** — Most played (default), Win rate (asc = worst matchups first), Win rate (desc), Most recent
- **Format filter** — pill buttons to scope to a specific format (since the same opponent may play different decks in different formats)

---

## Empty State

If no matches have been recorded, show a message explaining that opponents are populated from MTGO match logs.

---

## Navigation

Add "Opponents" to the sidebar nav between Leagues and Settings.

Route: `GET /opponents` → `opponents.index`

---

## Data Considerations

- Opponent username comes from `GamePlayer` where `is_local = false`, joined through `Game → Match`
- One opponent can have multiple archetypes across different matches — show all distinct ones seen
- Records should be per-format aware (optional: toggle between all-time and per-format)
- The same player might appear as opponent across multiple formats — decide whether to merge (one card total) or split by format. Default: merge, with format breakdown visible in detail view.
- Minimum match threshold (3) for tags prevents noise from one-off encounters
