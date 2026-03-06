# League Overlay Window

Status: **On hold** — depends on Phase 1 (state-based match pipeline)

## Concept

A small, always-on-top NativePHP window showing the current league run in realtime. Targeted at streamers and players who want a quick glance at their league progress.

```
----------------------------------
| Eldrazi Tron               3-1 |
| Modern League          Match 5 |
----------------------------------
```

## Requirements

- Show active league record (wins-losses across the run)
- Show which match is currently being played (1-5)
- Display deck name and league format
- Window is small, movable, always-on-top, closeable
- Toggled from the app header (not settings) — "League Overlay" button near the settings cog
- Only shows when there is an active league (< 5 matches)

## Technical Notes

### NativePHP Window

NativePHP 2.0 supports multiple windows with `Window::open()` and `alwaysOnTop()`. The app currently only has a single main window. The overlay would be a second window opened on demand with its own route.

### Active League Detection

A league is "active" when it has fewer than 5 completed matches. The overlay picks the most recent league by `started_at`.

### Data Refresh

With the state-based match pipeline (Phase 1), the overlay can detect in-progress matches and update the "Match N" indicator before the match completes. League record updates when a match reaches `complete` state.

Processing interval should be reduced from 30s to ~5-10s for near-realtime feel. SQLite single-writer constraint is manageable since BuildMatch transactions are brief.

### Deck Name Display

Deck name comes from the match's `DeckVersion` relationship. Before the first match is processed, fall back to the league name + format. Once any match in the league has a resolved deck, use that.

## Dependencies

- **Phase 1: State-based match pipeline** — matches need `started`/`in_progress` states so the overlay knows a match is underway before it completes
- Reduced `processLogEvents` interval (~5-10s)

## Open Questions

- Exact window dimensions and styling
- Should the overlay be draggable to any screen (multi-monitor)?
- Should it support customization (font size, opacity, background color) for stream integration?
