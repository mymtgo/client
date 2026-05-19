<?php

use App\Actions\Reports\GetReportArchetypeOptions;
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

it('returns archetypes with at least one deck and complete match for active account', function () {
    $account = Account::create(['username' => 'player-a', 'active' => true, 'tracked' => true]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create(['name' => 'Mono-Red Aggro', 'color_identity' => 'R']);
    $deck = Deck::factory()->create(['account_id' => $account->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'state' => 'complete']);

    Archetype::factory()->create(['name' => 'No Decks']);

    $result = GetReportArchetypeOptions::run();

    expect($result)->toHaveCount(1)
        ->and($result[0])->toMatchArray([
            'id' => $archetype->id,
            'name' => 'Mono-Red Aggro',
            'colorIdentity' => 'R',
        ]);
});

it('excludes merged-out archetypes', function () {
    $account = Account::create(['username' => 'player-b', 'active' => true, 'tracked' => true]);
    Account::flushCurrent();

    $canonical = Archetype::factory()->create(['name' => 'Canonical']);
    $merged = Archetype::factory()->create(['name' => 'Merged Old', 'merged_into_id' => $canonical->id]);

    $deck = Deck::factory()->create(['account_id' => $account->id, 'archetype_id' => $merged->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'state' => 'complete']);

    $result = GetReportArchetypeOptions::run();

    expect($result->pluck('name')->all())->not->toContain('Merged Old');
});

it('respects active account scope', function () {
    $accountA = Account::create(['username' => 'player-c', 'active' => true, 'tracked' => true]);
    $accountB = Account::create(['username' => 'player-d', 'active' => false, 'tracked' => true]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create(['name' => 'Other Account Only']);
    $deck = Deck::factory()->create(['account_id' => $accountB->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'state' => 'complete']);

    expect(GetReportArchetypeOptions::run())->toBeEmpty();
});

it('excludes archetypes whose decks have no matches', function () {
    $account = Account::create(['username' => 'player-e', 'active' => true, 'tracked' => true]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create(['name' => 'No Matches Yet']);
    $deck = Deck::factory()->create(['account_id' => $account->id, 'archetype_id' => $archetype->id]);
    DeckVersion::factory()->create(['deck_id' => $deck->id]);

    expect(GetReportArchetypeOptions::run())->toBeEmpty();
});

it('includes archetypes whose only contributing deck is soft-deleted', function () {
    $account = Account::create(['username' => 'player-f', 'active' => true, 'tracked' => true]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create(['name' => 'Retired Deck Archetype']);
    $deck = Deck::factory()->create(['account_id' => $account->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'state' => 'complete']);
    $deck->delete();

    expect(GetReportArchetypeOptions::run()->pluck('name')->all())
        ->toContain('Retired Deck Archetype');
});
