<?php

use App\Actions\Leagues\DeckFitsLeaguePool;
use App\Enums\LeagueKind;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function leagueWithPool(array $catalogIds): League
{
    $league = League::factory()->create(['kind' => LeagueKind::Draft]);
    $draft = Draft::factory()->for($league)->create();
    foreach (array_values($catalogIds) as $i => $id) {
        DraftPick::factory()->for($draft)->create(['ordinal' => $i + 1, 'picked_catalog_id' => $id]);
    }

    return $league;
}

/**
 * @param  array<int, int>  $catalogIds
 */
function makeKnownSpells(array $catalogIds): void
{
    foreach ($catalogIds as $id) {
        Card::factory()->create(['mtgo_id' => (string) $id, 'type' => 'Creature']);
    }
}

it('accepts a deck built from the pool plus basics', function () {
    $pool = range(1000, 1041);
    $league = leagueWithPool($pool);
    makeKnownSpells(array_slice($pool, 0, 23));
    Card::factory()->create(['mtgo_id' => '153894', 'type' => 'Basic Land']);

    $main = array_fill_keys(array_slice($pool, 0, 23), 1) + ['153894' => 17];

    expect(DeckFitsLeaguePool::run($league, $main))->toBeTrue();
});

it('rejects a deck from a different draft', function () {
    $league = leagueWithPool(range(1000, 1041));
    makeKnownSpells(range(2000, 2022));
    Card::factory()->create(['mtgo_id' => '153894', 'type' => 'Basic Land']);

    $main = array_fill_keys(range(2000, 2022), 1) + ['153894' => 17];

    expect(DeckFitsLeaguePool::run($league, $main))->toBeFalse();
});

it('accepts when the league has no draft to compare against', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft]);

    expect(DeckFitsLeaguePool::run($league, [1 => 40]))->toBeTrue();
});

it('accepts a deck with unresolved catalog ids alongside fitting spells', function () {
    // Card enrichment runs asynchronously: a catalog id with no Card row
    // yet, or a Card row whose type hasn't been enriched, must be neutral,
    // dropped from both sides of the coverage ratio, not counted against it.
    // Eight unresolved ids would drag a fully-covered 23-spell deck below
    // the 0.75 threshold (23 / 31 ≈ 0.74) if they counted as uncovered, so
    // this only passes when they are excluded from the ratio entirely.
    $pool = range(1000, 1022);
    $league = leagueWithPool($pool);
    makeKnownSpells($pool);
    Card::factory()->create(['mtgo_id' => '153894', 'type' => 'Basic Land']);
    Card::factory()->stub()->create(['mtgo_id' => '160000']);

    $unresolved = array_fill_keys(range(9000, 9007), 1);
    $main = array_fill_keys($pool, 1)
        + ['153894' => 17]
        + $unresolved
        + ['160000' => 1];

    expect(DeckFitsLeaguePool::run($league, $main))->toBeTrue();
});
