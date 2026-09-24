<?php

use App\Sidecar\SidecarDownloadState;
use App\Sidecar\SidecarDownloadStore;
use App\Sidecar\SidecarPaths;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->bin = sys_get_temp_dir().'/sidecar-bin-'.Str::random(8);
    config(['sidecar.bin_directory' => $this->bin, 'sidecar.version' => '0.1.0']);
});

afterEach(function () {
    File::deleteDirectory($this->bin);
});

it('reads idle for the pinned version when no file exists', function () {
    $state = SidecarDownloadStore::read();

    expect($state->status)->toBe(SidecarDownloadState::IDLE);
    expect($state->version)->toBe('0.1.0');
});

it('reads idle when the file is corrupt', function () {
    File::ensureDirectoryExists($this->bin);
    File::put(SidecarPaths::downloadStateFile(), '{not json');

    expect(SidecarDownloadStore::read()->status)->toBe(SidecarDownloadState::IDLE);
});

it('round-trips a state through the file', function () {
    SidecarDownloadStore::write(SidecarDownloadState::downloading('0.1.0', 'run-a', 1024, 4096));

    $state = SidecarDownloadStore::read();

    expect($state->status)->toBe(SidecarDownloadState::DOWNLOADING);
    expect($state->runId)->toBe('run-a');
    expect($state->bytes)->toBe(1024);
    expect($state->total)->toBe(4096);
    expect($state->progress())->toBe(25);
    expect(glob($this->bin.'/*.tmp'))->toBe([]);
});

it('drops writes from a superseded run', function () {
    SidecarDownloadStore::write(SidecarDownloadState::downloading('0.1.0', 'run-new'));

    $written = SidecarDownloadStore::writeForRun('run-old', SidecarDownloadState::downloading('0.1.0', 'run-old', 999, 1000));

    expect($written)->toBeFalse();
    expect(SidecarDownloadStore::read()->runId)->toBe('run-new');
});

it('treats a downloading state with no update for 30 seconds as stale', function () {
    $this->freezeSecond();
    SidecarDownloadStore::write(SidecarDownloadState::downloading('0.1.0', 'run-a'));

    $this->travel(29)->seconds();
    expect(SidecarDownloadStore::read()->isStale())->toBeFalse();

    $this->travel(2)->seconds();
    expect(SidecarDownloadStore::read()->isStale())->toBeTrue();
});

it('never calls a finished state stale', function () {
    SidecarDownloadStore::write(SidecarDownloadState::failed('0.1.0', 'run-a', SidecarDownloadState::ERROR_NETWORK));

    $this->travel(5)->minutes();

    expect(SidecarDownloadStore::read()->isStale())->toBeFalse();
});

it('reports unknown progress when the total is unknown', function () {
    expect(SidecarDownloadState::downloading('0.1.0', 'run-a', 500, null)->progress())->toBeNull();
});
