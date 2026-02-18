# Match View

## Purpose

Full detail view for a single match. Shows how the match played out game by game, who the opponent was, what they were playing, and key in-game events.

---

## Layout

Single page with a header summary followed by per-game sections below.

---

## Header / Match Summary

- Date and time of the match
- Deck used (links to deck view)
- Format
- Overall result (Win / Loss)
- Opponent username
- Opponent archetype — displayed if classified, with an edit/tag control to manually set or correct it
- League indicator if this match was part of a league run (with link to that league run)

---

## Opponent Archetype Tagging

- If no archetype has been classified, show a prompt to tag it
- If one has been auto-classified, show it with an option to override
- Tagging is inline — no separate page required
- Archetype applies to the match, not individual games

---

## Game-by-Game Breakdown

One section per game in the match (Game 1, Game 2, Game 3 if applicable).

Each game section shows:

- Game number
- Result (Win / Loss)
- Who played first (on the play / on the draw)
- Duration (if available from log data)
- Mulligan count for each player (if available)

### Cards Seen From Opponent

Within each game (or aggregated across the match), show opponent cards that were logged:

- Card name
- Number of times seen
- Grouped or listed simply — not a full decklist reconstruction

### Key Events / Timeline

A condensed timeline of notable in-game moments for each game:

- Mulligans
- Notable plays (if captured in log data)
- Turn count at game end
- Game result marker

The timeline should be readable as a narrative of how the game went, not an exhaustive log dump.

---

## Navigation

- Reached from: Dashboard recent matches list, Deck view match history, League run match list
- Back navigation returns to the referring context (deck, league, or dashboard)
- Links out to: Deck view, League run view, Game replay (if implemented)

---

## Data Considerations

- Match result may not always be deterministic from logs — handle unknown/incomplete results gracefully
- "Who played first" is logged per game, not per match
- Opponent cards seen come from GameDeckCard records on the opponent's GameDeck
- Not all games will have complete timeline data — show what's available, omit what isn't
- A match can have 1–3 games (best of 3); render only the games that exist
