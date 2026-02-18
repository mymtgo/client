# Plan: Leagues Listing Page (Fake Data)

## Context

The sidebar links to `/leagues` but no route or page existed. Per `specs/leagues-listing.md`, this page shows all league runs with KPIs at the top, a format filter, and a chronological table with pip indicators and trophy icons. All data is fake until the backend is wired.

Reference spec: `specs/leagues-listing.md`

---

## Files Changed

| Action | File |
|--------|------|
| Create | `app/Http/Controllers/Leagues/IndexController.php` |
| Modify | `routes/web.php` |
| Create | `resources/js/Pages/leagues/Index.vue` |
| Update | `resources/js/components/AppSidebar.vue` — Leagues link now uses Wayfinder |

---

## Backend

`IndexController` is a stub — renders `leagues/Index` with no props. All data lives in the Vue component.

---

## Frontend

- **KPI bar** — single card with 4 cells: Runs, 5-0 (🏆), 4-1, Win rate. Updates with format filter.
- **Format pills** — same pattern as deck listing. Filters both the KPI bar and the table.
- **Runs table** — chronological, most recent first. Columns: Date, Format, Deck, Record, Pips.
  - Trophy icon next to record for 5-0 runs
  - "In progress" badge for incomplete runs
  - Pip circles: green=W, red=L, muted=empty
  - Deck name links to deck detail via Wayfinder
