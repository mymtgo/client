<?php

use App\Facades\AppSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake();
});

it('defaults logPath to empty string when unset', function () {
    expect(AppSettings::logPath())->toBe('');
});

it('round-trips logPath', function () {
    AppSettings::setLogPath('C:\\custom\\logs');
    expect(AppSettings::logPath())->toBe('C:\\custom\\logs');
});

it('casts bool settings correctly with defaults', function () {
    expect(AppSettings::isWatcherActive())->toBeTrue();
    expect(AppSettings::isDebugMode())->toBeFalse();
    expect(AppSettings::showLeagueWindow())->toBeFalse();
    expect(AppSettings::showGameOverlay())->toBeFalse();
    expect(AppSettings::overlayShowOpponent())->toBeTrue();
    expect(AppSettings::overlayShowDrawOdds())->toBeTrue();
    expect(AppSettings::overlayShowSideboard())->toBeTrue();
    expect(AppSettings::downloadImagesLocally())->toBeFalse();
});

it('round-trips bool mutators', function () {
    AppSettings::setWatcherActive(false);
    AppSettings::setDebugMode(true);

    expect(AppSettings::isWatcherActive())->toBeFalse();
    expect(AppSettings::isDebugMode())->toBeTrue();
});

it('defaults offline mode to false when the key has never been written', function () {
    expect(AppSettings::isOffline())->toBeFalse();
});

it('round-trips offline mode', function () {
    AppSettings::setOffline(true);

    expect(AppSettings::isOffline())->toBeTrue();

    AppSettings::setOffline(false);

    expect(AppSettings::isOffline())->toBeFalse();
});

it('defaults systemTimezone to UTC', function () {
    expect(AppSettings::systemTimezone())->toBe('UTC');
});

it('round-trips systemTimezone', function () {
    AppSettings::setSystemTimezone('America/New_York');
    expect(AppSettings::systemTimezone())->toBe('America/New_York');
});

it('round-trips deviceId', function () {
    AppSettings::setDeviceId('abc-123');
    expect(AppSettings::deviceId())->toBe('abc-123');
});

it('encrypts apiKey on write and decrypts on read', function () {
    AppSettings::setApiKey('secret-key-xyz');

    $raw = json_decode(Storage::disk()->get('settings.json'), true);
    expect($raw['api_key'])->not->toBe('secret-key-xyz');
    expect(Crypt::decrypt($raw['api_key']))->toBe('secret-key-xyz');

    expect(AppSettings::apiKey())->toBe('secret-key-xyz');
});

it('returns null for apiKey when unset', function () {
    expect(AppSettings::apiKey())->toBeNull();
});

it('returns null for apiKey when stored value is malformed', function () {
    Storage::disk()->put('settings.json', json_encode(['api_key' => 'not-encrypted']));

    expect(AppSettings::apiKey())->toBeNull();
});

it('allows clearing apiKey with null', function () {
    AppSettings::setApiKey('secret');
    AppSettings::setApiKey(null);

    expect(AppSettings::apiKey())->toBeNull();
});

it('round-trips apiKeyExpiresAt as string', function () {
    AppSettings::setApiKeyExpiresAt('2026-05-01T00:00:00Z');
    expect(AppSettings::apiKeyExpiresAt())->toBe('2026-05-01T00:00:00Z');
});

it('round-trips decksGroupedByArchetype', function () {
    expect(AppSettings::decksGroupedByArchetype())->toBeFalse();

    AppSettings::setDecksGroupedByArchetype(true);
    expect(AppSettings::decksGroupedByArchetype())->toBeTrue();
});

it('defaults the draft notes window on', function () {
    expect(AppSettings::showDraftNotesWindow())->toBeTrue();
});

it('round-trips the draft notes window setting', function () {
    AppSettings::setShowDraftNotesWindow(false);
    expect(AppSettings::showDraftNotesWindow())->toBeFalse();
    expect(AppSettings::get('draft_notes_window'))->toBeFalse();

    AppSettings::setShowDraftNotesWindow(true);
    expect(AppSettings::showDraftNotesWindow())->toBeTrue();
});
