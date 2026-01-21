# Database (Conceptual)

This is a **conceptual** schema overview so agents can reason about relationships.

---

## Core Entities

### Decks

* `decks`

    * Represents an MTGO deck by stable identifier (e.g., `mtgo_id` / `NetDeckId`)
    * Soft deletable

* `deck_versions`

    * Snapshots of deck contents over time
    * Ordered by `modified_at`

Relationship:

* Deck **has many** DeckVersions

---

### Matches & Games

* `matches`

    * One MTGO match
    * References deck version used:

        * `deck_version_id`

* `games`

    * One game within a match
    * `match_id`

* `game_player`

    * Join table for players in a game
    * Includes:

        * `is_local` (true for the user)

* `players`

    * Player identity (username)

Relationships:

* Match **has many** Games
* Game **has many** Players (through `game_player`)
* Match **belongs to** DeckVersion

---

### Archetypes (optional / evolving)

* `archetypes`
* `match_archetypes`

    * Associates a player (or opponent) with an archetype for a given match

---

## Guiding Rules

* The DB is the source of truth for stats.
* File ingestion is the source of truth for what happened.
* Prefer **append-only event data** where possible; compute aggregates separately.

---

## Common Queries (conceptual)

* Deck -> versions -> matches -> games -> results
* DeckVersion -> matches (where used)
* Match -> opponent archetype (via non-local `game_player`)

---

# END
