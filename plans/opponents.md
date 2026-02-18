# Plan: Opponents Page (Fake Data)

## Context

Opponents are derived from match history — every unique MTGO username faced becomes an opponent card. The page gives the player a quick way to recognise recurring opponents and see what they tend to play.

Reference spec: `specs/opponents.md`

---

## Files Changed

| Action | File |
|--------|------|
| Create | `app/Http/Controllers/Opponents/IndexController.php` |
| Modify | `routes/web.php` — added `/opponents` group |
| Create | `resources/js/Pages/opponents/Index.vue` |
| Modify | `resources/js/components/AppSidebar.vue` — Opponents nav item |

---

## Tag Rules

| Tag | Condition | Min matches |
|-----|-----------|-------------|
| 👹 Nemesis | Win rate < 40% | 3 |
| ⚔️ Rival | Win rate 40–49% | 3 |
| 🎯 Favourite Victim | Win rate > 65% | 3 |

Only one tag per opponent (most extreme wins). No tag shown if < 3 matches.

---

## Toolbar

- Search input — client-side filter by username
- Sort dropdown — Most played (default) | Win rate ↑ | Win rate ↓ | Most recent
- Format pills — All + one per format seen in data

---

## Opponent Card

- Username (prominent) + tag badge (top right)
- Win rate % (green ≥ 50%, red < 50%) + W–L record + last played (relative, right-aligned)
- Archetypes seen with mana symbols, or "Unknown" in muted text
