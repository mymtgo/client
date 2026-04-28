<?php

use App\Facades\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs deck sync without error when data path is empty', function () {
    $emptyDir = sys_get_temp_dir().'/setup-sync-'.uniqid();
    mkdir($emptyDir);
    AppSettings::setLogDataPath($emptyDir);

    $this->post('/setup/decks/sync')
        ->assertRedirect('/setup')
        ->assertSessionMissing('setup_error_decks');
});

it('flashes error when sync fails', function () {
    // Create a directory structure that GetDeckFiles will traverse:
    // a base dir with a subdirectory containing a user_settings file and
    // an invalid XML file matching the deck filename pattern.
    $baseDir = sys_get_temp_dir().'/setup-sync-fail-'.uniqid();
    $accountDir = $baseDir.'/abc123';
    mkdir($accountDir, 0755, true);
    file_put_contents($accountDir.'/user_settings', '');
    $deckUuid = '00000000-0000-0000-0000-000000000000';
    file_put_contents($accountDir."/grouping {$deckUuid}.xml", '<<invalid xml>>');

    AppSettings::setLogDataPath($baseDir);

    $this->post('/setup/decks/sync')
        ->assertRedirect('/setup')
        ->assertSessionHas('setup_error_decks');
});

it('skips deck sync', function () {
    $this->post('/setup/decks/skip')
        ->assertRedirect('/setup');

    expect(AppSettings::setupSkippedDecks())->toBeTrue();
});
