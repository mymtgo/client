# Plan: Deck Listing Page (Fake Data)

## Context

The sidebar links to `/decks` but no route or page existed. Per `specs/deck-listing.md`, this is a full-page deck browser grouped by format, with cards showing win rate, match count, and last-played date. All data is fake until the backend is wired.

Reference spec: `specs/deck-listing.md`

---

## Files Changed

| Action | File |
|--------|------|
| Create | `app/Http/Controllers/Decks/IndexController.php` |
| Modify | `routes/web.php` |
| Create | `resources/js/Pages/decks/Index.vue` |

---

## Backend

`IndexController` is a stub — renders `decks/Index` with no props. All data lives in the Vue component as fake data constants. Route added before `{deck:id}` to avoid conflict.

---

## Frontend

- Format filter pills (All / Standard / Modern / Pioneer) — functional, filters in-memory
- Sort dropdown (Last Played / Win Rate / Match Count / Name) — functional, sorts in-memory
- Format sections with aggregate win rate in the header
- 3-col responsive deck card grid per section
- Each card: name, format badge, last-played relative time, win rate %, W-L record
- Win rate coloured green ≥ 50%, red < 50%
- Clicking a card navigates to `decks.show`
