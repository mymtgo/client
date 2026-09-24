<?php

use App\Actions\Sidecar\StartSidecarSupervisor;
use App\Facades\AppSettings;
use App\Jobs\DownloadSidecarJob;
use App\Sidecar\SidecarDownloadState;
use App\Sidecar\SidecarDownloadStore;
use App\Sidecar\SidecarPaths;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Native\Desktop\Facades\ChildProcess;

const HELPER_URL = 'https://github.com/mymtgo/sidecar/releases/download/v0.1.0/mymtgo-helper.exe';

beforeEach(function () {
    // Drop the global catch-all Http::fake() from Pest.php so our stubs win.
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setValue(Http::getFacadeRoot(), collect());

    $this->bin = sys_get_temp_dir().'/sidecar-bin-'.Str::random(8);
    $this->body = 'fake helper exe bytes';
    config([
        'sidecar.platform' => 'Windows',
        'sidecar.bin_directory' => $this->bin,
        'sidecar.version' => '0.1.0',
        'sidecar.sha256' => hash('sha256', $this->body),
    ]);
    AppSettings::setOffline(false);
    AppSettings::setSidecarEnabled(true);
    AppSettings::setSidecarParentPid(4242);

    SidecarDownloadStore::write(SidecarDownloadState::downloading('0.1.0', 'run-a'));
});

afterEach(function () {
    File::deleteDirectory($this->bin);
});

it('downloads, verifies, moves into place and starts with the stored boot pid', function () {
    ChildProcess::fake();
    Http::fake([HELPER_URL => Http::response($this->body)]);

    (new DownloadSidecarJob('run-a'))->handle();

    expect(File::get(SidecarPaths::pinnedExe()))->toBe($this->body);
    expect(glob($this->bin.'/*.partial-*'))->toBe([]);
    expect(SidecarDownloadStore::read()->status)->toBe(SidecarDownloadState::READY);

    ChildProcess::assertStarted(function ($cmd, $alias, $cwd, $env, $persistent) {
        return $alias === StartSidecarSupervisor::ALIAS
            && $cmd[0] === SidecarPaths::pinnedExe()
            && $cmd[array_search('--parent-pid', $cmd, true) + 1] === '4242';
    });
});

it('uses long timeouts so a slow connection can finish 68 MB', function () {
    // The HTTP client's 30s default fails anyone under about 18 Mbit/s.
    expect(DownloadSidecarJob::requestOptions())->toBe(['timeout' => 540, 'connect_timeout' => 10]);
});

it('fails as checksum without retrying when the hash does not match', function () {
    $fake = ChildProcess::fake();
    Http::fake([HELPER_URL => Http::response('tampered bytes')]);

    (new DownloadSidecarJob('run-a'))->handle();

    $state = SidecarDownloadStore::read();
    expect($state->status)->toBe(SidecarDownloadState::FAILED);
    expect($state->error)->toBe(SidecarDownloadState::ERROR_CHECKSUM);
    expect(is_file(SidecarPaths::pinnedExe()))->toBeFalse();
    expect(glob($this->bin.'/*.partial-*'))->toBe([]);
    expect($fake->starts)->toBeEmpty();
});

it('refuses to download with an empty pin', function () {
    ChildProcess::fake();
    Http::fake();
    config(['sidecar.sha256' => '']);

    (new DownloadSidecarJob('run-a'))->handle();

    Http::assertNothingSent();
    expect(SidecarDownloadStore::read()->error)->toBe(SidecarDownloadState::ERROR_CHECKSUM);
});

it('treats a non-2xx response as a network failure, never checksum', function () {
    ChildProcess::fake();
    Http::fake([HELPER_URL => Http::response('Not Found', 404)]);

    $job = new DownloadSidecarJob('run-a');

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    expect(SidecarDownloadStore::read()->status)->toBe(SidecarDownloadState::DOWNLOADING);
    expect(glob($this->bin.'/*.partial-*'))->toBe([]);

    $job->failed(new RuntimeException('HTTP 404'));

    $state = SidecarDownloadStore::read();
    expect($state->status)->toBe(SidecarDownloadState::FAILED);
    expect($state->error)->toBe(SidecarDownloadState::ERROR_NETWORK);
});

it('does not overwrite a checksum failure with network in the failed hook', function () {
    ChildProcess::fake();
    Http::fake([HELPER_URL => Http::response('tampered bytes')]);

    $job = new DownloadSidecarJob('run-a');
    $job->handle();
    $job->failed(new RuntimeException('Helper checksum mismatch'));

    expect(SidecarDownloadStore::read()->error)->toBe(SidecarDownloadState::ERROR_CHECKSUM);
});

it('makes no request when offline mode was switched on after dispatch', function () {
    Http::fake();
    AppSettings::setOffline(true);

    (new DownloadSidecarJob('run-a'))->handle();

    Http::assertNothingSent();
});

it('makes no request when the helper was switched off after dispatch', function () {
    Http::fake();
    AppSettings::setSidecarEnabled(false);

    (new DownloadSidecarJob('run-a'))->handle();

    Http::assertNothingSent();
});

it('makes no request when a newer run owns the state', function () {
    Http::fake();
    SidecarDownloadStore::write(SidecarDownloadState::downloading('0.1.0', 'run-b'));

    (new DownloadSidecarJob('run-a'))->handle();

    Http::assertNothingSent();
    expect(SidecarDownloadStore::read()->runId)->toBe('run-b');
});

it('makes no request when the pinned exe already exists', function () {
    Http::fake();
    File::ensureDirectoryExists($this->bin);
    File::put(SidecarPaths::pinnedExe(), 'already here');

    (new DownloadSidecarJob('run-a'))->handle();

    Http::assertNothingSent();
});

it('is unique per pinned version on the sidecar queue', function () {
    $job = new DownloadSidecarJob('run-a');

    expect($job->uniqueId())->toBe('0.1.0');
    expect($job->queue)->toBe('sidecar');
    expect($job->uniqueFor)->toBe(1200);
    expect($job->timeout)->toBe(600);
    expect($job->tries)->toBe(3);
    expect($job->backoff())->toBe([30, 120]);
});

it('does not install when a newer run took over during the download', function () {
    $fake = ChildProcess::fake();
    Http::fake([HELPER_URL => function () {
        SidecarDownloadStore::write(SidecarDownloadState::downloading('0.1.0', 'run-b'));

        return Http::response($this->body);
    }]);

    (new DownloadSidecarJob('run-a'))->handle();

    expect(is_file(SidecarPaths::pinnedExe()))->toBeFalse();
    expect(glob($this->bin.'/*.partial-*'))->toBe([]);
    expect(SidecarDownloadStore::read()->runId)->toBe('run-b');
    expect($fake->starts)->toBeEmpty();
});

it('records ready when it owns the run and the exe is already in place', function () {
    Http::fake();
    File::ensureDirectoryExists($this->bin);
    File::put(SidecarPaths::pinnedExe(), 'already here');

    (new DownloadSidecarJob('run-a'))->handle();

    expect(SidecarDownloadStore::read()->status)->toBe(SidecarDownloadState::READY);
});
