<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Import Data Path Override
    |--------------------------------------------------------------------------
    |
    | When set, the import wizard will scan this directory instead of the
    | standard MTGO data path. Useful for development/testing on non-Windows
    | machines where MTGO isn't installed.
    |
    | Example: storage_path('app/91F5DC46A0AFBF283E8FD4E9E184F175')
    |
    */
    'import_data_path' => env('MYMTGO_IMPORT_DATA_PATH'),
];
