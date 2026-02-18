# Deck View

## Purpose

Deep dive into a single deck. Shows the current decklist alongside performance stats, match history, and matchup data.

---

## Layout

Side-by-side layout: decklist panel on one side, stats and history on the other. Both are visible simultaneously without needing to switch tabs for the primary content.

On narrower viewports the layout collapses to stacked sections.

---

## Decklist Panel

Displays the most recent version of the deck.

- Maindeck cards grouped by type (Creatures, Instants/Sorceries, Enchantments, Artifacts, Planeswalkers, Lands)
- Sideboard shown below maindeck
- Card quantities displayed
- Mana curve visualisation (bar chart by CMC) — optional, secondary
- If the deck has multiple versions, show a version history selector (date of each version)

---

## Stats Panel

### Header Stats

- Deck name
- Format
- Overall win rate % with match count (e.g. 64% — 38 matches)
- Last played date

### Win Rate Over Time

A chart showing how the deck's win rate has trended across its match history. Helps identify if the deck is improving, declining, or consistent.

### Matchup Spread

A breakdown of results against known opponent archetypes:

- Archetype name
- W-L record against that archetype
- Win rate %
- Sorted by number of matches (most played matchups first)

### Match History

A list of all matches played with this deck:

- Date
- Opponent archetype (if known/tagged)
- Result (W/L)
- Format (relevant if deck has been used across formats — rare but possible)
- Each row links to the match view

### League History

A list of all league runs using this deck:

- Run date
- Final record (e.g. 4-1, 3-2, 5-0)
- Trophy icon for 5-0
- Each row links to the league run

---

## Navigation

Reached by clicking a deck card on the Deck listing page. Also reachable from match/league views that reference this deck.

---

## Data Considerations

- The "current" decklist is the most recent DeckVersion by timestamp
- Match history must be filtered to games where this deck was used (via GameDeck relationship)
- Version history should show when the list changed, not just when it was played
