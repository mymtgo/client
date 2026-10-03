<?php

use App\Facades\AppSettings;
use App\Updates\RemoveSidecarHelper;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->bin = storage_path(RemoveSidecarHelper::BIN_DIRECTORY);
    $this->defaultDir = storage_path(RemoveSidecarHelper::DEFAULT_DIRECTORY);
    $this->customDir = sys_get_temp_dir().'/remove-helper-'.uniqid();

    File::ensureDirectoryExists($this->bin);
    File::put($this->bin.'/mymtgo-helper-0.1.2.exe', 'exe');
    File::put($this->bin.'/download.json', '{}');

    foreach (RemoveSidecarHelper::SETTINGS_KEYS as $key) {
        AppSettings::set($key, 'x');
    }

    AppSettings::forget('sidecar_directory');
});

afterEach(function () {
    File::deleteDirectory($this->bin);
    File::deleteDirectory($this->defaultDir);
    File::deleteDirectory($this->customDir);
});

function writeRemovedHelperFiles(string $dir): void
{
    File::ensureDirectoryExists($dir);

    foreach (['events-aaaa.ndjson', 'status.json', 'status.json.tmp', 'sidecar.log', 'sidecar.1.log', 'known-good-cache.json', 'known-good-cache.json.tmp'] as $name) {
        File::put($dir.'/'.$name, 'x');
    }
}

it('deletes the helper exe and its download state', function () {
    (new RemoveSidecarHelper)->run();

    expect(file_exists($this->bin))->toBeFalse();
});

it('removes the default helper directory once its files are gone', function () {
    writeRemovedHelperFiles($this->defaultDir);

    (new RemoveSidecarHelper)->run();

    expect(file_exists($this->defaultDir))->toBeFalse();
});

it('keeps the default directory when it holds files the helper did not write', function () {
    writeRemovedHelperFiles($this->defaultDir);
    File::put($this->defaultDir.'/notes.txt', 'mine');

    (new RemoveSidecarHelper)->run();

    expect(array_map(fn ($file) => $file->getFilename(), File::files($this->defaultDir)))->toBe(['notes.txt']);
});

it('deletes only helper files from a custom directory and keeps the directory', function () {
    writeRemovedHelperFiles($this->customDir);
    File::put($this->customDir.'/notes.txt', 'mine');
    AppSettings::set('sidecar_directory', $this->customDir);

    (new RemoveSidecarHelper)->run();

    expect(array_map(fn ($file) => $file->getFilename(), File::files($this->customDir)))->toBe(['notes.txt']);
});

it('removes every helper settings key', function () {
    AppSettings::set('sidecar_directory', $this->customDir);

    (new RemoveSidecarHelper)->run();

    foreach (RemoveSidecarHelper::SETTINGS_KEYS as $key) {
        expect(AppSettings::get($key))->toBeNull();
    }
});

it('does nothing when the helper was never installed', function () {
    File::deleteDirectory($this->bin);

    (new RemoveSidecarHelper)->run();

    expect(file_exists($this->bin))->toBeFalse()
        ->and(AppSettings::get('sidecar_enabled'))->toBeNull();
});

it('throws and keeps the settings when the exe survives the delete', function () {
    // Stands in for an exe Windows still has locked: the delete leaves it.
    $update = new class extends RemoveSidecarHelper
    {
        protected function deleteBinDirectory(string $bin): void {}
    };

    expect(fn () => $update->run())->toThrow(RuntimeException::class, 'sidecar-bin')
        ->and(AppSettings::get('sidecar_enabled'))->toBe('x');
});
