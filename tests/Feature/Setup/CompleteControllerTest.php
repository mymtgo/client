<?php

use App\Facades\AppSettings;
use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('flips the setup completed flag and redirects to /import', function () {
    Archetype::factory()->create();

    $this->post('/setup/complete', ['next' => 'import'])
        ->assertRedirect('/import');

    expect(AppSettings::setupCompleted())->toBeTrue();
});

it('flips the setup completed flag and redirects to /', function () {
    Archetype::factory()->create();

    $this->post('/setup/complete', ['next' => 'app'])
        ->assertRedirect('/');

    expect(AppSettings::setupCompleted())->toBeTrue();
});

it('defaults to / when next is missing', function () {
    Archetype::factory()->create();

    $this->post('/setup/complete')
        ->assertRedirect('/');
});
