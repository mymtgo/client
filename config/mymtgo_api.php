<?php

return [
    'url' => env('MYMTGO_API_URL', 'https://mymtgo.com'),
    'verify_ssl' => env('MYMTGO_API_VERIFY_SSL', true),

    // Public OAuth client identifier (PKCE — no secret exists client-side).
    'oauth_client_id' => env('MYMTGO_OAUTH_CLIENT_ID'),
];
