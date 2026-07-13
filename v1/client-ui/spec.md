# Client UI — JSON-First Views, 0.x Design Port (No Redesign)

> Extracted from the v1 architecture brainstorm (2026-06-30). Codebase strategy in [`../overview/spec.md`](../overview/spec.md); data comes from [`../cloud-api/spec.md`](../cloud-api/spec.md).

> **Decision (2026-07-09): the visual redesign is dead.** The 0.x design is kept — v1 ports the existing visual language onto the new backend and improves it incrementally over time. `design-brief.md` was deleted; do not run its checkpoint process. The win condition for the frontend rebuild is **simpler code**, not a new look (see "One canonical treatment" below).

## Principle: base first, design follows (form follows function)

Build the plumbing correct end-to-end first; the UI can **start as raw JSON output** and gain design later. The data source flips local-DB → JSON API, so every viewing page changes its fetching regardless — build each page once against the final data shape. The "design later" step is now a known target: restyle to match 0.x, not invent a new language.

## Re-port from 0.x — do not redesign

The 2026-07-02 gut deleted the entire frontend (including overlays and `components/ui/`), so nothing survives in the working tree — the `0.x` branch / git history is the re-port source for all of it:

- **Live overlay windows** (deck-odds, opponent-scout) — decoupled from the data flip (they run off in-memory log events, not the API — see [`../client-agent/spec.md`](../client-agent/spec.md) §1), and a core differentiator. Port the logic and look from 0.x; don't rebuild from scratch.
- **`components/ui/` primitives** (shadcn-vue) + Tailwind theme — the design-system base. Port from 0.x with **one canonical treatment per primitive** (see below); don't rebuild components (buttons/inputs/etc.) from zero.
- **Viewing-page visual design** (history, stats, decks, cards, dashboard) — the 0.x pages are the visual reference for their v1 rebuilds.

## One canonical treatment (the simplification mandate)

The 0.x frontend's real defect was cohesion, not the look: primitives were restyled ad hoc per page, and a half-adopted decorative layer (unused display font, unused texture utility, bevel on a handful of surfaces) shipped inconsistently. During the port:

- Every primitive (dialog, context menu, button, …) gets **exactly one treatment**, defined in `components/ui/` — per-page restyling is deleted, not ported.
- Dead decorative utilities are dropped, not carried over.
- Same look, less code. Any decorative idea either ships everywhere through the system or doesn't ship.

## New surfaces (styled to match the 0.x language)

- **Auth window** (see [`../client-auth/spec.md`](../client-auth/spec.md)).
- **Needs-attention outcome UI** — the local area where `outcome: Unknown` matches surface for manual edit (see [`../client-agent/spec.md`](../client-agent/spec.md) §2). Manual edits bake into `{match}.json` and re-push.
- **Account management** (plan status, device/token management, account deletion — see [`../ops/spec.md`](../ops/spec.md)).

## One language, shared surface

The same JSON API feeds desktop (Inertia) and web → one visual language applied everywhere. That language is the 0.x design; the web property adopts it too.

## Entitlement gating

Free vs paid is a binary decided **per page** (see [`../ops/spec.md`](../ops/spec.md)). The UI locks gated pages; the server is the source of truth (the API enforces `plan` regardless of what the client shows).
