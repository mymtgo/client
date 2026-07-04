# The `{match}.json` Contract

> Extracted from the v1 architecture brainstorm (2026-06-30). The shared artifact: the **client compiler** ([`../client-agent/spec.md`](../client-agent/spec.md)) produces it; the **build worker** ([`../cloud-pipeline/spec.md`](../cloud-pipeline/spec.md)) consumes it. Lock this shape first — both sides depend on it.

Carries everything the worker needs to build a full match **and** re-derive it later. Shaped to the **1v1 target** (so the worker writes the clean schema directly). Whole-file replace on each push. Envelope + payload:

```jsonc
{
  // envelope
  "schema_version": 1,
  "client_version": "1.0.0",
  "source": "mtgo",                // 'mtgo' | 'arena' — multi-source discriminator; identity keys are scoped by source (see Arena readiness below). v1 ships MTGO only.
  "match_key": "<MatchToken uuid>", // = match.token. Per-match MatchToken uuid, verified across full lifecycle (join → every game event → ...ClosedState). NOT league token (per-season, repeats); NOT Match Id (int).
  "compiled_at": "<iso8601>",
  "file_version": 7,               // monotonic; server processes the version it read, re-churns on change
  "imported": false,               // true for 0.x backfill imports

  // owner identity (resolved locally; never the editable kind)
  "mtgo_username": "<local player handle>",
  "mtgo_player_id": "<local player numeric id — from PlayerIds; stable, disambiguates self>",

  "match": {
    "token": "<MatchToken uuid>",   // == match_key (per-match identity)
    "mtgo_id": "<MatchID int>",     // attribute only — NOT the key
    "format": "CModern",
    "match_type": "League",
    "outcome": "Win|Loss|Draw|Unknown",
    "outcome_source": "resolved|manual|unknown", // manual = user-set in needs-attention UI; baked in so re-derivation preserves it
    "state": "Complete|Ended|InProgress|...",
    "started_at": "<iso8601>",
    "ended_at": "<iso8601|null>",
    "notes": null,

    "opponent": { "mtgo_player_id": "<int>", "username": "<handle|null>" },  // keyed on player id (rename-proof); username is display only

    "deck": {                       // local player's deck, from MTGO XML (inline → cloud owns versioning)
      "mtgo_id": "<NetDeckId>",
      "name": "...", "format": "CModern", "color_identity": "UBR",
      "modified_at": "<iso8601>",   // version source — cloud dedupes/never-regresses
      "signature": "<base64 cardlist>"
    },

    "league":     { "token": "<per-season uuid — repeats, grouping only, NOT match_key>", "name": "...", "format": "...", "joined_at": "...", "dropped_at": null } /* || null */,
    "tournament": { "mtgo_event_id": 123, "round": 4, "name": "..." } /* || null */,

    "games": [
      {
        "mtgo_id": "<GameID>",
        "won": true,                // null = unknown/abandoned
        "started_at": "...", "ended_at": "...",
        "turn_count": 9,
        "local_on_play": true,
        "local_mulligans": 1, "opp_mulligans": 0,
        "local_dice": 6, "opp_dice": 3,
        "local_instance": 111, "opp_instance": 222,
        "local_deck":    { "signature": "<base64>" },   // → game_decks (is_opponent=false)
        "opponent_deck": { "signature": "<base64>" },   // → game_decks (is_opponent=true)
        "card_stats": [
          { "oracle_id": "...", "opponent": false, "quantity": 4, "kept": 1, "seen": 2,
            "played": 1, "won": true, "is_postboard": false, "sided_out": false,
            "pregame_revealed": false, "pregame_played": false,
            "kicked": 0, "flashback": 0, "madness": 0, "evoked": 0, "activated": 0 }
        ],
        "timeline": [ { "action": "...", "timestamp": "...", "player": "...", "context": "..." } ]
      }
    ],

    "opponent_archetype": { "uuid": "...", "name": "...", "confidence": 0.82 } /* || null — local-live guess; worker re-derives authoritatively */
  }
}
```

**Sparse variant (0.x import):** `games: []` (or games without `card_stats`/`timeline`) is valid; the worker builds a match-only record. `imported: true` flags these. See [`../migration/spec.md`](../migration/spec.md).

## Notes

- `cast` (current 0.x schema) is dropped — it duplicates `played`.
- `starting_hand_size` (current `game_player`, always-7 bug) is dropped in favour of `local_mulligans`/`opp_mulligans`; hand size is derived (`7 − mulligans`).
- The deck `signature` is the existing base64 cardlist format, carried verbatim.
- **`timeline[]` is decoded from the `MetaMessage` byte-array** carried in each `GsMessageMessage` log line — it holds the game action/chat stream (turns, plays, rolls, mulligans, etc.). The **client compiler** must parse/convert MetaMessage → structured timeline events; it is the primary timeline source. (Compilation-layer work → covered by the keep-forever raw archive if the decoder has bugs.)
- **`MetaMessage` is per-perspective** — verified: both players in a match log the *same* MatchToken / MatchID / GameID / PlayerIds but *different* MetaMessage bytes (each is the local player's private view). Reinforces raw-stays-device-local; and future cross-user stitching **merges** two perspectives rather than deduping (deferred — read-side join on `match_key`, no write-path change).

## Identity rules (load-bearing)

- **`match_key` = MatchToken (uuid).** Verified against real logs (`storage/app/mtgo.log`, one league season): 6 distinct Match Tokens vs 1 League Token. Present at join → every game event → terminal `...ClosedState`. Format-agnostic.
- The **same MatchToken is shared by both players** (it is the MTGO server's real match id) → globally deterministic, but per-account records must be scoped `UNIQUE(user_id, match_key)`, never globally unique.
- `mtgo_id` (MatchID int) is an attribute, not the key. `league_token` groups league runs (repeats per season), never a key.
- `opponent.mtgo_player_id` is the stable, rename-proof key for opponents; `username` is display only.
- **Confirmed against the live 0.x DB:** `matches.token` stores the MatchToken uuid → `match_key` maps directly, no fallback. But 0.x has **no `mtgo_player_id`** (neither local nor opponent) — imported matches emit `mtgo_player_id: null`. So on the cloud side, `opponents.mtgo_player_id` must be **nullable** with a **partial unique index** (`WHERE mtgo_player_id IS NOT NULL`) + a username fallback for imports. Same for the envelope `mtgo_player_id` on imports.

## Arena readiness (multi-source seam)

v1 ships MTGO only, but the contract carries a `source` discriminator so a future Arena source is a **union, not a rewrite** — the cloud (sink/worker/schema/API) is source-agnostic; only the ingest *agent* is platform-specific.

- **`source` is part of match identity.** Keys scope by it: `UNIQUE(user_id, source, match_key)` on `matches`/`match_files`; the sink object path includes source. Adding this now is one column; retrofitting it later is a data migration.
- **Card identity is `oracle_id`** (card stats already key on it) — the common denominator across clients. Scryfall carries both `mtgo_id` *and* `arena_id`, so an Arena catalog mapping is additive.
- **`games[]` + ≥1-game validity** already handle Arena's Bo1.
- **Deferred to v2 (accept as additive later):** the Arena ingest agent (different log format; Arena is cross-platform, breaking MTGO's Windows-only assumption) and any `mtgo_*` → generic (`platform_player_id`, `game_account`) rename — better built against real Arena logs than guessed now.
