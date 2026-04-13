<?php

use App\Actions\Support\BuildSupportBundle;
use App\Facades\Mtgo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

// RefreshDatabase is required because HandleInertiaRequests::share() queries
// Account, LogCursor, and MtgoMatch on every request, including file downloads.
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mtgoLogDir = sys_get_temp_dir().'/mtgo-test-'.uniqid();
    File::makeDirectory($this->mtgoLogDir, 0755, true);
    File::put($this->mtgoLogDir.'/mtgo.log', "MTGO log contents\n");

    $this->logsDir = sys_get_temp_dir().'/logs-test-'.uniqid();
    File::makeDirectory($this->logsDir, 0755, true);

    Mtgo::shouldReceive('getLogPath')->andReturn($this->mtgoLogDir);
    Cache::forget('mtgo.all_log_paths');

    // Bind the action with the test logs dir so the controller resolves it correctly.
    $this->app->bind(BuildSupportBundle::class, fn () => new BuildSupportBundle($this->logsDir));
});

afterEach(function () {
    File::deleteDirectory($this->mtgoLogDir);
    File::deleteDirectory($this->logsDir);
    Cache::forget('mtgo.all_log_paths');
});

it('returns a zip file with the report bundle', function () {
    File::put($this->logsDir.'/pipeline-2026-04-13.log', "pipeline\n");

    $response = $this->get(route('support.report.download'));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/zip');
    expect($response->headers->get('content-disposition'))
        ->toContain('attachment')
        ->toMatch('/mtgo-report-\d{4}-\d{2}-\d{2}-\d{6}\.zip/');
});

it('returns 404 when no log files exist', function () {
    File::delete($this->mtgoLogDir.'/mtgo.log');
    Cache::forget('mtgo.all_log_paths');

    $response = $this->get(route('support.report.download'));

    $response->assertNotFound();
});
