# Known MTGO Lies & Traps

This document lists **false assumptions** and **dangerous patterns** commonly
encountered when working with MTGO logs.

These are not bugs in this app — they are properties of MTGO itself.

Any ingestion, parsing, or match logic must assume these behaviours are normal.

---

## 1) “Match Ended” Does Not Mean Match Ended

MTGO may:
- Emit a match-end style message
- Close the game client
- Stop logging abruptly

Without:
- A clean game-end event
- A clean match-complete event
- A final winner declaration

**Reality:**  
A match may appear to end without any canonical “end” signal.

**Rule:**  
Match completion must be inferred, not trusted.

---

## 2) “Game Over” ≠ Winner Known

Games can end via:
- Client crash
- Disconnect
- Window close
- Network loss
- MTGO soft crash

Often without:
- Life total = 0
- Concede event
- Winner event

**Reality:**  
Game termination ≠ winner determination.

**Rule:**  
Game result may be:
- win
- loss
- unknown
- inferred
- abandoned

The system must allow **unknown outcomes**.

---

## 3) Logs Lie About Order

Log order is not guaranteed to represent real-time order:

- Buffered writes
- Delayed flush
- Batched JSON writes
- Interleaved meta events
- Context switching

**Reality:**  
Timestamp ordering is unreliable.

**Rule:**  
Never rely on strict line order alone for causality.

---

## 4) Duplicate Events Are Normal

MTGO may emit:
- Duplicate state updates
- Repeated headers
- Replayed status messages
- Re-sent JSON blocks

**Reality:**  
Duplicates are not errors.

**Rule:**  
Event processing must be idempotent.

---

## 5) MetaMessage JSON Is Noise

Many JSON blocks are:
- UI telemetry
- Twitch info
- client metadata
- analytics
- irrelevant system messages

**Reality:**  
Not all JSON is gameplay.

**Rule:**  
Explicitly whitelist meaningful JSON types.  
Default posture = ignore unless proven relevant.

---

## 6) Context Can Switch Mid-Stream

MTGO logs may:
- Jump between matches
- Interleave league data
- Emit stale match headers
- Re-reference old matches

**Reality:**  
The log is not strictly sequential per match.

**Rule:**  
Use **explicit match identifiers**, not proximity.

---

## 7) “Local Player” Is Not Always Explicit

Sometimes the local player:
- Is only known from settings (`Mtgo::getUsername()`)
- Is not present in early match headers
- Appears only in later events

**Reality:**  
Player identity may be late-bound.

**Rule:**  
Local identity is authoritative; logs are incomplete.

---

## 8) Deck State Can Drift

Deck files may:
- Update mid-session
- Be overwritten
- Be duplicated across directories
- Disappear temporarily
- Reappear later

**Reality:**  
Deck files are not stable.

**Rule:**  
Always use:
- latest timestamp
- versioning
- never assume persistence

---

## 9) Cursor Lies (File System Lies)

File systems may:
- Rotate files
- Truncate files
- Replace files in-place
- Change inode with same filename
- Reset file size

**Reality:**  
A filename is not a file identity.

**Rule:**  
Cursor logic must handle:
- shrink detection
- rotation
- replacement
- reset safely

---

## 10) “No More Logs” ≠ Match Finished

Silence in logs may mean:
- MTGO froze
- MTGO crashed
- MTGO paused logging
- User alt-tabbed
- Client closed uncleanly

**Reality:**  
Silence is not state.

**Rule:**  
Absence of evidence is not evidence of completion.

---

## 11) MTGO Is Not Deterministic

Same actions can produce different log shapes across sessions.

**Reality:**  
MTGO logging is not a stable API.

**Rule:**  
Parsing must be heuristic, tolerant, and defensive.

---

## 12) Never Assume Clean Boundaries

There is no guarantee of:
- clean match start
- clean game start
- clean game end
- clean match end

**Reality:**  
Boundaries are fuzzy.

**Rule:**  
Everything is inferred state, not declared truth.

---

# Prime Directive

> **Logs are hostile input.**
> Trust structure, not semantics.
> Trust invariants, not messages.
> Trust pipelines, not appearances.

---

# Agent Contract

Any AI agent working on this codebase must:

- Assume malformed input
- Assume partial state
- Assume missing events
- Assume duplicate events
- Assume reprocessing
- Assume corrupted sequences

If logic requires “clean MTGO behaviour” to function,  
**the logic is wrong.**
