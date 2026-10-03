<?php

use App\Facades\AppSettings;
use App\Updates\RemoveSidecarHelper;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    // A per-test storage root, so the suite never touches the repo's storage/app or races a parallel run.
    $this->storage = sys_get_temp_dir().'/remove-helper-'.uniqid();
    $this->app->useStoragePath($this->storage);

    $this->bin = storage_path(RemoveSidecarHelper::BIN_DIRECTORY);
    $this->dataDir = storage_path(RemoveSidecarHelper::DATA_DIRECTORY);

    File::ensureDirectoryExists($this->bin);
    File::put($this->bin.'/mymtgo-helper-0.1.2.exe', 'exe');
    File::put($this->bin.'/download.json', '{}');

    File::ensureDirectoryExists($this->dataDir);

    foreach (['events-aaaa.ndjson', 'status.json', 'sidecar.log', 'known-good-cache.json', 'crash.dmp'] as $name) {
        File::put($this->dataDir.'/'.$name, 'x');
    }

    foreach (RemoveSidecarHelper::SETTINGS_KEYS as $key) {
        AppSettings::set($key, 'x');
    }
});

afterEach(function () {
    File::deleteDirectory($this->storage);
});

it('deletes the helper exe and its download state', function () {
    (new RemoveSidecarHelper)->run();

    expect(file_exists($this->bin))->toBeFalse();
});

it('deletes the helper data directory with everything in it', function () {
    (new RemoveSidecarHelper)->run();

    expect(file_exists($this->dataDir))->toBeFalse();
});

it('removes every helper settings key', function () {
    (new RemoveSidecarHelper)->run();

    foreach (RemoveSidecarHelper::SETTINGS_KEYS as $key) {
        expect(AppSettings::get($key))->toBeNull();
    }
});

it('does nothing when the helper was never installed', function () {
    File::deleteDirectory($this->bin);
    File::deleteDirectory($this->dataDir);

    (new RemoveSidecarHelper)->run();

    expect(file_exists($this->bin))->toBeFalse()
        ->and(AppSettings::get('sidecar_enabled'))->toBeNull();
});

it('throws and keeps the settings when a helper directory survives the delete', function () {
    // Stands in for a file Windows still has locked: the delete leaves it.
    $update = new class extends RemoveSidecarHelper
    {
        protected function deleteDirectory(string $directory): void {}
    };

    expect(fn () => $update->run())->toThrow(RuntimeException::class, 'sidecar-bin')
        ->and(AppSettings::get('sidecar_enabled'))->toBe('x');
});

it('throws when only the data directory survives the delete', function () {
    $update = new class extends RemoveSidecarHelper
    {
        protected function deleteDirectory(string $directory): void
        {
            if (str_ends_with($directory, 'sidecar-bin')) {
                parent::deleteDirectory($directory);
            }
        }
    };

    expect(fn () => $update->run())->toThrow(RuntimeException::class, 'sidecar could not be deleted')
        ->and(file_exists($this->bin))->toBeFalse()
        ->and(AppSettings::get('sidecar_enabled'))->toBe('x');
});
