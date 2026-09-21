<?php

use App\Facades\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the sidecar debug page', function () {
    AppSettings::setDebugMode(true);

    $this->get(route('debug.sidecar.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('debug/Sidecar')
            ->has('status')
            ->has('settings.enabled')
            ->has('settings.authority.username')
            ->has('summary.game_result.agree')
            ->has('recentEvents')
            ->has('diffs'));
});
