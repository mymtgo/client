<?php

use App\Facades\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the sidecar debug page', function () {
    AppSettings::setDebugMode(true);
    config(['sidecar.bin_directory' => sys_get_temp_dir().'/sidecar-bin-'.Str::random(8)]);

    $this->get(route('debug.sidecar.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('debug/Sidecar')
            ->has('status')
            ->where('download.status', 'idle')
            ->has('settings.enabled')
            ->has('settings.authority.username')
            ->has('summary.game_result.agree')
            ->has('recentEvents')
            ->has('diffs'));
});
