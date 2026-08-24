<?php

use App\Settings\AppSettings;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake();
    $this->settings = new AppSettings;
});

// --- Failure paths: settings.json exists but cannot currently be trusted. ---
// isOffline() must fail CLOSED (true) on every one of these, distinct from
// the "no settings.json at all" case below, which is a normal first-run
// state and must keep defaulting to online.

it('fails closed when settings.json is empty', function () {
    file_put_contents(Storage::disk()->path('settings.json'), '');

    expect($this->settings->isOffline())->toBeTrue();
});

it('fails closed when settings.json contains invalid JSON', function () {
    file_put_contents(Storage::disk()->path('settings.json'), '{not valid json');

    expect($this->settings->isOffline())->toBeTrue();

    // The corrupt file is still quarantined as before — isOffline() failing
    // closed does not change that self-healing behaviour.
    $corrupt = collect(Storage::disk()->files())->first(
        fn (string $f) => str_starts_with($f, 'settings.json.corrupt.')
    );
    expect($corrupt)->not->toBeNull();
});

it('fails closed when settings.json cannot be opened for reading', function () {
    if (function_exists('posix_getuid') && posix_getuid() === 0) {
        $this->markTestSkipped('Running as root bypasses file permissions.');
    }

    $path = Storage::disk()->path('settings.json');
    file_put_contents($path, json_encode(['offline_mode' => false]));
    chmod($path, 0000);

    try {
        expect($this->settings->isOffline())->toBeTrue();
    } finally {
        chmod($path, 0644);
    }
})->skipOnWindows();

it('does not silently drop offline_mode across a set() quarantine of an unrelated key', function () {
    file_put_contents(Storage::disk()->path('settings.json'), '{corrupt');

    // A write to any other key still has to rebuild the file — the
    // corrupt content means whatever offline_mode used to be is
    // unrecoverable, so the rebuild must land on the private choice.
    $this->settings->set('system_tz', 'UTC');

    expect(json_decode(Storage::disk()->get('settings.json'), true))
        ->toBe(['offline_mode' => true, 'system_tz' => 'UTC']);
    expect($this->settings->isOffline())->toBeTrue();
});

// --- Control: the ordinary paths must NOT be hard-wired to true. ---

it('still defaults offline mode to false when settings.json does not exist at all', function () {
    // No file at all is the expected first-run state, not a read failure —
    // every setting's documented default already accounts for it.
    expect(Storage::disk()->exists('settings.json'))->toBeFalse();
    expect($this->settings->isOffline())->toBeFalse();
});

it('still returns the stored value on a healthy read', function () {
    $this->settings->setOffline(true);
    expect($this->settings->isOffline())->toBeTrue();

    $this->settings->setOffline(false);
    expect($this->settings->isOffline())->toBeFalse();
});

it('does not cache the flag across successive calls within the same process', function () {
    $this->settings->setOffline(false);
    expect($this->settings->isOffline())->toBeFalse();

    file_put_contents(Storage::disk()->path('settings.json'), '{corrupt-mid-run');
    expect($this->settings->isOffline())->toBeTrue();
});

it('does not bake offline_mode: true into a genuinely fresh install\'s first write', function () {
    // Regression test: the quarantine fail-closed default above (set()
    // rebuilding with offline_mode: true) must be scoped to genuine
    // corruption only. An empty file is also "not valid array JSON" —
    // exactly what a brand-new install's very first set() call sees before
    // anything has ever been written — and must NOT trip the same default,
    // or every new install would silently start offline.
    expect(Storage::disk()->exists('settings.json'))->toBeFalse();

    $this->settings->setLogPath('C:\\fresh\\install');

    expect(json_decode(Storage::disk()->get('settings.json'), true))
        ->toBe(['log_path' => 'C:\\fresh\\install']);
    expect($this->settings->isOffline())->toBeFalse();
});

// --- offlineModeNeverSet(): the guard MtgoManager::runInitialSetup() uses to
// avoid mistaking a boot-time read failure for a genuine fresh install. ---

it('offlineModeNeverSet is true only when a read succeeds and the key is genuinely absent', function () {
    expect(Storage::disk()->exists('settings.json'))->toBeFalse();

    expect($this->settings->offlineModeNeverSet())->toBeTrue();
});

it('offlineModeNeverSet is false once the key has been written, whatever its value', function () {
    $this->settings->setOffline(true);
    expect($this->settings->offlineModeNeverSet())->toBeFalse();

    $this->settings->setOffline(false);
    expect($this->settings->offlineModeNeverSet())->toBeFalse();
});

it('offlineModeNeverSet is false (not "never set") on empty settings.json', function () {
    file_put_contents(Storage::disk()->path('settings.json'), '');

    expect($this->settings->offlineModeNeverSet())->toBeFalse();
});

it('offlineModeNeverSet is false (not "never set") on invalid JSON', function () {
    file_put_contents(Storage::disk()->path('settings.json'), '{not valid json');

    expect($this->settings->offlineModeNeverSet())->toBeFalse();
});

it('offlineModeNeverSet is false (not "never set") when the file cannot be opened for reading', function () {
    if (function_exists('posix_getuid') && posix_getuid() === 0) {
        $this->markTestSkipped('Running as root bypasses file permissions.');
    }

    $path = Storage::disk()->path('settings.json');
    file_put_contents($path, json_encode(['offline_mode' => true]));
    chmod($path, 0000);

    try {
        expect($this->settings->offlineModeNeverSet())->toBeFalse();
    } finally {
        chmod($path, 0644);
    }
})->skipOnWindows();
