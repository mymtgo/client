<?php

use App\Sidecar\SidecarPaths;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->bin = sys_get_temp_dir().'/sidecar-bin-'.Str::random(8);
    config([
        'sidecar.platform' => 'Windows',
        'sidecar.bin_directory' => $this->bin,
        'sidecar.version' => '0.1.0',
        'sidecar.exe_override' => null,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->bin);
});

it('names the pinned exe after the pinned version', function () {
    expect(SidecarPaths::pinnedExe())->toBe($this->bin.DIRECTORY_SEPARATOR.'mymtgo-helper-0.1.0.exe');
    expect(SidecarPaths::downloadStateFile())->toBe($this->bin.DIRECTORY_SEPARATOR.'download.json');
});

it('returns null when the pinned exe is missing', function () {
    expect(SidecarPaths::exe())->toBeNull();
});

it('returns the pinned exe when it exists', function () {
    File::ensureDirectoryExists($this->bin);
    File::put(SidecarPaths::pinnedExe(), 'exe');

    expect(SidecarPaths::exe())->toBe(SidecarPaths::pinnedExe());
});

it('ignores an exe for another version', function () {
    File::ensureDirectoryExists($this->bin);
    File::put($this->bin.'/mymtgo-helper-0.0.9.exe', 'old');

    expect(SidecarPaths::exe())->toBeNull();
    expect(SidecarPaths::staleExes())->toBe([$this->bin.DIRECTORY_SEPARATOR.'mymtgo-helper-0.0.9.exe']);
});

it('returns null on non-Windows even when the exe exists', function () {
    File::ensureDirectoryExists($this->bin);
    File::put(SidecarPaths::pinnedExe(), 'exe');
    config(['sidecar.platform' => 'Darwin']);

    expect(SidecarPaths::supported())->toBeFalse();
    expect(SidecarPaths::exe())->toBeNull();
});

it('honours the override only in the local environment', function () {
    File::ensureDirectoryExists($this->bin);
    $override = $this->bin.'/dev-build.exe';
    File::put($override, 'dev');
    config(['sidecar.exe_override' => $override]);

    expect(SidecarPaths::exe())->toBeNull();

    app()->detectEnvironment(fn () => 'local');

    expect(SidecarPaths::exe())->toBe($override);
});
