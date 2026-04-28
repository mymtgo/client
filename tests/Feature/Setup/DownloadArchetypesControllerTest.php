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
