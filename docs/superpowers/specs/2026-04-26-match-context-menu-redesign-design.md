# Match Row Context Menu Redesign

**Date:** 2026-04-26
**Status:** Approved design — ready for implementation plan

## Problem

Current right-click context menu on match rows (`resources/js/components/matches/MatchesTable.vue:181-267`) is a flat list with no grouping or visual hierarchy:

- Add notes
- Detect archetype
- Set manual archetype
- Clear archetype
- Remove from stats

Setting an archetype manually requires a two-step interaction: click "Set manual archetype" → modal dialog opens → search → select. The dialog interrupts flow for the most common case (single match).

## Goal

Inline archetype selection inside the context menu via a nested submenu containing search + scrollable archetype list. No dialog round-trip for single-row archetype assignment. Visual structure with grouping. Bulk selection (multi-row) keeps the existing dialog.

## Design

### Top-level menu structure

```
┌──────────────────────────────┐
│ Add notes / Edit notes       │
│ ──────────────────────────── │
│ ARCHETYPE          (label)   │
│ Detect                       │
│ Set manually          ▸      │──→ submenu
│ Clear  (disabled if ∅)       │
│ ──────────────────────────── │
│ Remove from stats            │  destructive (red)
└──────────────────────────────┘
```

- "Archetype" is a `ContextMenuLabel` (group heading), not a submenu trigger.
- Items appear flat below the label.
- "Set manually" is the only item with a submenu (chevron right).
- "Clear" is rendered always but `disabled` when no archetype is set, so menu shape stays stable.
- "Remove from stats" uses destructive styling (`text-destructive` / red text).

### Submenu (Set manually) layout

```
┌────────────────────────────────────┐
│ 🔍 Search archetypes...            │  sticky top
├────────────────────────────────────┤
│ Homebrew                       ✓   │  pinned fallbacks
│ Rogue                              │  (always visible,
│                                    │   not filtered by search)
├────────────────────────────────────┤  border separator
│ 4c Scapeshift             ⛰💧🔴   │  scrollable region
│ Asmo Food                 ⚪🟢     │  filtered by search
│ Boros Midrange            ⚪🔴     │
│ ...                                │
└────────────────────────────────────┘
```

- Width: ~280px.
- Search input sticky at top (always visible while scrolling).
- Fallback archetypes (`isFallback === true`, e.g. Homebrew, Rogue) pinned below search, **never filtered** by search query, never scrolled out of view.
- Border separator between pinned fallbacks and scrollable list.
- Regular archetypes scrollable, max-height ~320px, filtered by search (case-insensitive name match).
- Each row: archetype name + mana symbols (right-aligned). No match counts.
- Currently-set archetype shows ✓ check icon (left of name) when `archetype.id === match.opponentArchetypes?.[0]?.archetype?.id`.
- Empty state when search yields zero regular archetypes: "No archetypes found." (fallbacks still visible above).

## Components

### New: `MatchRowContextMenu.vue`

Path: `resources/js/components/matches/MatchRowContextMenu.vue`

Extracted from `MatchesTable.vue`. Wraps the row content with `ContextMenuTrigger` and renders all menu items.

**Props:**
- `match: App.Data.Front.MatchData` — the match row.
- `archetypes: App.Data.Front.ArchetypeData[]` — full archetype list (already format-filterable).

**Emits:**
- `detect: [matchId: number]`
- `clear: [matchId: number]`
- `delete: [matchId: number]`
- `openNotes: [matchId: number, notes: string | null]`

**Slot:**
- `default` — row content (table cells). Wrapped in `ContextMenuTrigger`.

Selecting an archetype in the submenu submits directly via `UpdateArchetypeController` (Wayfinder) and closes the menu. Component owns this submission internally — no parent emit needed for the inline path.

### New: `ArchetypePicker.vue`

Path: `resources/js/components/matches/ArchetypePicker.vue`

The submenu content. Renders sticky search, pinned fallbacks, scrollable filtered list. Used inside `ContextMenuSubContent`. Reusable: future refactor could replace dialog body with this.

**Props:**
- `archetypes: App.Data.Front.ArchetypeData[]`
- `format: string | null` — for format filtering.
- `currentArchetypeId: number | null` — for ✓ indicator.
- `disabled?: boolean` — disables interaction during submit.

**Emits:**
- `select: [archetypeId: number]`

**Implementation:**
- Uses shadcn `Command` / `CommandInput` / `CommandList` / `CommandItem` / `CommandEmpty` for the **scrollable filtered section only**. cmdk-vue handles search + arrow-key navigation + enter-to-select.
- Pinned fallbacks rendered as a static `<div>` block above `<CommandList>`, **outside** Command's filter scope. Each fallback is a button with same styling as command items but unfiltered.
- Search input is `CommandInput`, sticky via flex layout (input fixed height, list `overflow-y-auto` with max-height).
- Reka-ui's nested `ContextMenuSubContent` keyboard nav defers to focused input's keydown; cmdk arrow handlers take precedence while input has focus.

### New: `useArchetypeSplit` composable

Path: `resources/js/composables/useArchetypeSplit.ts`

Extracts the format/fallback split logic currently duplicated in `SetArchetypeDialog.vue:35-63`. Returns `{ fallbacks, regular, filterRegular(query) }`.

Consumed by `ArchetypePicker.vue` and `SetArchetypeDialog.vue` (refactor dialog to use the composable in the same change to avoid drift).

## Data flow

Existing prop chain unchanged:
`MatchesController` → `DeckMatches.vue` (prop `archetypes`) → `MatchesTable.vue` → `MatchRowContextMenu` → `ArchetypePicker`.

No new backend work. No new endpoints. Reuses:
- `UpdateArchetypeController` (single match — submenu select).
- `BulkUpdateArchetypeController` (untouched — dialog only).
- `detectArchetype` / `clearArchetype` / `deleteMatch` handlers in `MatchesTable.vue` (passed down or kept in parent via emits).

## Behavior details

- Submenu opens on hover over "Set manually" (reka-ui default).
- Search input auto-focuses when submenu opens (reka-ui `ContextMenuSubContent` opens with content focus; explicit `focus()` on `CommandInput` ref via `onMounted` if needed).
- Selecting an archetype: submits, closes entire context menu (root), shows existing toast/inertia flash.
- Esc closes submenu only; second Esc closes root menu (reka-ui default).
- During submission, all picker rows disabled to prevent double-submit.
- Submenu auto-flips position when near viewport edge (reka-ui handles).

## Bulk path (unchanged)

`SetArchetypeDialog.vue` retained for bulk multi-select operations triggered from row selection toolbar. The dialog refactor uses the new `useArchetypeSplit` composable for consistency but keeps its existing UI and behavior.

## Edge cases

- **Zero archetypes loaded**: submenu shows fallbacks only (Homebrew, Rogue). Scrollable section shows `CommandEmpty`.
- **Search matches nothing in regular**: `CommandEmpty` renders. Fallbacks still visible.
- **Match already has fallback assigned**: ✓ next to that fallback in the pinned section.
- **No archetype set on match**: "Clear" item disabled. No ✓ anywhere.
- **Submenu near viewport bottom/right**: reka-ui flip auto.
- **Format mismatch**: `useArchetypeSplit` filters regular archetypes by format the same way `SetArchetypeDialog.vue` does (`formatMap` lookup). Fallbacks pass format check unconditionally.

## Testing

- **Browser test** (Pest 4, `tests/Browser/MatchContextMenuTest.php` — new):
  - Right-click match row → context menu opens.
  - Hover "Set manually" → submenu opens with search + list.
  - Type query → list filters, fallbacks remain.
  - Click archetype → archetype set, menu closes, row updates.
  - Right-click row with archetype set → "Clear" enabled.
  - Right-click row without archetype → "Clear" disabled.
- **Unit/feature tests for `UpdateArchetypeController`**: untouched, already pass.
- **`SetArchetypeDialog` tests** (if any): adjusted to account for `useArchetypeSplit` composable extraction; behavior identical.

## Out of scope

- Bulk archetype selection redesign.
- Replacing the dialog entirely.
- Match count badges in the picker.
- Archetype icons beyond mana symbols.
- Keyboard shortcut hints in menu items.

## Files touched

**New:**
- `resources/js/components/matches/MatchRowContextMenu.vue`
- `resources/js/components/matches/ArchetypePicker.vue`
- `resources/js/composables/useArchetypeSplit.ts`
- `tests/Browser/MatchContextMenuTest.php`

**Modified:**
- `resources/js/components/matches/MatchesTable.vue` — replace inline `ContextMenu` (lines 181-267) with `<MatchRowContextMenu>` wrapper around row content.
- `resources/js/components/matches/SetArchetypeDialog.vue` — adopt `useArchetypeSplit` composable (no UI change).

**Untouched:**
- `app/Http/Controllers/Matches/UpdateArchetypeController.php`
- `app/Http/Controllers/Matches/BulkUpdateArchetypeController.php`
- `app/Http/Controllers/Decks/MatchesController.php`
- All archetype data DTOs and types.
