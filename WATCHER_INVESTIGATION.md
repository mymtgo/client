# File Watcher Investigation

## Summary

The file watcher was introduced for real-time log ingestion. The Node.js watcher (chokidar) works — verified by running it standalone and seeing MTGO log changes detected instantly. The problem is NativePHP's child process IPC chain silently drops all messages before they reach PHP.

## What works

- **Node.js watcher**: chokidar detects `mtgo.log` and `Match_GameLog_*.dat` changes immediately
- **Boot-time ingestion**: `Mtgo::ingestLogs()` in `NativeAppServiceProvider::boot()` processes all existing data on startup
- **Event-driven pipeline**: `HandleFileChange` → `IngestLog` → `DispatchDomainEvents` → listeners all work when triggered directly

## What's broken: NativePHP IPC

Messages written to stdout in the Node child process go through this chain:

```
file-watcher.js (stdout)
  → childProcess.ts wrapper (reads stdout, does console.log)
    → utility process stdout
      → Electron API handler (api/childProcess.ts)
        → notifyLaravel() HTTP POST to /_native/api/events
          → DispatchEventFromAppController
            → MessageReceived event
              → HandleFileChange listener
```

**Zero messages arrive at the PHP end.** The pipeline log has no `HandleFileChange` entries whatsoever.

### Known contributing factors

1. **`notifyLaravel()` silently swallows errors** — the Electron-side function has an empty `catch {}` block (`vendor/nativephp/desktop/resources/electron/electron-plugin/src/server/utils.ts:26`), so any failure in the HTTP POST is invisible.

2. **`ProcessSpawned` event has an argument mismatch** — Electron sends `payload: [alias, pid]` (2 args) but the PHP constructor only accepts 1 (`public string $alias`). This caused `ArgumentCountError` and was originally used to send the configure message. Fixed by passing watch paths as CLI args instead.

3. **`MessageReceived` event format** — Electron sends `payload: { alias, data }` as an object (named args), which should work with PHP 8 named argument spreading. This is the suspected-working part of the chain, but messages still don't arrive.

### What hasn't been investigated

- Whether the utility process stdout is actually captured by the Electron API handler
- Whether the HTTP POST to `/_native/api/events` is being made at all (vs failing silently)
- Whether there's a timing issue with the PHP server not being ready
- NativePHP version-specific bugs in the child process implementation

## Options for fixing

1. **File-based queue**: Watcher appends JSON lines to a temp file, a PHP artisan command tail-reads it. Event-driven detection (chokidar), reliable delivery (file I/O). Needs both processes started from `NativeAppServiceProvider`.

2. **Direct HTTP from Node to PHP**: Pass the NativePHP PHP port and secret as env vars to the watcher via `ChildProcess::node(env: [...])`. Watcher POSTs directly to `/_native/api/events` or a custom endpoint, bypassing the utility process stdout chain entirely.

3. **Fix NativePHP's IPC**: Debug why the utility process stdout → HTTP POST chain fails. May require changes to the vendored NativePHP package or an upgrade.

4. **PHP-native polling**: `ChildProcess::artisan()` running a persistent command that polls file sizes at ~500ms. Simple and reliable but not event-driven — functionally similar to the scheduler approach.

## Fixes landed this session

All tested (330 tests pass):

| File | Fix |
|------|-----|
| `MtgoManager.php` | Removed `sync: false` from `ingestGameLogs()` — unknown named parameter crashed entire boot sequence |
| `ExtractJson.php` | Added fast path for full-JSON text + scan budget to prevent O(n²) timeout on large inputs |
| `NativeAppServiceProvider.php` | Watch paths passed as CLI args; removed broken `ProcessSpawned` event listener |
| `file-watcher.js` | Only emits for `mtgo.log` and `Match_GameLog_*.dat` — was creating junk `LogCursor` entries for every file in the data directory |
| `StoreMatchMetadata.php` | Backfills format, match_type, AND triggers league assignment from `game_management_json` events. The `match_state_changed` events that trigger `CreateMatch` are one-liners without the `Receiver:` metadata block. |
