# Client UI — JSON-First Views, Overlay Keep-List, Redesign (Deferred)

> Extracted from the v1 architecture brainstorm (2026-06-30). Codebase strategy in [`../overview/spec.md`](../overview/spec.md); data comes from [`../cloud-api/spec.md`](../cloud-api/spec.md).

## Principle: base first, redesign follows (form follows function)

Build the plumbing correct end-to-end first; the UI can **start as raw JSON output** and gain design later. The data source flips local-DB → JSON API, so every viewing page changes its fetching regardless — build each page once against the (eventual) final look rather than rebuilding twice.

## Keep — do not rebuild

- **Live overlay windows** (deck-odds, opponent-scout) — recently refactored, **decoupled from the data flip** (they run off in-memory log events, not the API — see [`../client-agent/spec.md`](../client-agent/spec.md) §1), and a core differentiator. **Restyle only**, don't rebuild the logic.
- **`components/ui/` primitives** (shadcn-vue) + Tailwind theme — the design-system base. Restyle tokens; don't rebuild components (buttons/inputs/etc.) from zero.

## Redesign scope (deferred — differentiates v1 from v0)

The viewing surfaces get a fresh visual language: history, stats, decks, cards, dashboard. Plus new surfaces:
- **Auth window** (see [`../client-auth/spec.md`](../client-auth/spec.md)).
- **Needs-attention outcome UI** — the local area where `outcome: Unknown` matches surface for manual edit (see [`../client-agent/spec.md`](../client-agent/spec.md) §2). Manual edits bake into `{match}.json` and re-push.
- **Account management** (plan status, device/token management, account deletion — see [`../ops/spec.md`](../ops/spec.md)).

## Design once, shared surface

The same JSON API feeds desktop (Inertia) and web → lock one visual language and apply everywhere. When ready, run a `superpowers:brainstorming` → `frontend-design` pass **before** building v1 pages (build each page once against the final look).

## Entitlement gating

Free vs paid is a binary decided **per page** (see [`../ops/spec.md`](../ops/spec.md)). The UI locks gated pages; the server is the source of truth (the API enforces `plan` regardless of what the client shows).
