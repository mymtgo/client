<?php

use App\Actions\Decks\GetPeerArchetypeChartData;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeArchetypeChartDeck(Account $account, ?Archetype $archetype = null): array
{
    $deck = Deck::factory()->create([
        'account_id' => $account->id,
        'archetype_id' => $archetype?->id,
    ]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    return [$deck, $version];
}

beforeEach(function () {
    $this->account = Account::create(['username' => 'tester', 'active' => true, 'tracked' => true]);
});

it('returns null when deck has no archetype', function () {
    [$deck] = makeArchetypeChartDeck($this->account, null);

    $result = GetPeerArchetypeChartData::run($deck, now()->subMonth(), now());

    expect($result)->toBeNull();
});

it('returns null when no peer decks share the archetype', function () {
    $archetype = Archetype::factory()->create();
    [$deck] = makeArchetypeChartDeck($this->account, $archetype);

    $result = GetPeerArchetypeChartData::run($deck, now()->subMonth(), now());

    expect($result)->toBeNull();
});

it('aggregates daily wins/losses across peer decks excluding the current deck', function () {
    $archetype = Archetype::factory()->create(['name' => 'Mono Red']);
    [$currentDeck] = makeArchetypeChartDeck($this->account, $archetype);
    [, $peerVersionA] = makeArchetypeChartDeck($this->account, $archetype);
    [, $peerVersionB] = makeArchetypeChartDeck($this->account, $archetype);

    $today = now()->startOfDay();

    MtgoMatch::factory()->won()->create([
        'deck_version_id' => $peerVersionA->id,
        'started_at' => $today,
    ]);
    MtgoMatch::factory()->lost()->create([
        'deck_version_id' => $peerVersionA->id,
        'started_at' => $today,
    ]);
    MtgoMatch::factory()->won()->create([
        'deck_version_id' => $peerVersionB->id,
        'started_at' => $today,
    ]);

    $result = GetPeerArchetypeChartData::run($currentDeck, now()->subMonth(), now()->endOfDay());

    expect($result)->not->toBeNull();
    expect($result['archetypeName'])->toBe('Mono Red');
    expect($result['deckCount'])->toBe(2);
    expect($result['data'])->toHaveCount(1);
    expect($result['data'][0]['wins'])->toBe(2);
    expect($result['data'][0]['losses'])->toBe(1);
});

it('does not include the current deck in peer aggregation', function () {
    $archetype = Archetype::factory()->create();
    [$currentDeck, $currentVersion] = makeArchetypeChartDeck($this->account, $archetype);
    [, $peerVersion] = makeArchetypeChartDeck($this->account, $archetype);

    MtgoMatch::factory()->won()->create([
        'deck_version_id' => $currentVersion->id,
        'started_at' => now(),
    ]);
    MtgoMatch::factory()->lost()->create([
        'deck_version_id' => $peerVersion->id,
        'started_at' => now(),
    ]);

    $result = GetPeerArchetypeChartData::run($currentDeck, now()->subMonth(), now()->endOfDay());

    expect($result['data'])->toHaveCount(1);
    expect($result['data'][0]['wins'])->toBe(0);
    expect($result['data'][0]['losses'])->toBe(1);
});

it('returns null when peers exist but have no matches in range', function () {
    $archetype = Archetype::factory()->create();
    [$currentDeck] = makeArchetypeChartDeck($this->account, $archetype);
    [, $peerVersion] = makeArchetypeChartDeck($this->account, $archetype);

    MtgoMatch::factory()->won()->create([
        'deck_version_id' => $peerVersion->id,
        'started_at' => now()->subYear(),
    ]);

    $result = GetPeerArchetypeChartData::run($currentDeck, now()->subMonth(), now());

    expect($result)->toBeNull();
});
