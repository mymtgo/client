# Dashboard

## Purpose

Personal MTGO snapshot. The home screen gives the player an at-a-glance read of where they're at: active league progress, how their formats are trending, and which decks are flying or struggling.

---

## Layout

Single scrollable page divided into distinct sections. No tabs.

---

## Sections

### 1. Active League (or Last Run)

**If a league is currently in progress:**
- Prominently show the active run at the top
- Display current record (e.g. 2-1) and matches remaining (e.g. 2 to go)
- Show the deck being played in the run
- Link to the league run detail

**If no league is active:**
- Fall back to showing the most recently completed league run
- Show final record, deck used, format, and date completed
- Trophy icon if it was a 5-0

---

### 2. Format Performance Over Time

A chart showing win rate trends per format over time.

- X axis: time (rolling months or match dates)
- Y axis: win rate %
- One line/series per format played (Modern, Pioneer, Legacy, etc.)
- Formats with very few matches can be omitted or shown as dots rather than a line
- Should give a clear sense of which formats are improving or declining

---

### 3. Best & Worst Performing Decks

Two side-by-side cards:

**Best Performing Deck**
- Deck name
- Format
- Win rate % (with match count as context, e.g. "68% — 22 matches")
- Link to deck view

**Worst Performing Deck**
- Same structure
- Only shown if there's enough data (e.g. minimum 5 matches played)

Both cards use a minimum match threshold to exclude outliers (decks played once or twice).

---

### 4. Recent Matches

A compact list of the most recent matches played (last 5–10).

- Deck used
- Opponent archetype (if known)
- Result (W/L)
- Format
- Date/time
- Each row links to the match view

---

## Data Considerations

- League "active" state is inferred from the match/log data — no explicit "start league" action exists
- Win rate calculations should respect minimum match thresholds to be meaningful
- Format performance chart may be sparse for new installs; show an empty/placeholder state gracefully
