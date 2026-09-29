<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Helper Release Pin
    |--------------------------------------------------------------------------
    |
    | The helper release this build runs. Bump version and sha256 together,
    | copying both from the mymtgo/sidecar release notes. An empty sha256
    | blocks the download entirely rather than running an unverified exe.
    |
    */

    'version' => '0.1.2',

    'sha256' => '7ebc9f9d519c80979ca8a3bbabda9c8100e64d0edcf8960d9be9faf45dff2e2e',

    'url' => 'https://github.com/mymtgo/sidecar/releases/download/v%s/mymtgo-helper.exe',

    /*
    |--------------------------------------------------------------------------
    | Local Build Override
    |--------------------------------------------------------------------------
    |
    | Dev only: run a local build instead of the pinned release. Ignored
    | outside APP_ENV=local, and stripped from bundled builds.
    |
    */

    'exe_override' => env('SIDECAR_EXE_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Test Seams
    |--------------------------------------------------------------------------
    |
    | Production leaves both at their defaults.
    |
    */

    'platform' => PHP_OS_FAMILY,

    'bin_directory' => null,

];
