# UI Audit — mymtgo Desktop Application

**Date**: 2026-02-27
**Scope**: Full frontend audit of the NativePHP (Electron) desktop app for MTGO deck tracking
**Focus**: Desktop-native UX, visual identity, information density, and design coherence

---

## Executive Summary

The app has a **solid functional foundation** — clean component architecture (166 shadcn-vue components), proper dark mode support, well-structured pages with correct data flow, and sensible use of Inertia.js for routing. The codebase is organized and maintainable.

However, the UI currently reads as a **generic web dashboard transplanted into an Electron shell**. It lacks visual identity tied to its MTG domain, underutilizes desktop-app conventions, and has spacing/density tuned for browser viewports rather than a native desktop window. The improvements below are grouped by impact and effort.

---

## 1. Visual Identity — "It could be any app"

### Current State
- **Color palette**: Pure achromatic grayscale (OKLCH with 0 chroma across every token). The only color in the entire app comes from `destructive` (red), `success` (green), `yellow-400` (trophy icon), and chart colors. There is no brand color, no accent hue, nothing that says "Magic: The Gathering."
- **Typography**: System font stack (`ui-sans-serif, system-ui`). Functional but anonymous — identical to every other shadcn scaffold.
- **Logo/branding**: The sidebar header is just the text "mymtgo" in `text-base font-semibold`. No icon, no mark, no personality.

### Recommendations

| Area | Suggestion | Effort |
|------|-----------|--------|
| **Brand accent color** | Introduce a single accent hue (even a desaturated blue, teal, or amber) used for primary buttons, active sidebar items, and key stat highlights. The current `--primary` is just black/white — this makes every interactive element feel invisible. | Low |
| **Typography upgrade** | Swap the body font to something with more character. Consider a slightly geometric sans (e.g., Geist, Satoshi, General Sans) for UI text and a monospace/tabular font for numbers. MTG stat tracking benefits from a font that handles numbers elegantly. | Low |
| **Logo/wordmark** | Design a small mark or icon for the sidebar header. Even a simple SVG glyph (a planeswalker silhouette, a mana symbol, a stylized "M") would anchor the identity. | Low |
| **Mana colors as design language** | The app already renders mana symbols (`ManaSymbols.vue`). Lean into WUBRG (White/Blue/Black/Red/Green) as a secondary color system for format badges, archetype pills, and color identity indicators. This instantly reads as "Magic." | Medium |
| **Noise/texture** | `noise-bg` and `bevel` classes exist but are barely used. The `bevel` effect on ResultBadge pips is a nice micro-detail — extend this treatment to key interactive surfaces (primary CTA buttons, the active league card). | Low |

---

## 2. Desktop-Native Conventions — "This feels like a website"

### Current State
The app uses a standard web layout: collapsible sidebar + scrolling content area + 12px header. No titlebar integration, no status bar, no keyboard shortcuts surfaced in the UI, no drag regions, no persistent panels.

### Recommendations

| Area | Suggestion | Effort |
|------|-----------|--------|
| **Titlebar integration** | NativePHP/Electron supports custom titlebars. Move the sidebar header and app title into a proper draggable titlebar region with traffic lights (macOS) or window controls (Windows). This immediately signals "native app." | Medium |
| **Status bar** | Add a persistent bottom bar (24-28px) showing: watcher status (green dot + "Watching" / red dot + "Stopped"), last ingestion time, current log cursor position, pending match count. This is information the user cares about constantly — it shouldn't require navigating to Settings. | Medium |
| **Keyboard shortcuts** | Surface shortcuts in the UI: `Cmd+1`–`Cmd+4` for nav items, `Cmd+,` for settings, `Cmd+F` for search. Show hints in tooltips on sidebar items. Desktop users expect this. | Medium |
| **Command palette** | Add a `Cmd+K` command palette for quick navigation: jump to deck by name, search opponents, navigate to recent matches. This is the single highest-leverage desktop UX feature for power users. | High |
| **Information density** | Current padding is web-generous (`p-4 lg:p-6`, `gap-6`). Desktop apps can afford tighter spacing since users are close to the screen. Consider reducing outer padding to `p-3 lg:p-4` and table row heights by ~20%. | Low |
| **Sidebar width** | 16rem (256px) is reasonable, but consider a narrower icon-only mode that persists across sessions. The current `collapsible="offcanvas"` is mobile-focused — on desktop, an icon-collapse mode (already supported by the component) would save horizontal space. | Low |
| **Window size memory** | Ensure Electron remembers window size/position across launches (NativePHP may handle this, but verify). | Low |

---

## 3. Dashboard — "Too much empty space, too few insights"

### Current State
The dashboard (`pages/Index.vue`) shows: timeframe selector, active league card, format chart, best/worst deck cards, and a recent matches table. The layout is a single column of stacked sections with `gap-6`.

### Issues
- **No headline stats**: There's no at-a-glance KPI row (overall win rate, total matches, current streak). The `matchesWon`/`matchesLost`/`matchWinrate`/`gameWinrate` props exist but aren't rendered — they're passed but unused.
- **Single-column stacking**: Wastes horizontal space on wide desktop windows. The best/worst deck cards use a 2-column grid, but the league card and format chart are full-width even when they don't need to be.
- **Timeframe selector**: Unstyled `border p-1` container with no rounded corners. Looks like a prototype element.
- **"View all" on recent matches**: This button has `variant="ghost"` and is styled as muted text — easy to miss. It also doesn't appear to link anywhere.

### Recommendations

| Area | Suggestion | Effort |
|------|-----------|--------|
| **KPI row** | Add a 4-column stat bar at the top (like the Leagues page already has): Match Win Rate, Game Win Rate, Total Matches, Current Streak. The data props already exist. | Low |
| **Timeframe selector polish** | Add `rounded-md` to the container. Consider using a proper `TabsList` from shadcn for visual consistency. | Low |
| **2-column layout** | On `lg+`, use a 2-column grid: left column for league + format chart, right column for best/worst decks. This reduces scrolling and uses horizontal space. | Medium |
| **Sparklines** | Add tiny win-rate sparklines to the best/worst deck cards. Even a simple 7-day trend line (SVG, ~30px tall) adds significant information density. | Medium |
| **Streak indicator** | Show current win/loss streak somewhere prominent — this is the #1 emotional stat for competitive players. | Low |

---

## 4. Decks Page — Good, Needs Polish

### Current State
Well-structured: format pills, sort dropdown, grouped deck cards in a responsive grid. Empty state exists.

### Issues
- **Deck cards lack visual weight**: Cards are flat white with hover state. No thumbnail, no color coding, no mini-chart. When you have 10+ decks, they all blur together.
- **No search**: Unlike the Opponents page, there's no search input for decks by name.
- **Format section headers**: The divider line (`border-t border-border`) extends with `flex-1` which is clever, but the section header is `text-sm` — too subtle for a grouping header.

### Recommendations

| Area | Suggestion | Effort |
|------|-----------|--------|
| **Color-coded format stripe** | Add a thin left border (3-4px) to each deck card in the format's color. Assign each format a distinct hue (Standard = blue, Modern = purple, Legacy = gold, etc.). | Low |
| **Mini win-rate bar** | Add a thin horizontal bar (4px tall, full card width) at the bottom of each card: green portion = win%, red = loss%. Visual at a glance. | Low |
| **Search input** | Add a search field to the toolbar, consistent with the Opponents page. | Low |
| **Last match result pips** | Show the last 5 match results as colored dots on each card (like the league pip pattern already exists). Reuse `ResultBadge`. | Low |

---

## 5. Deck Detail Page — Dense but Disoriented

### Current State
Header with deck name + selectors, then nested tabs (Stats > [Matches / Matchups / Leagues] and Decklist). Stats row is a `divide-x` card.

### Issues
- **Nested tabs are confusing**: There's a top-level `<Tabs>` (Stats / Decklist) and then Stats contains another `<Tabs>` (Matches / Matchups / Leagues). This double-nesting is disorienting — the user can lose track of which tab level they're interacting with.
- **Stats card has no vertical padding**: `CardContent class="flex divide-x p-0"` — the stat cells have `px-4` but no `py-3`, so the numbers feel cramped against the card edges.
- **Selectors are crowded**: Period selector and version selector are both in the header's right side without clear visual grouping.

### Recommendations

| Area | Suggestion | Effort |
|------|-----------|--------|
| **Flatten tab structure** | Merge into a single tab bar: Overview, Matches, Matchups, Leagues, Decklist. Move the stats card into the Overview tab. This eliminates the nesting confusion. | Medium |
| **Stats card padding** | Add `py-3` to the stat cells. | Trivial |
| **Selector grouping** | Wrap the two selectors in a `flex gap-2` container with a subtle separator or label between them. | Low |
| **Sticky header** | Pin the deck name + tab bar to the top on scroll. In a desktop window with lots of match data, the header scrolls away quickly. | Medium |

---

## 6. Leagues Page — Strongest Page

### Current State
Best-designed page in the app. KPI bar, format pills, phantom filter, league run cards with nested match tables. Good information hierarchy.

### Issues
- **KPI bar doesn't filter with phantom**: The KPI bar filters out phantom runs, which is correct, but this isn't communicated. If the user sets "Only phantom," the KPI bar shows 0 for everything with no explanation.
- **League cards are vertically expensive**: Each card has a header bar + full match table. With 20+ league runs, this is a lot of scrolling.

### Recommendations

| Area | Suggestion | Effort |
|------|-----------|--------|
| **KPI context label** | Add a small "Excludes phantom events" note under the KPI bar, or dynamically update KPIs based on the phantom filter. | Low |
| **Collapsible league cards** | Default league match tables to collapsed, with a click-to-expand interaction. Show just the header bar (date, format, deck, record, pips) by default. | Medium |
| **Pagination or virtualization** | For users with 50+ league runs, consider pagination or virtual scrolling. | Medium |

---

## 7. Opponents Page — Functional, Needs Personality

### Current State
Search + sort + format pills toolbar, then a data table with opponent names, archetypes, W/L, win rate, last played.

### Issues
- **Nemesis/Rival/Favourite Victim badges are fun but underexplored**: These tags are the most personality-rich feature in the app. They deserve more visual treatment — maybe an icon, a background color, or a tooltip explaining the threshold.
- **No link to opponent history**: Clicking a row doesn't navigate anywhere. There's no opponent detail page.
- **Archetype pills are too subtle**: The `border px-2 py-0.5` mana symbol pills are nearly invisible at a glance.

### Recommendations

| Area | Suggestion | Effort |
|------|-----------|--------|
| **Badge styling** | Give Nemesis a skull icon, Rival a crossed-swords icon, Favourite Victim a target icon. Add tooltips explaining the criteria ("You've won <40% of 3+ matches"). | Low |
| **Row click → match history filter** | Make rows clickable to filter recent matches by opponent. Even without a dedicated page, linking to the dashboard or match list filtered by opponent adds utility. | Medium |
| **Archetype pill backgrounds** | Give the mana-symbol pills a very faint background tint based on the dominant color (e.g., faint blue for U-heavy archetypes). | Low |

---

## 8. Settings Page — Web-Form, Not Desktop-Preferences

### Current State
Vertical sections separated by `divide-y` within `max-w-2xl`. File paths, watcher controls, display settings, privacy.

### Issues
- **No rounded status indicators**: Path status uses a square `div class="size-2"` — should be `rounded-full` for consistency with the ResultBadge dot pattern.
- **No file picker**: Users have to type/paste file paths. Desktop apps should use a native file picker dialog (Electron's `dialog.showOpenDialog`).
- **Actions lack feedback**: "Run now" buttons show a spinner, but there's no success toast or completion message.

### Recommendations

| Area | Suggestion | Effort |
|------|-----------|--------|
| **Native file picker** | Replace manual path inputs with "Browse..." buttons that invoke Electron's native directory picker via NativePHP. Keep the text input for manual override. | Medium |
| **Success toasts** | Add a toast/notification system for action completion ("Ingestion complete — 42 events processed"). shadcn has a toast component. | Medium |
| **Status dot rounding** | Add `rounded-full` to the path status indicators. | Trivial |
| **Section navigation** | Add a mini sidebar or anchor links for settings sections. As more settings are added, scrolling to find a section will become tedious. | Low |

---

## 9. Match Detail Page — Well-Structured

### Current State
Summary card with result badge, opponent info, archetype (with edit), deck link, timestamp. Then per-game sections.

### Issues
- **Match time format**: Shows raw timestamp format. Consider "2h 15m ago" + absolute on hover.
- **Game sections could be collapsible**: Especially for 3-game matches where the user only cares about a specific game.

### Recommendations

| Area | Suggestion | Effort |
|------|-----------|--------|
| **Time formatting** | Use relative time as primary, absolute on tooltip hover. | Low |
| **Collapsible games** | Add expand/collapse to game sections, all expanded by default. | Low |

---

## 10. Cross-Cutting Improvements

### Loading & Transitions
- **Page transitions**: No page transition animations. Adding a simple fade (150ms) on Inertia page visits would feel more polished. Inertia supports this via Vue's `<Transition>` component.
- **Skeleton consistency**: Skeletons are used in deck detail deferred tabs but not on the dashboard or other pages.

### Empty States
- **Decks page**: Has a good empty state with icon + text.
- **Dashboard**: No empty state — if there are no matches, the page is just... empty.
- **Leagues**: Has a text-only empty state ("No league runs recorded yet") — no icon.
- **Recommendation**: Create a consistent empty state pattern: icon + headline + subtext + optional CTA. Apply to all pages.

### Responsive Considerations
- While this is a desktop app, Electron windows can be resized to narrow widths. The current responsive breakpoints (`sm`, `lg`) handle this, but the sidebar `collapsible="offcanvas"` behavior may be jarring on desktop — it should collapse to icons rather than hiding entirely.

### Accessibility
- Focus rings are properly configured via shadcn defaults.
- Missing: skip links, ARIA labels on icon-only buttons (the archetype edit pencil, the sidebar trigger).
- Table rows that act as links (`cursor-pointer` + `@click`) should use proper `<a>` tags or `role="link"` for screen readers.

---

## 11. Recommended Priority Order

### Phase 1 — Quick Wins (1-2 days)
1. Add a brand accent color to the palette
2. Add headline KPI row to dashboard (data already exists)
3. Fix timeframe selector styling (rounded corners)
4. Add `rounded-full` to settings status dots
5. Fix deck detail stats card padding
6. Add `py-3` to stat cells
7. Polish empty states across all pages

### Phase 2 — Desktop Feel (3-5 days)
1. Status bar with watcher state + last ingestion
2. Information density pass (tighten padding/gaps globally)
3. Sidebar icon-collapse mode for desktop
4. Collapsible league cards
5. Page transition animations
6. Native file picker for settings paths
7. Toast notification system

### Phase 3 — Identity & Delight (1-2 weeks)
1. Typography upgrade (custom font)
2. Format color system (color-coded badges and card stripes)
3. Mana-color design language for archetypes
4. Command palette (`Cmd+K`)
5. Keyboard shortcuts with UI hints
6. Sparklines / mini-charts on cards
7. Custom titlebar with drag region
8. Opponent detail page / filtered match history

---

## Appendix: File Reference

| File | Role |
|------|------|
| `resources/css/app.css` | Theme tokens, color variables, custom utilities |
| `resources/js/AppLayout.vue` | Root layout (sidebar + header + content) |
| `resources/js/components/AppSidebar.vue` | Navigation sidebar |
| `resources/js/components/SiteHeader.vue` | Page header with breadcrumbs |
| `resources/js/pages/Index.vue` | Dashboard |
| `resources/js/pages/decks/Index.vue` | Deck list |
| `resources/js/pages/decks/Show.vue` | Deck detail |
| `resources/js/pages/leagues/Index.vue` | League runs |
| `resources/js/pages/opponents/Index.vue` | Opponent tracker |
| `resources/js/pages/matches/Show.vue` | Match detail |
| `resources/js/pages/settings/Index.vue` | Settings / preferences |
| `resources/js/components/matches/ResultBadge.vue` | Win/loss pip indicator |
| `resources/js/components/leagues/LeagueTable.vue` | League run card with match table |
| `resources/js/components/ManaSymbols.vue` | WUBRG mana icon renderer |
