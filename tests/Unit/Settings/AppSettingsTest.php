<?php

use App\Settings\AppSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake();
    $this->settings = new AppSettings;
});

it('returns default when file missing', function () {
    expect($this->settings->get('nope', 'fallback'))->toBe('fallback');
});

it('returns null default by default', function () {
    expect($this->settings->get('nope'))->toBeNull();
});

it('round-trips a scalar value', function () {
    $this->settings->set('share_stats', true);

    expect($this->settings->get('share_stats'))->toBeTrue();
});

it('persists across instances (simulating separate processes)', function () {
    $this->settings->set('system_tz', 'America/New_York');

    $fresh = new AppSettings;
    expect($fresh->get('system_tz'))->toBe('America/New_York');
});

it('returns default for key not in file', function () {
    $this->settings->set('share_stats', true);

    expect($this->settings->get('missing', 'x'))->toBe('x');
});

it('does not leave temp file artifacts', function () {
    $this->settings->set('log_path', 'C:\\logs');

    // After a completed write, only the final file should remain.
    expect(Storage::disk()->exists('settings.json'))->toBeTrue();
    expect(Storage::disk()->exists('settings.json.tmp'))->toBeFalse();
});

it('produces a file readable by json_decode', function () {
    $this->settings->set('share_stats', true);
    $this->settings->set('system_tz', 'UTC');

    $raw = Storage::disk()->get('settings.json');
    expect(json_decode($raw, true))->toBe([
        'share_stats' => true,
        'system_tz' => 'UTC',
    ]);
});

it('holds an exclusive lock across read-modify-write', function () {
    // Prime the file so flock has a target.
    $this->settings->set('a', 1);

    $path = Storage::disk()->path('settings.json');
    $external = fopen($path, 'c+');
    expect(flock($external, LOCK_EX | LOCK_NB))->toBeTrue();

    // With an external exclusive lock held, a concurrent set() must wait.
    // We assert that the write call does not return immediately: we release
    // the external lock on a short timer and verify set() completes after.
    try {
        $pid = pcntl_fork();
        if ($pid === 0) {
            usleep(200_000);
            flock($external, LOCK_UN);
            fclose($external);
            exit(0);
        }

        $start = microtime(true);
        $this->settings->set('b', 2);
        $elapsed = microtime(true) - $start;

        expect($elapsed)->toBeGreaterThan(0.15);
        expect($this->settings->get('a'))->toBe(1);
        expect($this->settings->get('b'))->toBe(2);

        pcntl_waitpid($pid, $status);
    } finally {
        if (is_resource($external)) {
            @flock($external, LOCK_UN);
            @fclose($external);
        }
    }
})->skipOnWindows()->skip(! function_exists('pcntl_fork'), 'pcntl_fork required');

it('renames a corrupt file aside and returns defaults on read', function () {
    file_put_contents(Storage::disk()->path('settings.json'), '{not valid json');

    Log::spy();

    expect($this->settings->get('anything', 'fallback'))->toBe('fallback');

    $disk = Storage::disk();
    expect($disk->exists('settings.json'))->toBeFalse();
    $corrupt = collect($disk->files())->first(
        fn (string $f) => str_starts_with($f, 'settings.json.corrupt.')
    );
    expect($corrupt)->not->toBeNull();
    expect($disk->get($corrupt))->toBe('{not valid json');

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'settings.json corrupt'));
});

it('allows a subsequent set to create a fresh valid file', function () {
    file_put_contents(Storage::disk()->path('settings.json'), '{not valid');

    $this->settings->get('share_stats'); // triggers quarantine
    $this->settings->set('share_stats', true);

    expect($this->settings->get('share_stats'))->toBeTrue();
    expect(json_decode(Storage::disk()->get('settings.json'), true))
        ->toBe(['share_stats' => true]);
});

it('resolves the facade to the singleton instance', function () {
    expect(App\Facades\AppSettings::get('missing'))->toBeNull();

    App\Facades\AppSettings::set('hi', 'there');
    expect(App\Facades\AppSettings::get('hi'))->toBe('there');
});

it('quarantines a corrupt file from within set() without a prior read', function () {
    file_put_contents(Storage::disk()->path('settings.json'), '{corrupt');

    Log::spy();

    $this->settings->set('share_stats', true);

    $disk = Storage::disk();
    $corrupt = collect($disk->files())->first(
        fn (string $f) => str_starts_with($f, 'settings.json.corrupt.')
    );
    expect($corrupt)->not->toBeNull();
    expect($disk->get($corrupt))->toBe('{corrupt');

    expect($disk->exists('settings.json'))->toBeTrue();
    expect(json_decode($disk->get('settings.json'), true))
        ->toBe(['share_stats' => true]);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'settings.json corrupt'));
});

it('persists archetypes_last_refreshed_at', function () {
    expect(App\Facades\AppSettings::archetypesLastRefreshedAt())->toBeNull();

    App\Facades\AppSettings::setArchetypesLastRefreshedAt('2026-05-20T04:00:00+00:00');

    expect(App\Facades\AppSettings::archetypesLastRefreshedAt())->toBe('2026-05-20T04:00:00+00:00');
});

it('persists archetypes_refresh_in_progress as bool', function () {
    expect(App\Facades\AppSettings::archetypesRefreshInProgress())->toBeFalse();

    App\Facades\AppSettings::setArchetypesRefreshInProgress(true);

    expect(App\Facades\AppSettings::archetypesRefreshInProgress())->toBeTrue();
});

it('reader does not see truncated bytes during a concurrent write', function () {
    // Prime the file with valid content.
    $this->settings->set('api_key', 'secret-token');

    $path = Storage::disk()->path('settings.json');

    // Simulate a writer holding LOCK_EX mid-write (right after ftruncate, before fwrite).
    $writer = fopen($path, 'c+');
    expect(flock($writer, LOCK_EX))->toBeTrue();

    // Fork: child holds the exclusive lock for 200ms while the file is empty,
    // then writes a valid payload and releases. Parent reads during the window.
    $pid = pcntl_fork();
    if ($pid === 0) {
        ftruncate($writer, 0);
        usleep(200_000);
        rewind($writer);
        fwrite($writer, json_encode(['api_key' => 'new-token']));
        fflush($writer);
        flock($writer, LOCK_UN);
        fclose($writer);
        exit(0);
    }

    // Parent: get() should block on LOCK_SH until the child releases,
    // then return the new value — never the transient zero-byte state.
    $start = microtime(true);
    $value = $this->settings->get('api_key');
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeGreaterThan(0.15);
    expect($value)->toBe('new-token');

    pcntl_waitpid($pid, $status);
})->skipOnWindows()->skip(! function_exists('pcntl_fork'), 'pcntl_fork required');
