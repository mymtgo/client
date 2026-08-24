<?php

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

uses(TestCase::class);

it('routes every mymtgo request through a macro', function () {
    $offenders = [];

    // AppServiceProvider defines the macros. RegisterDevice must not use them:
    // the mymtgoReference macro calls RegisterDevice::ensureFresh(), so going
    // through it here would recurse.
    $exempt = [
        'Providers/AppServiceProvider.php',
        'Actions/RegisterDevice.php',
    ];

    // Four ways a call site can bypass the macros and talk to mymtgo.com
    // directly: reading the config key with either quote style, reading the
    // underlying env var directly, or hardcoding the base URL outright.
    //
    // This is a content grep, not a call-graph analysis: it can only catch a
    // call site still wired to the raw URL. It cannot catch a *new* macro
    // that skips the isOffline() check, or a call site that builds the URL
    // through some other indirection. That gap has to be caught in review.
    $patterns = [
        '/config\(\s*[\'"]mymtgo_api\.url[\'"]\s*\)/',
        '/env\(\s*[\'"]MYMTGO_API_URL[\'"]/',
        '/https:\/\/mymtgo\.com/',
    ];

    $files = Finder::create()
        ->files()
        ->in(app_path())
        ->name('*.php');

    foreach ($files as $file) {
        if (in_array($file->getRelativePathname(), $exempt, true)) {
            continue;
        }

        $contents = $file->getContents();

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $contents)) {
                $offenders[] = $file->getRelativePathname();

                break;
            }
        }
    }

    expect($offenders)->toBe([], implode(', ', $offenders).' should use Http::mymtgoApi() or Http::mymtgoReference()');
});
