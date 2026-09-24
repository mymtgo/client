<?php

use App\Actions\Sidecar\ResolveHelperStatus;
use App\Facades\AppSettings;
use App\Sidecar\SidecarDownloadState;
use App\Sidecar\SidecarDownloadStore;
use App\Sidecar\SidecarPaths;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->bin = sys_get_temp_dir().'/sidecar-bin-'.Str::random(8);
    $this->out = sys_get_temp_dir().'/sidecar-out-'.Str::random(8);
    File::ensureDirectoryExists($this->out);
    config([
        'sidecar.platform' => 'Windows',
        'sidecar.bin_directory' => $this->bin,
        'sidecar.version' => '0.1.0',
    ]);
    AppSettings::setSidecarDirectory($this->out);
    AppSettings::setSidecarEnabled(true);
    AppSettings::setSidecarTripped(false);
    AppSettings::setOffline(false);
});

afterEach(function () {
    File::deleteDirectory($this->bin);
    File::deleteDirectory($this->out);
});

function putPinnedExe(): void
{
    File::ensureDirectoryExists(SidecarPaths::binDirectory());
    File::put(SidecarPaths::pinnedExe(), 'exe');
}

function putHeartbeat(int $secondsAgo): void
{
    File::put(SidecarPaths::statusFile(), json_encode([
        'state' => 'attached',
        'heartbeat' => now()->subSeconds($secondsAgo)->toIso8601ZuluString(),
    ]));
}

it('is null off Windows', function () {
    config(['sidecar.platform' => 'Darwin']);

    expect(ResolveHelperStatus::run())->toBeNull();
});

it('is off when disabled', function () {
    AppSettings::setSidecarEnabled(false);

    expect(ResolveHelperStatus::run()['state'])->toBe('off');
});

it('is tripped after repeated crashes', function () {
    putPinnedExe();
    AppSettings::setSidecarTripped(true);

    expect(ResolveHelperStatus::run()['state'])->toBe('tripped');
});

it('is running with a fresh heartbeat', function () {
    putPinnedExe();
    putHeartbeat(2);

    expect(ResolveHelperStatus::run()['state'])->toBe('running');
});

it('is starting with an exe and no fresh heartbeat', function () {
    putPinnedExe();
    putHeartbeat(60);

    expect(ResolveHelperStatus::run()['state'])->toBe('starting');
});

it('is downloading with progress', function () {
    SidecarDownloadStore::write(SidecarDownloadState::downloading('0.1.0', 'run-a', 42, 100));

    expect(ResolveHelperStatus::run())->toBe(['state' => 'downloading', 'progress' => 42, 'error' => null]);
});

it('is downloading without progress when the total is unknown', function () {
    SidecarDownloadStore::write(SidecarDownloadState::downloading('0.1.0', 'run-a', 42, null));

    expect(ResolveHelperStatus::run())->toBe(['state' => 'downloading', 'progress' => null, 'error' => null]);
});

it('shows a stale download as a network failure', function () {
    $this->freezeSecond();
    SidecarDownloadStore::write(SidecarDownloadState::downloading('0.1.0', 'run-a', 42, 100));
    $this->travel(31)->seconds();

    expect(ResolveHelperStatus::run())->toBe(['state' => 'failed', 'progress' => null, 'error' => 'network']);
});

it('passes the failure reason through', function (string $error) {
    SidecarDownloadStore::write(SidecarDownloadState::failed('0.1.0', 'run-a', $error));

    expect(ResolveHelperStatus::run())->toBe(['state' => 'failed', 'progress' => null, 'error' => $error]);
})->with(['network', 'checksum', 'quarantined']);

it('is waiting on offline mode when there is no exe', function () {
    AppSettings::setOffline(true);

    expect(ResolveHelperStatus::run()['state'])->toBe('offline');
});

it('is downloading with unknown progress before the job has written anything', function () {
    expect(ResolveHelperStatus::run())->toBe(['state' => 'downloading', 'progress' => null, 'error' => null]);
});

it('shows offline rather than a failure when offline mode stopped the download', function () {
    $this->freezeSecond();
    SidecarDownloadStore::write(SidecarDownloadState::downloading('0.1.0', 'run-a'));
    $this->travel(31)->seconds();
    AppSettings::setOffline(true);

    expect(ResolveHelperStatus::run()['state'])->toBe('offline');
});
