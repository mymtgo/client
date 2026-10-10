<?php

/*
 * The farewell release (0.47.0): MyMTGO 1.0 replaces this app, so every page
 * shows one screen pointing to the new download. Not read from the
 * environment, so a user's .env cannot switch it off; the test suite switches
 * it off for the older page tests.
 */

return [
    'enabled' => true,

    'download_url' => 'https://mymtgo.com/tracker',
];
