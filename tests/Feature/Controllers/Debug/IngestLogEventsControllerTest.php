<?php

use App\Facades\AppSettings;
use App\Jobs\IngestLogs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    AppSettings::set('debug_mode', true);
});

it('dispatches IngestLogs instead of ingesting inline', function () {
    Bus::fake();

    $this->post('/debug/log-events/ingest')->assertRedirect();

    Bus::assertDispatched(IngestLogs::class);
});
