<?php

use App\Facades\AppSettings;
use App\Jobs\RunPipelineJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    AppSettings::set('debug_mode', true);
});

it('dispatches RunPipelineJob instead of running the pipeline inline', function () {
    Bus::fake();

    $this->post('/debug/matches/process')->assertRedirect();

    Bus::assertDispatched(RunPipelineJob::class);
});
