<?php

use App\Facades\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Native\Desktop\Facades\Shell;

uses(RefreshDatabase::class);

it('renders the signed-out login page', function () {
    $this->get(route('auth.login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
});

it('opens the authorize URL in the system browser and stashes the PKCE pair', function () {
    config()->set('mymtgo_api.url', 'https://mymtgo.test');
    config()->set('mymtgo_api.oauth_client_id', 'client-abc');

    $shell = Shell::fake();

    $this->from(route('auth.login'))
        ->post(route('auth.website'))
        ->assertRedirect(route('auth.login'));

    expect($shell->openExternalCalls)->toHaveCount(1)
        ->and($shell->openExternalCalls[0])->toStartWith('https://mymtgo.test/oauth/authorize?')
        ->and(AppSettings::pkceVerifier())->not->toBeNull()
        ->and(AppSettings::oauthState())->not->toBeNull();
});
