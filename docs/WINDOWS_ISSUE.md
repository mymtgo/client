# Windows Boot Hang — NativePHP `php artisan optimize`

## Symptom

On Windows, the packaged Electron app hangs indefinitely on startup. The `php artisan optimize`
(and `php artisan migrate`) calls inside NativePHP never complete. The app appears frozen.

`native:dev` works fine because it runs from a terminal that has a proper stdio/console attached.

---

## Root Cause

`spawnSync` (used in NativePHP's `callPhpSync`) on Windows will hang when:
- `stdio` is not explicitly set to `'pipe'` (defaults to inheriting parent stdio)
- The parent process has no console window (i.e. a packaged Electron app launched from the GUI)

Without `stdio: 'pipe'`, Windows blocks waiting for a console handle that doesn't exist.

The fix is to add `stdio: 'pipe'` and `windowsHide: true` to the `spawnSync` options in
`callPhpSync`.

---

## Investigation History

### 1. Initial patch attempt (2026-02-26)

A patch script was created at `scripts/apply-patches.php` targeting:

```
vendor/nativephp/desktop/resources/electron/electron-plugin/src/server/php.ts
```

**This did not work.** The `.ts` source is never recompiled during `composer install`.
The file that actually executes is the pre-compiled:

```
vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/php.js
```

### 2. NativePHP 2.1.0 investigation

The 2.0.2 → 2.1.0 diff was reviewed. The notable addition was:

```js
// resources/electron/src/main/index.js
import fixPath from 'fix-path';
fixPath();
```

`fix-path` is a macOS/Linux library to inherit the shell PATH for GUI apps. On Windows,
it can trigger shell detection. This was suspected as a contributing factor but downgrading
to 2.0.2 did not resolve the hang, confirming the root cause was always the `spawnSync`
stdio issue.

### 3. Fix — applied to fork (2026-02-26)

The fix was applied directly to the NativePHP desktop fork at `/Volumes/Dev/mymtgo/desktop`.

**`resources/electron/electron-plugin/src/server/php.ts`** (source):
```ts
return spawnSync(state.php, args, {
    cwd: options.cwd,
    env: { ...process.env, ...options.env },
    stdio: 'pipe',       // <-- added
    windowsHide: true,   // <-- added
});
```

**`resources/electron/electron-plugin/dist/server/php.js`** (compiled — this is what runs):
```js
return spawnSync(state.php, args, {
    cwd: options.cwd,
    env: Object.assign(Object.assign({}, process.env), options.env),
    stdio: 'pipe',       // <-- added
    windowsHide: true,   // <-- added
});
```

---

## Current Setup

- `composer.json` points to the fork: `https://github.com/alecritson/desktop.git` at `dev-main`
- Fix is on the `main` branch of the fork
- After pulling changes: `composer update nativephp/desktop`

---

## If the Issue Recurs After a NativePHP Upgrade

The `dist/server/php.js` file will be overwritten. Re-apply the fix to both:
- `resources/electron/electron-plugin/src/server/php.ts` (line ~171, `callPhpSync`)
- `resources/electron/electron-plugin/dist/server/php.js` (line ~129, `callPhpSync`)

The `scripts/apply-patches.php` script in the client is now stale — it targets the wrong
file. Either update it to target `dist/server/php.js` or rely on the fork instead.
