<?php

use App\Actions\Reports\GetReportFormatOptions;
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

it('returns formats that have completed matches for archetype + active account', function () {
    $account = Account::create(['username' => 'player-a', 'active' => true, 'tracked' => true]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['account_id' => $account->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'format' => 'CModern', 'state' => 'complete']);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'format' => 'CPioneer', 'state' => 'complete']);

    $result = GetReportFormatOptions::run($archetype->id);

    expect($result->pluck('value')->all())->toBe(['CModern', 'CPioneer'])
        ->and($result->firstWhere('value', 'CModern')['label'])->toBe('Modern')
        ->and($result->firstWhere('value', 'CPioneer')['label'])->toBe('Pioneer');
});

it('returns empty when archetype has no completed matches', function () {
    $account = Account::create(['username' => 'player-b', 'active' => true, 'tracked' => true]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create();
    expect(GetReportFormatOptions::run($archetype->id))->toBeEmpty();
});

it('excludes incomplete matches', function () {
    $account = Account::create(['username' => 'player-c', 'active' => true, 'tracked' => true]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['account_id' => $account->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'format' => 'CModern', 'state' => 'in_progress']);

    expect(GetReportFormatOptions::run($archetype->id))->toBeEmpty();
});

it('respects active account scope', function () {
    $accountA = Account::create(['username' => 'player-d', 'active' => true, 'tracked' => true]);
    $accountB = Account::create(['username' => 'player-e', 'active' => false, 'tracked' => true]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create();
    $deckOther = Deck::factory()->create(['account_id' => $accountB->id, 'archetype_id' => $archetype->id]);
    $versionOther = DeckVersion::factory()->create(['deck_id' => $deckOther->id]);
    MtgoMatch::factory()->create(['deck_version_id' => $versionOther->id, 'format' => 'CModern', 'state' => 'complete']);

    expect(GetReportFormatOptions::run($archetype->id))->toBeEmpty();
});

it('deduplicates repeated formats', function () {
    $account = Account::create(['username' => 'main', 'active' => true]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['account_id' => $account->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    MtgoMatch::factory()->count(3)->create(['deck_version_id' => $version->id, 'format' => 'CModern', 'state' => 'complete']);

    $result = GetReportFormatOptions::run($archetype->id);

    expect($result)->toHaveCount(1)
        ->and($result[0]['value'])->toBe('CModern');
});
