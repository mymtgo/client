<?php

use App\Actions\Limited\Read\BuildLimitedCardRows;
use App\Actions\Limited\Read\BuildLimitedIndex;
use App\Actions\Limited\Read\CountSealedPacks;
use App\Actions\Limited\Read\GetLimitedEventSharedProps;
use App\Actions\Limited\Read\ReadSealedPool;
use App\Enums\LeagueKind;
use App\Enums\MatchState;
use App\Models\Card;
use App\Models\League;
use App\Models\LimitedDeckSnapshot;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A sealed run of six FRA boosters. Each registered deck holds the whole
 * pool (the sideboard is every card not played). With $boosterAdded the
 * second deck also holds the booster added after match 3.
 */
function sealedPoolLeague(bool $boosterAdded = true): League
{
    $league = League::factory()->create(['kind' => LeagueKind::Sealed, 'format' => 'S6FRA', 'set_code' => 'FRA', 'started_at' => now()->subHour()]);
    Card::factory()->create(['mtgo_id' => '1', 'oracle_id' => 'bard', 'name' => 'Bard', 'colors' => 'W', 'type' => 'Creature', 'rarity' => 'common', 'set_name' => 'Fraternity', 'art_crop' => 'https://img/bard.jpg']);
    Card::factory()->create(['mtgo_id' => '2', 'oracle_id' => 'harper', 'name' => 'Harper', 'colors' => 'U', 'type' => 'Creature', 'rarity' => 'rare', 'set_name' => 'Fraternity', 'art_crop' => 'https://img/harper.jpg']);
    Card::factory()->create(['mtgo_id' => '6', 'oracle_id' => 'boosted', 'name' => 'Boosted', 'colors' => 'R', 'type' => 'Creature', 'rarity' => 'common', 'set_name' => 'Fraternity']);
    Card::factory()->create(['mtgo_id' => '9', 'oracle_id' => 'island', 'name' => 'Island', 'colors' => '', 'type' => 'Basic Land']);

    $m1 = MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'started_at' => now()->subMinutes(50)]);
    $first = [['catalog_id' => 1, 'quantity' => 1, 'sideboard' => false], ['catalog_id' => 9, 'quantity' => 17, 'sideboard' => false], ['catalog_id' => 2, 'quantity' => 1, 'sideboard' => true]];
    LimitedDeckSnapshot::create(['league_id' => $league->id, 'match_id' => $m1->id, 'source' => 'registered', 'signature' => 'p1', 'captured_at' => now()->subMinutes(50), 'cards' => $first]);

    if ($boosterAdded) {
        $m4 = MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'started_at' => now()->subMinutes(20)]);
        $later = [...$first, ['catalog_id' => 6, 'quantity' => 1, 'sideboard' => true]];
        LimitedDeckSnapshot::create(['league_id' => $league->id, 'match_id' => $m4->id, 'source' => 'registered', 'signature' => 'p2', 'captured_at' => now()->subMinutes(20), 'cards' => $later]);
    }

    return $league;
}

it('reads the sealed pool without basics and marks booster copies as added', function () {
    $result = ReadSealedPool::run(sealedPoolLeague());

    expect($result['pool'])->toBe([1 => 1, 2 => 1, 6 => 1])
        ->and($result['added'])->toBe([6 => 1]);
});

it('reads an empty pool before any deck is registered', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Sealed, 'format' => 'S6FRA']);

    expect(ReadSealedPool::run($league))->toBe(['pool' => [], 'added' => []]);
});

it('counts the boosters a sealed pool was opened from', function (bool $boosterAdded, int $packs) {
    expect(CountSealedPacks::run(sealedPoolLeague($boosterAdded)))->toBe($packs);
})->with([
    'six boosters' => [false, 6],
    'plus the added booster' => [true, 7],
]);

it('reads the booster count from either format spelling', function (string $format, ?int $packs) {
    $league = League::factory()->create(['kind' => LeagueKind::Sealed, 'format' => $format]);

    expect(CountSealedPacks::run($league))->toBe($packs);
})->with([
    'match code' => ['S6FRA', 6],
    'league panel' => ['FRAx6', 6],
    'unknown' => ['Sealed', null],
]);

it('has no pack count for a draft', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'format' => 'DFRAFRAFRA']);

    expect(CountSealedPacks::run($league))->toBeNull();
});

it('gives the sealed sidebar a pack count, set name and cover from the pool', function () {
    $event = GetLimitedEventSharedProps::run(sealedPoolLeague())['event'];

    expect($event->packs)->toBe(7)
        ->and($event->setName)->toBe('Fraternity')
        ->and($event->coverArt)->toBe('https://img/harper.jpg');
});

it('gives the sealed index row a pack count', function () {
    sealedPoolLeague();

    $row = collect(BuildLimitedIndex::run(null, null, now()->subYear(), now()->addDay())['rows'])->first();

    expect($row->packs)->toBe(7)
        ->and($row->results)->toHaveCount(6);
});

it('builds one card row per sealed pool card, tagging the added booster', function () {
    $result = BuildLimitedCardRows::run(sealedPoolLeague());

    $rows = collect($result['rows'])->keyBy('catalogId');

    expect($rows->keys()->sort()->values()->all())->toBe([1, 2, 6])
        ->and($rows[1])->toMatchArray(['status' => 'main', 'added' => false, 'labels' => []])
        ->and($rows[2])->toMatchArray(['status' => 'side', 'added' => false])
        ->and($rows[6])->toMatchArray(['status' => 'side', 'added' => true])
        ->and($result['summary']['distinct'])->toBe(3)
        ->and($result['cards'])->toHaveKeys(['1', '2', '6']);
});
