<?php

return [
    'discord_invite_url' => env('SUPPORT_DISCORD_INVITE_URL', 'https://discord.gg/KuFv4Jcm38'),

    /*
    |--------------------------------------------------------------------------
    | Tix donation
    |--------------------------------------------------------------------------
    |
    | The MTGO account players trade Event Tickets to when supporting
    | development, and how many games must be tracked before the one-time
    | takeover modal asks. With no handle configured the modal is suppressed —
    | there is no point asking for tix with no destination.
    |
    */
    'tix_handle' => env('SUPPORT_TIX_HANDLE', 'anticloser'),

    'prompt_after_games' => (int) env('SUPPORT_PROMPT_AFTER_GAMES', 30),
];
