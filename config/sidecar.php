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

    'version' => '0.1.0',

    'sha256' => 'e6327ddbc50e943c092991b95297a3e9fc743f033cd2bb8f40a2eef4fcf5224c',

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
