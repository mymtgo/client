<?php

// Budget numbers below mirror the server's config/sync.php and must never
// drift looser than it. Tighter is safe; looser breaks the sync protocol.

return [
    // 2: league bundles carry drafts, picks and snapshots (2026-09-03)
    // 3: match bundles carry matches.manual and game_player.opening_hand_json;
    //    deck bundles carry sideboard guides and matchup notes (2026-09-09)
    // 4: deck bundles carry cover_mtgo_id and archetype_uuid; league sidecars
    //    carry deck_client_id (2026-09-10)
    'canonical_version' => 4,
    'oauth' => [
        'client_id' => env('MYMTGO_OAUTH_CLIENT_ID'),
        // No scope is requested: one first-party client has nothing to
        // partition, and the API stopped checking one (API spec
        // 2026-09-09, Scopes).
        //
        // The registered redirect is a page on the API, not this app's deep
        // link. A browser handed a custom-scheme redirect passes it to the
        // OS but leaves the tab on the consent screen, which reads as though
        // authorizing did nothing. That page forwards the same query to the
        // deep link below and tells the reader the tab can be closed. It must
        // byte-for-byte match the redirect URI registered on the server's
        // mymtgo-desktop client.
        'redirect_uri' => rtrim((string) env('MYMTGO_API_URL', 'https://mymtgo.com'), '/').'/oauth/desktop/callback',

        // What the API's callback page sends on, and therefore the only deep
        // link this app treats as an OAuth callback. Its scheme must match
        // NATIVEPHP_DEEPLINK_SCHEME or the OS has nothing to route.
        'deeplink_uri' => 'mymtgo://oauth/callback',
    ],
    'limits' => [
        // The server accepts up to 100, but a default PHP server truncates
        // long before that: max_file_uploads is 20, and each blob is also
        // ~14 multipart parts against max_input_vars /
        // max_multipart_body_parts (1000-ish). A truncated request 422s
        // wholesale, so stay at 20 files and everything fits on default
        // ini settings everywhere.
        'blobs_per_request' => 20,
        'compressed_per_request' => 8 * 1024 * 1024,
        'compressed_per_blob' => 1024 * 1024,
        'manifest_chunk' => 2000,
    ],
];
