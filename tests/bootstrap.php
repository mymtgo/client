<?php

declare(strict_types=1);

/*
 * Tests get their own storage tree.
 *
 * Logging channels resolve their paths through storage_path() at config load,
 * so without this the suite writes its own lines into the real
 * storage/logs/*.log that the running app reads back. The sync activity feed
 * is the sharp edge: SyncActivity::isRunning() reports "syncing" whenever that
 * file's last line is non-terminal and its mtime is recent, so a test run left
 * the settings card spinning for 15 minutes.
 *
 * Set before the container exists, because Application::storagePath() reads
 * $_ENV on every call and bootstrap/app.php is required by the first test case
 * that runs.
 */
$storage = __DIR__.'/storage';

foreach ([
    'app/private',
    'app/public',
    'framework/cache/data',
    'framework/sessions',
    'framework/testing',
    'framework/views',
    'logs',
] as $directory) {
    if (! is_dir($path = $storage.'/'.$directory)) {
        mkdir($path, 0777, true);
    }
}

$_ENV['LARAVEL_STORAGE_PATH'] = $storage;
$_SERVER['LARAVEL_STORAGE_PATH'] = $storage;

require __DIR__.'/../vendor/autoload.php';
