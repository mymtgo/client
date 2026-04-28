<?php

use App\Facades\AppSettings;
use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects to /setup when setup is not complete', function () {
    AppSettings::setSetupCompleted(false);

    $this->get('/')->assertRedirect('/setup');
});

it('redirects to /setup when there are no archetypes even after setup completed', function () {
    AppSettings::setSetupCompleted(true);
    Archetype::query()->delete();

    $this->get('/')->assertRedirect('/setup');
});

it('allows the request when setup is complete and archetypes exist', function () {
    AppSettings::setSetupCompleted(true);
    Archetype::factory()->create();

    $this->get('/')->assertOk();
});

it('always allows /setup itself', function () {
    AppSettings::setSetupCompleted(false);

    $this->get('/setup')->assertOk();
});
