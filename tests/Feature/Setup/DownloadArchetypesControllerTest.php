<?php

use App\Facades\AppSettings;
use App\Jobs\DownloadArchetypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('dispatches the download archetypes job synchronously', function () {
    Bus::fake([DownloadArchetypes::class]);

    $this->post('/setup/archetypes/download')
        ->assertRedirect('/setup');

    Bus::assertDispatchedSync(DownloadArchetypes::class);
});

it('skips archetype download', function () {
    $this->post('/setup/archetypes/skip')
        ->assertRedirect('/setup');

    expect(AppSettings::setupSkippedArchetypes())->toBeTrue();
});

it('flashes error when download throws', function () {
    config(['mymtgo_api.url' => 'http://127.0.0.1:1']);

    $response = $this->post('/setup/archetypes/download');

    $response->assertRedirect('/setup');
    $response->assertSessionHas('setup_error_archetypes');
});
