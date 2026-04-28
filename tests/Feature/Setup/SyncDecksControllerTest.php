<?php

use App\Facades\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('clears the skip flag on successful sync', function () {
    $emptyDir = sys_get_temp_dir().'/setup-sync-'.uniqid();
    mkdir($emptyDir);
    AppSettings::setLogDataPath($emptyDir);
    AppSettings::setSetupSkippedDecks(true);

    $this->post('/setup/decks/sync')
        ->assertRedirect('/setup')
        ->assertSessionMissing('setup_error_decks');

    expect(AppSettings::setupSkippedDecks())->toBeFalse();
});

it('flashes error when sync fails', function () {
    // Create a directory structure that GetDeckFiles will discover:
    // a base dir with a subdirectory containing a user_settings sentinel file
    // (required by GetDeckFiles::findActiveDirectory) and a deck XML file
    // matching the expected filename pattern (grouping {uuid}.xml).
    // The XML has GroupingType="Deck" but an unparseable Timestamp attribute,
    // which causes Carbon::parse() to throw an InvalidFormatException inside
    // SyncDecks::run() — a deterministic, warning-suppression-safe failure.
    $baseDir = sys_get_temp_dir().'/setup-sync-fail-'.uniqid();
    $accountDir = $baseDir.'/abc123';
    mkdir($accountDir, 0755, true);
    file_put_contents($accountDir.'/user_settings', '');
    $deckUuid = '00000000-0000-0000-0000-000000000000';
    $deckXml = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<DeckListData GroupingType="Deck" NetDeckId="1" Name="Test" FormatCode="LEGACY" Timestamp="not-a-real-timestamp">
    <Item CatId="1" Quantity="4" IsSideboard="false"/>
</DeckListData>
XML;
    file_put_contents($accountDir."/grouping {$deckUuid}.xml", $deckXml);

    AppSettings::setLogDataPath($baseDir);

    $this->post('/setup/decks/sync')
        ->assertRedirect('/setup')
        ->assertSessionHas('setup_error_decks', 'Could not sync decks. Make sure your MTGO data path is correct and try again.');
});

it('skips deck sync', function () {
    $this->post('/setup/decks/skip')
        ->assertRedirect('/setup');

    expect(AppSettings::setupSkippedDecks())->toBeTrue();
});
