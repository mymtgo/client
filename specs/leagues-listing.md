# Leagues Listing

## Purpose

A chronological record of all league runs. Lets the player review their history of 5-match league attempts, filtered by format, with aggregate KPIs at the top.

---

## Layout

KPI summary at the top, filter controls below, then a chronological list of league runs.

---

## KPI Bar

A row of summary stats across all runs (or filtered by the current format selection):

- Total runs completed
- 5-0 count (with trophy icon) — e.g. "🏆 5-0 (5)"
- 4-1 count — e.g. "4-1 (12)"
- Win rate across all league matches (e.g. 61%)
- Best streak or other notable stat (TBD)

KPIs update reactively when a format filter is applied.

---

## Format Filter

A filter control (tabs, dropdown, or pill buttons) to narrow the list to a specific format.

- "All formats" is the default
- Formats shown are only those with at least one league run recorded

---

## League Run List

Chronological list, most recent first.

Each run shows:

- Date of first match in the run
- Format
- Deck used (name, links to deck view)
- Final record (e.g. 4-1, 3-2, 5-0)
- Trophy icon if 5-0
- Each run links to a league run detail view (future page — see notes)

Runs that are in progress (not yet 5 matches complete) should be visually distinguishable — e.g. "In progress — 2-1, 2 remaining".

---

## Empty State

If no league runs have been recorded, show an explanation that leagues are automatically detected from MTGO match logs once the watcher is running.

---

## Navigation

Accessible via the top-level "Leagues" link in the sidebar nav.

---

## Notes

- A League run detail page (showing all 5 matches in a run with results) is a natural follow-on page. Not specced here but each run row should link to it when built.
- League grouping is inferred from match data — MTGO does not emit a clean "league started/ended" signal, so the grouping logic in BuildMatch must be relied upon.
- Runs with fewer than 5 matches (partial/abandoned) should still appear if recorded.
