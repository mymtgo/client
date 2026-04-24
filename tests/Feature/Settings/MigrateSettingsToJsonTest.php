<?php

use App\Settings\MigrateSettingsToJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Native\Desktop\Facades\Settings;

beforeEach(function () {
    Storage::fake();
});

it('copies every known key from NativePHP Settings', function () {
    Settings::set('log_path', 'C:\\logs');
    Settings::set('share_stats', true);
    Settings::set('watcher_active', false);
    Settings::set('system_tz', 'Europe/London');
    Settings::set('device_id', 'dev-42');

    (new MigrateSettingsToJson)->run();

    $data = json_decode(Storage::disk()->get('settings.json'), true);

    expect($data['log_path'])->toBe('C:\\logs');
    expect($data['share_stats'])->toBeTrue();
    expect($data['watcher_active'])->toBeFalse();
    expect($data['system_tz'])->toBe('Europe/London');
    expect($data['device_id'])->toBe('dev-42');
});

it('falls back to defaults for keys that throw during read', function () {
    Settings::swap(new class
    {
        public function get(string $key, $default = null): mixed
        {
            if ($key === 'log_path') {
                return 'C:\\only-readable';
            }
            throw new RuntimeException('Electron down');
        }

        public function set(string $key, $value): void {}
    });

    (new MigrateSettingsToJson)->run();

    $data = json_decode(Storage::disk()->get('settings.json'), true);

    expect($data['log_path'])->toBe('C:\\only-readable');
    expect($data['share_stats'])->toBeTrue();          // default
    expect($data['watcher_active'])->toBeTrue();       // default
});

it('does not create the file when every key fails to read', function () {
    Settings::swap(new class
    {
        public function get(string $key, $default = null): mixed
        {
            throw new RuntimeException('Electron down');
        }

        public function set(string $key, $value): void {}
    });

    (new MigrateSettingsToJson)->run();

    expect(Storage::disk()->exists('settings.json'))->toBeFalse();
});

it('writes the encrypted api_key unchanged so decryption works', function () {
    $encrypted = Crypt::encrypt('plaintext-key');
    Settings::set('api_key', $encrypted);

    (new MigrateSettingsToJson)->run();

    $data = json_decode(Storage::disk()->get('settings.json'), true);
    expect($data['api_key'])->toBe($encrypted);
    expect(Crypt::decrypt($data['api_key']))->toBe('plaintext-key');
});
