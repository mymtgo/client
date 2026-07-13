<?php

use App\Actions\Auth\Logout;
use App\Data\OAuthTokens;
use App\Facades\AppSettings;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as WindowInstance;

it('clears stored tokens and reopens the auth window on logout', function () {
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');
    AppSettings::setOauthTokens(new OAuthTokens('acc', 'ref', now()->addHour()->toIso8601String()));

    $fake = Window::fake()->alwaysReturnWindows([
        new WindowInstance('main'),
    ]);

    app(Logout::class)->run();

    expect(AppSettings::oauthTokens())->toBeNull();
    // Closure form on purpose: assertOpened('auth') trips is_callable() on
    // Laravel's global auth() helper inside WindowManagerFake.
    $fake->assertOpened(fn (string $id): bool => $id === 'auth');
});
