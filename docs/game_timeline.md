# Replay Mode (Frontend only) — Behaviour Spec

## Context

We have timeline rows available on the frontend for a single game.

Type:
```ts
export type GameTimelineData = {
  timestamp: string;
  content: Array<any>;
};
```

Notes:
- `timestamp` is a `"HH:MM:SS"` time-of-day string.
- `content` is the JSON snapshot payload for that event (already decoded into an array-like structure).
- The replay feature is snapshot-based: each timeline row is a frame/state to display.
- This is a **Vue 3 + Inertia** implementation only. No backend work is required.

All Replay-related Vue components created for this feature must live in:
`resources/js/Pages/games/partials`

---

## UI library guidance

Prefer Shadcn components where they make sense (buttons, sliders, dropdowns, cards), but:
- do not hard-require Shadcn to function
- components should degrade gracefully if Shadcn wrappers are unavailable (i.e. keep logic and structure independent of the UI kit)

---

## Core concepts

### Timeline events
- An “event” is a single `GameTimelineData` row.
- Events must be treated as an ordered sequence based on `timestamp`.
- If timestamps are duplicated, preserve a stable, predictable order (e.g. keep original order).

### Gaps
- There may be gaps between consecutive event timestamps.
- Replay must handle gaps differently depending on user intent:
    1) **Playback** should not force long waits during large gaps (gaps should be effectively “filled” / compressed).
    2) **Scrubbing** should ignore gaps entirely (only event timestamps matter).

---

## Required behaviours

### 1) Initial state
- Replay loads displaying the earliest event’s snapshot.
- UI clearly shows the current event timestamp.

### 2) Play / Pause
When Play starts:
- advance forward through events in timestamp order
- update the displayed snapshot as events are reached
- stop automatically at the last event

When paused:
- keep the current snapshot displayed
- scrubbing and stepping still work

### 3) Gap handling during playback (gap “filling”)
Playback must feel continuous and not “dead” during long gaps.

Desired outcome:
- small gaps may feel natural
- large gaps must not cause long waiting time

Implementation is free to choose its strategy, but behaviour must match the above.

### 4) Step controls
Provide:
- previous event
- next event

Stepping:
- moves exactly one event
- updates snapshot + timestamp immediately

### 5) Scrubbable timeline with markers
Replay must include:
- a timeline/progress control
- markers indicating each event timestamp

Scrubbing behaviour:
- scrubber selects only real events (never lands between events)
- dragging/clicking snaps to nearest event marker
- snapshot updates immediately on scrub

Scrubbing is **discrete over events**, not continuous over time.

### 6) Progress indicator
While playing:
- a playhead/progress indicator moves forward
- UI makes it clear how far through the replay you are
- markers remain meaningful reference points

It’s acceptable if the playhead is based on an internal playback-time model (rather than raw timestamp spacing) so long as it matches the gap-filling playback behaviour.

### 7) Speed control
Replay should offer:
- normal speed
- one or more faster speeds
  (optionally slower speeds)

Speed affects playback pacing but must never change event order.

---

## Robustness / edge cases

- No events: show a friendly empty state.
- One event: show snapshot; Play is disabled or no-op.
- Invalid/empty `content`: do not crash; fail gracefully (skip or warn).
- Out-of-order timestamps: reorder consistently so replay still works.
- Very large number of events: timeline remains usable (markers may be visually simplified, but scrubbing must still snap to real events).

---

## Non-goals (for now)

- Inferring specific game actions from diffs
- Perfect rendering of every MTGO zone/entity
- Editing/annotating/exporting replay data
- Any backend/database changes

---

## Acceptance criteria checklist

- [ ] Earliest event snapshot shown on load
- [ ] Play advances through events in order and stops at end
- [ ] Playback does not stall for long gaps (gaps effectively compressed)
- [ ] Prev/Next steps exactly one event
- [ ] Timeline displays markers for events
- [ ] Scrubbing snaps only to real events and skips gaps
- [ ] Timestamp + snapshot always match selected event
- [ ] Components placed in `resources/js/Pages/games/partials`
- [ ] UI prefers Shadcn components but does not depend on them to work
- [ ] Works with empty, single, and large timelines without breaking

---

Prefer the simplest architecture that satisfies these behaviours. Avoid unnecessary abstractions.
