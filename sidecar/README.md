# MTGO sidecar

.NET 10 helper that reads the live MTGO client through MTGOSDK and writes NDJSON events plus
status.json for the app's PHP ingester. Spec: docs/superpowers/specs/2026-09-21-mtgo-sidecar-design.md.

Two projects:

- `MyMtgo.Sidecar.Core` (`net10.0`, no MTGOSDK dependency): envelope and writer, atomic status,
  version gate, parent watch, rolling log, the game snapshot model, and the snapshot differ. Runs
  and tests on macOS.
- `MyMtgo.Sidecar` (`net10.0-windows`, the exe): thin MTGOSDK adapter. Attaches to MTGO, maps SDK
  snapshots into Core snapshots, runs the probe, drives the supervisor loop. Runs on Windows only,
  with MTGO installed.

## Build

    export DOTNET_ROOT=/opt/homebrew/opt/dotnet/libexec   # macOS
    dotnet test MyMtgo.Sidecar.Core.Tests                  # the Core suite, runs anywhere
    dotnet build MyMtgo.Sidecar -c Release                 # compiles anywhere, runs on Windows only

Toolchain used to build this: .NET 10 SDK 10.0.401 via Homebrew on macOS, MTGOSDK NuGet
`1.7.0.20260903`.

## Publish into the app

    ./publish.sh 0.30.0            # macOS/Linux
    .\publish.ps1 -Version 0.30.0  # Windows

Output: `resources/sidecar/mymtgo-helper.exe` plus the MTGOSDK licence and notice. The folder is
git-ignored; the NativePHP Windows build bundles whatever is there. Mac builds ship without it.

The exe is self-contained and untrimmed, about 146 MB. Trimming stays off (`PublishTrimmed=false`)
because MTGOSDK relies on reflection; NativeAOT is not viable for the same reason.

## Run by hand

    mymtgo-helper.exe --out C:\path\to\sidecar --parent-pid <pid> [--config <https url>] [--probe-interval <seconds>]

Square brackets mark optional flags; drop the brackets when you type the command.

Arguments:

- `--out <dir>` (required): directory for the event stream, status file and log.
- `--parent-pid <pid>` (required): the sidecar exits within 5 seconds of this process dying.
- `--config <https url>` (optional): remote config source (version gate, etc).
- `--probe-interval <seconds>` (optional): interval between verification probes.

Exit codes: `0` normal exit, `2` usage error, `3` cannot create the output directory.

Logs go to `<out>/sidecar.log` (rolling, 5 MB x 3). Ctrl+C exits.

## Outputs

Written to the `--out` directory:

- `events-{session}.ndjson`: the append-only NDJSON event stream for the current session.
- `status.json`: current sidecar state, written atomically every 2 seconds and on every change.
- `sidecar.log`: rolling diagnostics log, never the event stream.
- `known-good-cache.json`: cache for the version gate.

## Testing on Windows

None of this can run from macOS: the exe needs a live MTGO client. Manual pass, in order.
Record `SMOKE: pass|fail` per item; adapter drift found here becomes a fix round.

1. `.\sidecar\publish.ps1 -Version <current NATIVEPHP_APP_VERSION>`, then `composer native:dev`
   with the sidecar setting on. Expect `status.json` in the app's `storage/app/sidecar/` with
   `state: waiting` within 5 seconds of boot, and `sidecar.log` present.
2. Start MTGO and log in. Expect `state: attached` (or `degraded` with the probe checks showing
   which failed), a `session_started` and `probe` line in the events file, `/debug/sidecar`
   showing the rows.
3. Play one league or practice game to completion. Expect `game_started`, keyframes each turn,
   `card_zone_changed` for every land drop, `card_tapped` when paying mana, `clock_tick` on turn
   boundaries, `game_ended` with the right `winner_p`, `match_started` (may be after
   `game_started`) and, after the match, `match_ended` with the right score.
4. Record answers to the two open spikes: does `c` (`card_zone_changed.c`) equal the Twitch
   snapshot card id for the same card (compare with `game_timelines` content for the same game);
   is `sideboarding_started.ends_at` offset from real time (see the `SideboardingEnds` ruling in
   Task 12).
5. Kill MTGO mid-game. Expect `game_ended {reason: "disconnect"}`, `state: waiting`, then a new
   session file and `keyframe {trigger: "reattach"}` when MTGO is restarted into the same game.
6. Close the app. Expect the exe to exit within 5 seconds (Task Manager).
7. Toggle the sidecar off in settings, then on. Expect no crash counted and a fresh attach.

Additional checks flagged by earlier tasks, to run alongside the above:

8. Zero-argument `Action` subscription to `GameStatusChanged` delivers status callbacks.
9. Nonce uniqueness across ticks: no missing events across long prompt-free stretches.
10. `OwnerIndex`/`ControllerIndex` are not `-1` on partial-backed cards.
11. `GameCard.Zone` read cost per tick: note first-tick latency and any catalog resolver IPC
    overhead observed.
12. The 3-second result grace period produces a winner, not `reason: unknown`, in the normal case.
13. Two-player attack target inference is correct (no ambiguity to resolve at 2 players).
14. `CardCounter.ToString` keys are accepted as-is by the PHP ingester.
15. The pre-mulligan first snapshot arrives (mulligan decisions are observable from tick one).
16. Opponent `ChessClock` is populated in both `clock_tick` and keyframe events, not just the
    local player's.
17. Parent process kill exits the sidecar within 5 seconds (distinct from item 6, which is a
    graceful app close; this is the parent-watch failure path).
18. MTGO quitting mid-session sets `state: waiting`, and a fresh attach produces a new session
    file (new `events-{session}.ndjson`), not a reused one.
19. Challenge match parent shape: verify both the `Match` parent case and the `null` parent with
    challenge text case are handled and distinguishable in `match_started.event_type`.
20. `sideboarding_started` is emitted for every between-game window in a best-of-three, not
    only the first (the tracker fires on the rising edge of the sideboarding flag).
21. With the app offline the PHP supervisor passes `--config ''`; confirm the exe starts (empty
    config means no remote gate, allow) and does not exit 2 into the crash tripwire.
22. A session that dies immediately after attach (for example MTGO killed during login) backs
    off (1 s doubling to 30 s) instead of re-attaching every second, and does not mint a new
    `events-{session}.ndjson` each cycle.
