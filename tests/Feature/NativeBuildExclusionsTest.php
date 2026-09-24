<?php

/**
 * The native build copies the whole project into the installer, skipping only
 * paths that match `cleanup_exclude_files`. Whatever sits on the build machine
 * ships otherwise: v0.41.1 carried the machine's own settings.json (with its
 * API key), players' support bundles and a 644 MB card dump.
 *
 * This mirrors NativePHP's CopiesToBuildDirectory filter: each path segment is
 * checked with fnmatch against the merged internal + app patterns, and an
 * excluded directory is never descended into.
 */
function isExcludedFromNativeBuild(string $relativePath): bool
{
    $patterns = array_unique(array_merge(
        config('nativephp-internal.cleanup_exclude_files', []),
        config('nativephp.cleanup_exclude_files', []),
    ));

    $segments = explode('/', $relativePath);

    for ($depth = 1; $depth <= count($segments); $depth++) {
        $prefix = implode('/', array_slice($segments, 0, $depth));

        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $prefix)) {
                return true;
            }
        }
    }

    return false;
}

it('keeps local data and dev files out of the installer', function (string $path) {
    expect(isExcludedFromNativeBuild($path))->toBeTrue();
})->with([
    'app settings with api key' => 'storage/app/private/settings.json',
    'support bundles' => 'storage/app/support/some_player/mtgo.log',
    'card dump' => 'storage/app/AllIdentifiers.json',
    'helper logs' => 'storage/app/sidecar_logs/sidecar/events.ndjson',
    'inertia devtools dumps' => 'storage/inertia-devtools/01ABC.json',
    'stray log in storage/app' => 'storage/app/mtgo.log',
    'stray log in database' => 'database/mtgo.log',
    'imported database at root' => 'database_imported.sqlite',
    'root tests' => 'tests/Feature/ExampleTest.php',
    'test storage' => 'tests/storage/app/private/settings.json',
    'docs' => 'docs/specs/some-design.md',
    'screenshots' => 'screenshots/draft_review.png',
    'agent config' => '.claude/settings.json',
    'agent workspace' => '.superpowers/sdd/plan/progress.md',
    'ide config' => '.idea/workspace.xml',
]);

it('still ships the application', function (string $path) {
    expect(isExcludedFromNativeBuild($path))->toBeFalse();
})->with([
    'app code' => 'app/Models/MtgoMatch.php',
    'config' => 'config/nativephp.php',
    'migrations' => 'database/migrations/2026_08_26_000001_add_kind_and_set_code_to_leagues_table.php',
    'routes' => 'routes/web.php',
    'built assets' => 'public/build/manifest.json',
    'views' => 'resources/views/app.blade.php',
    'vendor' => 'vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
    'artisan' => 'artisan',
    'composer' => 'composer.json',
]);
