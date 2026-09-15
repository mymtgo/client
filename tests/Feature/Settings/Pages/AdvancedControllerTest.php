<?php

use App\Facades\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('renders the advanced settings page with debug mode and version', function () {
    AppSettings::setDebugMode(true);

    $this->get(route('settings.advanced'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Advanced')
            ->where('currentPage', 'advanced')
            ->where('debugMode', true)
            ->where('appVersion', config('nativephp.version')));
});
