<?php

use App\Actions\Reports\GetReportDeckVersionIds;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Account::flushCurrent();
});

it('returns versions whose matches match archetype + format, ignoring others', function () {
    $account = Account::create(['username' => 'main', 'active' => true]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['account_id' => $account->id, 'archetype_id' => $archetype->id]);
    $included = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $excludedVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    MtgoMatch::factory()->create([
        'deck_version_id' => $included->id,
        'format' => 'CModern',
        'state' => 'complete',
    ]);
    MtgoMatch::factory()->create([
        'deck_version_id' => $excludedVersion->id,
        'format' => 'CPioneer',
        'state' => 'complete',
    ]);

    expect(GetReportDeckVersionIds::run($archetype->id, 'CModern', null, null))->toBe([$included->id]);
});

it('respects timeframe bounds', function () {
    $account = Account::create(['username' => 'main', 'active' => true]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['account_id' => $account->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'format' => 'CModern',
        'state' => 'complete',
        'started_at' => now()->subDays(20),
    ]);

    $result = GetReportDeckVersionIds::run($archetype->id, 'CModern', now()->subDays(7), now());

    expect($result)->toBe([]);
});

it('respects active account scope', function () {
    $accountA = Account::create(['username' => 'A', 'active' => true]);
    $accountB = Account::create(['username' => 'B', 'active' => false]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create();
    $deckOther = Deck::factory()->create(['account_id' => $accountB->id, 'archetype_id' => $archetype->id]);
    $versionOther = DeckVersion::factory()->create(['deck_id' => $deckOther->id]);
    MtgoMatch::factory()->create([
        'deck_version_id' => $versionOther->id,
        'format' => 'CModern',
        'state' => 'complete',
    ]);

    expect(GetReportDeckVersionIds::run($archetype->id, 'CModern', null, null))->toBe([]);
});

it('includes soft-deleted decks', function () {
    $account = Account::create(['username' => 'main', 'active' => true]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['account_id' => $account->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'format' => 'CModern', 'state' => 'complete']);
    $deck->delete();

    expect(GetReportDeckVersionIds::run($archetype->id, 'CModern', null, null))->toBe([$version->id]);
});

it('deduplicates when a version has multiple matching matches', function () {
    $account = Account::create(['username' => 'main', 'active' => true]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['account_id' => $account->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    MtgoMatch::factory()->count(3)->create([
        'deck_version_id' => $version->id,
        'format' => 'CModern',
        'state' => 'complete',
    ]);

    expect(GetReportDeckVersionIds::run($archetype->id, 'CModern', null, null))->toBe([$version->id]);
});
