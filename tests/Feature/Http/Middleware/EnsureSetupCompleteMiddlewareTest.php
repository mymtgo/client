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
    // Enable the archetype-existence check that is bypassed in testing by
    // default to avoid breaking unrelated tests that don't seed archetypes.
    AppSettings::set('enforce_archetype_check', true);
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

it('always allows /up health check', function () {
    AppSettings::setSetupCompleted(false);

    $this->get('/up')->assertOk();
});

it('always allows /_native/* paths', function () {
    AppSettings::setSetupCompleted(false);

    // Use GET — the assertion is that the gate did NOT redirect us to /setup.
    // The route may still 404 in the test environment; that is acceptable.
    $response = $this->get('/_native/non-existent-route');

    expect($response->isRedirect('/setup'))->toBeFalse();
});
