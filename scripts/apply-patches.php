<?php

/**
 * Apply vendor patches that cannot be managed via composer-patches.
 * Run automatically via composer post-install-cmd / post-update-cmd.
 */

echo "\nApplying vendor patches...\n";

applyPatch(
    description: 'NativePHP: windowsHide+stdio to fix Windows 15-min boot hang (callPhpSync)',
    file: __DIR__ . '/../vendor/nativephp/desktop/resources/electron/electron-plugin/src/server/php.ts',
    guard: 'windowsHide: true',
    search: "            }\n        }\n    );\n}\n\nfunction getArgumentEnv(",
    replace: "            },\n            stdio: 'pipe',\n            windowsHide: true,\n        }\n    );\n}\n\nfunction getArgumentEnv(",
);

echo "\n";

// ---------------------------------------------------------------------------

function applyPatch(string $description, string $file, string $guard, string $search, string $replace): void
{
    if (!file_exists($file)) {
        echo "  [SKIP] {$description}\n         File not found: {$file}\n";
        return;
    }

    $contents = file_get_contents($file);

    if (str_contains($contents, $guard)) {
        echo "  [OK]   {$description}\n";
        return;
    }

    if (!str_contains($contents, $search)) {
        echo "  [FAIL] {$description}\n         Search string not found — patch may need updating after a NativePHP upgrade.\n";
        return;
    }

    file_put_contents($file, str_replace($search, $replace, $contents));
    echo "  [DONE] {$description}\n";
}
