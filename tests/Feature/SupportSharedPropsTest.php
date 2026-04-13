<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;

it('shares the Discord invite URL via Inertia props', function () {
    config()->set('support.discord_invite_url', 'https://discord.gg/test123');

    $middleware = new HandleInertiaRequests;
    $shared = $middleware->share(Request::create('/'));

    expect($shared)->toHaveKey('support');
    expect($shared['support'])->toBe([
        'discordInviteUrl' => 'https://discord.gg/test123',
    ]);
});
