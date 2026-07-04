# v1 Design Brief — Fresh Visual Language for the MTGO Deck Tracker

**For:** a design agent (claude.ai/design). This brief is fully self-contained — you have no access to the codebase, and you don't need it. Everything relevant from the current app is described or inlined below.

---

## 1. Mission & protocol

Design a fresh, cohesive visual language for a desktop deck-tracker app and deliver it as a design package:

1. A **design system** — a named token sheet (colors, radii, shadows/elevation, type scale) + one canonical visual treatment for every component in the §5 inventory, demonstrated on a kitchen-sink screen.
2. A **deck view dashboard** screen built entirely from that system, using the sample data in §8.

**Checkpoint protocol — do not skip:**

- **Checkpoint 1 (before building anything):** propose **2–3 distinct visual directions**. Each direction mockup should show: a stat-heavy panel, a data-table fragment, a button row, and a dialog. Present them and **wait for the owner to pick one** before designing the full system.
- **Checkpoint 2:** present the kitchen-sink design-system screen for review **before** starting the dashboard.
- **Checkpoint 3:** present the finished dashboard.

Exploration is genuinely open within the constraints below. Do not play it safe by producing three near-identical directions — make them meaningfully different takes on the same soul (§3).

## 2. Product context

The app is a desktop deck tracker for **Magic: The Gathering Online (MTGO)** — an Electron app (Laravel + Inertia + Vue 3 + Tailwind CSS v4 under the hood). It watches MTGO's local log files, reconstructs matches and games, and shows players their performance data.

- **Audience:** competitive MTGO grinders. They live in this app *while playing* — it runs alongside the MTGO client for hours, often on a second monitor. They care about win rates, matchup spreads, card performance, league progress.
- **The app is data-heavy.** Nearly every surface is numbers: match histories, win/loss records, percentages, per-card statistics, trend charts. **The data is the most important part of the design.** (See §6.)
- **Surfaces the language must eventually cover** (you only design the dashboard now, but the system must plausibly stretch to): dashboard/home, match history list + detail, stats pages, deck pages, card catalog, an outcome-edit dialog flow, an account page, an auth window, and two compact always-on-top overlay windows (deck draw-odds, opponent scout). The same visual language will later be applied to a marketing/web property.

## 3. The soul — what must survive

This is a **fresh design, not a facelift**. It should stand apart from the current app — if a returning user squints and thinks "same design, new coat of paint," you've been too conservative. Only two qualities carry over, and they are *moods*, not values to replicate:

1. **Dark steel.** Dark, cool, metallic-industrial. Not warm, not pastel, not neon synthwave. Within that mood the palette is yours to invent — hues, surface tones, accent strategy, how much color the interface carries.
2. **Tangible.** Surfaces and controls should feel physical — like things you can press, machined panels rather than floating rectangles. How that manifests is your call: edges, elevation, pressed states, inset wells, whatever serves it — but flat, weightless, borderless minimalism is the wrong direction.

You are not given the current palette, fonts, or component styles on purpose. Don't try to reconstruct them.

Also inherited by default (challenge them only with a stated reason): desktop-grade density, slim understated scrollbars, restrained motion.

## 4. Why the current design failed — the mishmash diagnosis

The current design isn't bad; it's **incohesive**. Learn from the specific failure modes:

- **A half-adopted decorative layer.** A display font was declared and used nowhere. A background-texture utility was defined and used nowhere. An edge-bevel treatment appeared on only a handful of surfaces — some buttons felt physical, most didn't.
- **Primitives styled ad hoc per page.** Dialogs, context menus, prompts, and buttons drifted apart because pages restyled them locally instead of the system defining them once. Two dialogs in different parts of the app look like different products.

The lesson: **every decorative or physical idea either ships everywhere through the system, or it doesn't ship.** That is the point of §5.

## 5. The cohesion contract (hard rules)

**Rule 1 — one canonical treatment per primitive.** Every component below gets exactly one designed treatment (with variants where listed), defined in the design system **before** any screen uses it:

| Component | Must define |
|---|---|
| Button | variants (primary / secondary / ghost / destructive), sizes, icon-button |
| Dialog / prompt (incl. confirm-destructive) | header, body, footer-action pattern |
| Context menu | incl. separators, destructive items, submenu |
| Dropdown menu | shares its look with context menu — they must be siblings |
| Select / combobox | trigger + listbox |
| Tooltip | one style |
| Popover | and its relationship to dialog (lighter, anchored) |
| Tabs | page-level and inline |
| Table | header, row hover, selected row, numeric cells, sticky header |
| Badge / tag | incl. win/loss/draw semantic variants |
| Card / panel | the base surface unit; elevation levels |
| Inputs (text, checkbox, switch, slider) | incl. focus + invalid states |
| Empty state | icon + message + action pattern |
| Skeleton / loading | matches the surface it replaces |
| Pagination | |
| Scrollbar | slim, quiet |

**Rule 2 — all interaction states, every time.** Default / hover / active-pressed / focus-visible / disabled. A primitive without designed states is unfinished.

**Rule 3 — no screen-local restyling of primitives.** Screens compose primitives and set layout; they never override a primitive's colors, borders, radii, or shadows. If a screen needs a new look, the system grows a variant.

**Rule 4 — tokens only.** Every color, radius, and shadow comes from the named token sheet. No one-off literal values inside component treatments. Name tokens in the shadcn convention (`background`, `foreground`, `card`, `popover`, `primary`, `secondary`, `muted`, `accent`, `destructive`, `border`, `input`, `ring`, plus your additions) — the implementation target already uses those names, and matching them makes translation mechanical.

## 6. Data-first doctrine

The data is the hero; chrome recedes. Concretely:

- **Numbers get typographic care:** tabular (fixed-width) numerals everywhere data aligns; right-align numeric columns; consistent decimal precision; percentages and records (`12–4`) treated as first-class typographic objects, not afterthought text.
- **Hierarchy: numbers > labels > chrome.** On a stat tile the value dominates, the label whispers, the container nearly disappears. If a border or shadow competes with a number for attention, the border loses.
- **Win/loss/draw semantics are system-level:** one green, one red, one neutral, tokenized, used identically in badges, table cells, and charts. These must read instantly at a glance from across a second monitor.
- **Density is a feature.** Grinders want many rows on screen. Prefer compact row heights with clear hover states over airy padding. If in doubt, offer the denser option.
- **Charts belong to the same family:** a tokenized chart palette (the win/loss pair + 3–4 categorical colors) that sits naturally on the dark surfaces. No default-library styling leaking through.
- **Decoration never sits *on* data.** Texture, gradients, and physical flourishes live on chrome (nav, headers, buttons) — data surfaces stay clean and quiet.

## 7. Constraints

- **Dark only.** There is no light mode. Design the single dark theme directly.
- **Desktop app realities:** the app runs in frameless Electron windows — screens have an app-drawn title/chrome area, not a browser tab. Popups, tooltips, and menus **cannot render outside the window bounds**, so avoid designs that depend on overflowing flyouts near edges.
- **Implementation target (design for it, don't build it):** Tailwind CSS v4 with CSS-variable tokens, and shadcn-style Vue primitives. Practical implications: express everything through the token sheet (§5 Rule 4); component treatments should be achievable with CSS (borders, shadows, gradients, subtle transforms) rather than bespoke imagery; keep motion simple (opacity/transform transitions).
- **Fonts:** a system font stack is the safe default. You may propose ONE display/brand font and ONE mono/numeric font — but per §5, if adopted they appear everywhere their role applies, or not at all. Free/openly-licensed fonts only.
- **Output format:** whatever claude.ai/design produces natively (interactive screens/artifacts) is fine. What matters is that the token sheet is explicit and every screen uses only those tokens.

## 8. The deck view dashboard (reference screen)

The dashboard is the per-deck home: the player opens their deck and sees how it performs. It must carry the following content — same data, fresh design; layout is entirely yours:

- **Timeframe filter** (e.g. 7 days / 30 days / 90 days / all time)
- **Match record + match win rate**, and **game record + game win rate** (matches are best-of-three; games are the individual games inside them)
- **On-the-play vs on-the-draw split** — game records + win rates for when the player went first vs second
- **Win-rate-over-time chart**, with an optional comparison series showing the archetype's aggregate performance across all players ("peer" line)
- **Matchup spread** — per-opponent-archetype records (the table that answers "what am I losing to?")
- **League results summary** — leagues are 5-match events; results bucket into 5-0, 4-1, 3-2, 2-3, 1-4, 0-5 finishes — plus the latest league's current progress
- **Standout cards** — best and worst performing cards in the deck (win rate when drawn)

### Sample data (use this, or equally realistic data — never lorem-ipsum placeholders)

- Deck: **"Izzet Murktide"** — archetype: Izzet Murktide, format: Modern
- Timeframe: last 30 days
- Matches: **23–11** (67.6%) · Games: **52–31** (62.7%)
- On the play: **29–13** (69.0%) · On the draw: **23–18** (56.1%)
- Matchup spread (archetype · record · win rate):
  - Rakdos Scam · 5–2 · 71.4%
  - Amulet Titan · 4–1 · 80.0%
  - Living End · 1–4 · 20.0%
  - Hammer Time · 3–1 · 75.0%
  - Burn · 2–2 · 50.0%
  - Yawgmoth · 2–1 · 66.7%
- League results: 5-0 ×2 · 4-1 ×5 · 3-2 ×4 · 2-3 ×2 · 1-4 ×1 — latest league in progress: **3–1**
- Standout cards: best — *Murktide Regent* 74% win rate when drawn (58 games); *Unholy Heat* 69% (61 games); worst — *Blood Moon* 41% (22 games); *Spell Pierce* 44% (31 games)
- Win-rate-over-time: invent a plausible weekly series trending from ~55% up to ~68% over 30 days; peer line hovering flat around 54%

Numbers should look lived-in, not round. If a value is missing for a state you want to show (e.g. empty state, loading), design the state anyway.

## 9. Deliverables recap

1. **Checkpoint 1:** 2–3 direction mockups (stat panel + table fragment + button row + dialog each). Wait for a pick.
2. **Design system:** explicit named token sheet + kitchen-sink screen showing every §5 primitive in every variant and interaction state, plus a data-table specimen and a stat-tile row. If two things on this screen look like different products, the contract is broken.
3. **Deck view dashboard** built purely from the system, with the §8 content and sample data.

Out of scope: the overlay windows, the auth window, light mode, the marketing site, and any implementation code — a separate engineering pass translates your design into the app.
