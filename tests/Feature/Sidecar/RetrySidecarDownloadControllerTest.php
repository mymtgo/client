<?php

use App\Facades\AppSettings;
use App\Jobs\DownloadSidecarJob;
use App\Sidecar\SidecarDownloadState;
use App\Sidecar\SidecarDownloadStore;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->bin = sys_get_temp_dir().'/sidecar-bin-'.Str::random(8);
    config(['sidecar.platform' => 'Windows', 'sidecar.bin_directory' => $this->bin, 'sidecar.version' => '0.1.0']);
    AppSettings::setOffline(false);
    AppSettings::setSidecarEnabled(true);
});

afterEach(function () {
    File::deleteDirectory($this->bin);
});

it('clears a held lock, resets the run and dispatches', function () {
    Queue::fake();
    SidecarDownloadStore::write(SidecarDownloadState::failed('0.1.0', 'run-a', SidecarDownloadState::ERROR_QUARANTINED));
    Cache::lock(UniqueLock::getKey(new DownloadSidecarJob('run-a')), 1200)->get();

    $this->post(route('settings.sidecar-download'))->assertRedirect();

    Queue::assertPushed(DownloadSidecarJob::class, 1);
    $state = SidecarDownloadStore::read();
    expect($state->status)->toBe(SidecarDownloadState::DOWNLOADING);
    expect($state->runId)->not->toBe('run-a');
});

it('does nothing off Windows', function () {
    Queue::fake();
    config(['sidecar.platform' => 'Darwin']);

    $this->post(route('settings.sidecar-download'))->assertRedirect();

    Queue::assertNothingPushed();
});

it('shares the helper status on the general settings page on Windows only', function () {
    $this->get(route('settings.general'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('helper.state', 'downloading'));

    config(['sidecar.platform' => 'Darwin']);

    $this->get(route('settings.general'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('helper', null));
});
