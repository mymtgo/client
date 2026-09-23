# Windows setup for the MTGO helper

Step by step for getting the helper (`sidecar/`) built and running inside the app on a
Windows dev machine. No C# knowledge needed for any of this; you only run commands.

Everything below runs in PowerShell. Open it from the Start menu (search "PowerShell").
Run each command from the repo root (the folder that holds `artisan`) unless told otherwise.

## 1. Install the .NET 10 SDK (once)

The SDK is the compiler. Users need nothing: the published helper carries its own runtime.

```powershell
winget install Microsoft.DotNet.SDK.10
```

Close and reopen PowerShell, then check:

```powershell
dotnet --version
dotnet --list-runtimes
```

Expect `dotnet --version` to print `10.x.y`, and the runtimes list to include a line starting
`Microsoft.WindowsDesktop.App 10.`. If `dotnet` is not recognised, reopen PowerShell; if still
not, reboot once. The installer updates PATH but open shells do not see it.

If `winget` itself is missing, download the SDK installer from
<https://dotnet.microsoft.com/download/dotnet/10.0> (pick "SDK", "Windows", "x64") and run it.

## 2. Get the branch

```powershell
git fetch
git checkout mtgo_sidecar_phase_two
git pull
composer install
npm install
```

## 3. Allow local scripts (once per shell, or once for good)

PowerShell blocks unsigned scripts by default. Either per shell:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
```

or once for your user:

```powershell
Set-ExecutionPolicy -Scope CurrentUser RemoteSigned
```

## 4. Build the helper

The version must match `NATIVEPHP_APP_VERSION` in `.env`. Check it:

```powershell
Select-String NATIVEPHP_APP_VERSION .env
```

Then:

```powershell
.\sidecar\publish.ps1 -Version 0.42.0
```

First run takes a few minutes (NuGet downloads MTGOSDK and dependencies). Later runs take
about thirty seconds. Success looks like three files in `resources\sidecar\`:

```
mymtgo-helper.exe        (about 23 MB)
MTGOSDK-LICENSE.txt
MTGOSDK-NOTICE.txt
```

That folder is git-ignored. Nothing to commit from it.

If it fails with `publish failed`, scroll up: the real error is above. Usual causes are no
network for NuGet, or `dotnet` not found (go back to step 1).

## 5. Run the app

```powershell
php artisan route:clear
php artisan wayfinder:generate
composer native:dev
```

The helper starts automatically when the app boots, as long as the sidecar setting is on
(default on; there is no settings screen for it yet, it lives in `storage\app\settings.json` as
`sidecar_enabled`). You do not run the exe yourself.

Check it is alive:

```powershell
Get-Content storage\app\sidecar\status.json
```

Within about five seconds of boot you should see `"state": "waiting"` (MTGO not running) or
`"state": "attached"` (MTGO running and logged in). `storage\app\sidecar\sidecar.log` holds the
helper's own diagnostics.

Task Manager shows the process as `MyMTGO Helper`.

The debug page inside the app, `/debug/sidecar`, shows status, recent events and any
disagreements between the helper and the logs. Debug mode must be on (Settings, Advanced).

## 6. Run the smoke checklist

Open `sidecar\README.md`, section "Testing on Windows". Twenty four numbered items. Note
`pass` or `fail` per item plus anything odd you see. Items 1 to 7 are the important ones,
the rest are checks flagged during development.

## 7. Capture fixtures to bring back

After playing, copy these somewhere safe (a zip is fine):

- `storage\app\sidecar\` (all `events-*.ndjson`, `status.json`, `sidecar.log`,
  `known-good-cache.json`)
- the MTGO log covering the same games. Settings, Storage shows the log path the app is
  watching. Copy the whole current log file.

Do not put these in the repo as they are. Usernames must be swapped for fakes before they
become fixtures: yours becomes `local.player`, opponents `Opp_1`, `Opp_2` and so on. Same
replacement in the ndjson and the log copy, so names still match across the two.

## 8. Rebuild after helper code changes

Any change under `sidecar\` needs step 4 again, then restart the app. The running helper is
a copy of the old exe; the app only picks up the new one on next boot.

For a quick compile check without publishing:

```powershell
cd sidecar
dotnet build MyMtgo.Sidecar -c Release
cd ..
```

Core unit tests (no MTGO needed):

```powershell
cd sidecar
dotnet test MyMtgo.Sidecar.Core.Tests
cd ..
```

## 9. Checking it runs without .NET installed (smoke item 23)

The helper is self-contained, so a user needs no .NET at all. Step 1 installed the Desktop
Runtime as part of the SDK, so to prove the helper does not lean on it:

1. Settings, Apps, Installed apps. Uninstall "Microsoft Windows Desktop Runtime - 10.x.y (x64)".
   Leave the SDK alone.
2. Start the app with MTGO running. Expect `status.json` to reach `attached` as usual.
3. Reinstall the runtime afterwards if you still build here: reinstalling the SDK brings it back.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `dotnet : The term 'dotnet' is not recognized` | Reopen PowerShell; reboot if that fails. |
| `running scripts is disabled on this system` | Step 3. |
| `publish failed` mentioning `MTGOSDK` or `NU1101` | NuGet cannot reach nuget.org. Check network or proxy. |
| `status.json` never appears | `sidecar_enabled` false in `storage\app\settings.json`, or exe missing. Check `resources\sidecar\mymtgo-helper.exe` exists and today's `storage\logs\pipeline-YYYY-MM-DD.log` for "Sidecar not started". |
| `state: error` with `ProcessCrashedException` | MTGO restarted or crashed. Helper retries with backoff up to 30 s. Normal. |
| Helper keeps exiting, debug page shows `tripped` | Five exits in ten minutes. Read `storage\app\sidecar\sidecar.log`, then restart the app to reset. |
| `state: degraded` | Probe found an SDK read that did not match. Events are still written with `verified: false`. Report which probe check failed (shown in `status.json`). |
