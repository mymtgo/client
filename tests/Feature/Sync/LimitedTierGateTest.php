<?php

use App\Facades\AppSettings;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Services\Sync\DirtyRows;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('treats a missing tier as free', function () {
    expect(AppSettings::isSupporter())->toBeFalse();

    AppSettings::setSyncSlots(['tier' => 'supporter', 'limit' => null, 'used' => 0, 'decks' => []]);

    expect(AppSettings::isSupporter())->toBeTrue();
});

it('keeps a free account limited deck out of the dirty set', function () {
    AppSettings::setSyncSlots(['tier' => 'free', 'limit' => 1, 'used' => 0, 'decks' => []]);

    $limited = Deck::factory()->create(['mtgo_id' => 'limited:abc', 'format' => 'Limited']);
    $constructed = Deck::factory()->create(['mtgo_id' => '110186502', 'format' => 'Modern']);

    $ids = DirtyRows::query('deck')->pluck('id');

    expect($ids)->toContain($constructed->id)
        ->and($ids)->not->toContain($limited->id)
        ->and(DirtyRows::knownIds('deck'))->not->toContain('limited_abc');
});

it('includes a supporter limited deck in the dirty set', function () {
    AppSettings::setSyncSlots(['tier' => 'supporter', 'limit' => null, 'used' => 0, 'decks' => []]);

    $limited = Deck::factory()->create(['mtgo_id' => 'limited:abc', 'format' => 'Limited']);

    expect(DirtyRows::query('deck')->pluck('id'))->toContain($limited->id);
});

it('carries a supporter limited league, draft and picks in the league bundle', function () {
    AppSettings::setSyncSlots(['tier' => 'supporter', 'limit' => null, 'used' => 0, 'decks' => []]);

    // The draft and every pick ride the league bundle, so a supporter's
    // limited event must reach the dirty league set exactly like a
    // constructed one does.
    $deck = Deck::factory()->create(['mtgo_id' => 'limited:abc', 'format' => 'Limited', 'cloud_sync_enabled' => true]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $league = League::factory()->create(['deck_version_id' => $version->id]);

    expect(DirtyRows::query('league')->pluck('id'))->toContain($league->id);
});

it('excludes a local-only limited deck from every tier', function () {
    AppSettings::setSyncSlots(['tier' => 'supporter', 'limit' => null, 'used' => 0, 'decks' => []]);

    $local = Deck::factory()->create(['mtgo_id' => 'limited:league-7', 'format' => 'Limited']);

    expect(DirtyRows::query('deck')->pluck('id'))->not->toContain($local->id);
});

it('offers no limited matches or leagues to a free account whose limited deck slot survived a tier lapse', function () {
    // A tier lapse never disables the deck's local flag by itself: only
    // ApplyDeckSyncSlots turns it off, driven by the slot ledger, and a
    // limited slot row survives a lapse by design. Match and league must
    // still stop syncing the moment the tier reads free.
    AppSettings::setSyncSlots(['tier' => 'free', 'limit' => 1, 'used' => 0, 'decks' => []]);

    $deck = Deck::factory()->create(['mtgo_id' => 'limited:abc', 'format' => 'Limited', 'cloud_sync_enabled' => true]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $league = League::factory()->create(['deck_version_id' => $version->id]);
    $match = MtgoMatch::factory()->create(['deck_version_id' => $version->id]);

    expect(DirtyRows::query('league')->pluck('id'))->not->toContain($league->id)
        ->and(DirtyRows::query('match')->pluck('id'))->not->toContain($match->id);
});

it('treats a non-string or unrecognized tier as free', function () {
    $limited = Deck::factory()->create(['mtgo_id' => 'limited:abc', 'format' => 'Limited']);

    // Malformed and unexpected tier values must all fail closed to free,
    // same as a missing tier: an int, an array, and a string the server
    // has never sent.
    foreach ([123, ['nested' => 'array'], 'pro'] as $tier) {
        AppSettings::setSyncSlots(['tier' => $tier, 'limit' => 1, 'used' => 0, 'decks' => []]);

        expect(AppSettings::isSupporter())->toBeFalse()
            ->and(DirtyRows::query('deck')->pluck('id'))->not->toContain($limited->id);
    }
});
