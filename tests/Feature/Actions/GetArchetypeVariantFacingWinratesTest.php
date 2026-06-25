<?php

use App\Actions\Archetypes\GetArchetypeVariantFacingWinrates;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createMatch(array $attributes = []): MtgoMatch
{
    return MtgoMatch::create(array_merge([
        'token' => fake()->uuid(),
        'mtgo_id' => fake()->unique()->numerify('######'),
        'format' => 'modern',
        'match_type' => 'league',
        'state' => 'complete',
        'outcome' => 'win',
        'started_at' => now(),
        'ended_at' => now(),
    ], $attributes));
}

function recordFacingMatch(Archetype $archetype, ArchetypeDeck $deck, bool $localWon): void
{
    $match = createMatch([
        'outcome' => $localWon ? 'win' : 'loss',
    ]);

    MatchArchetype::create([
        'archetype_id' => $archetype->id,
        'archetype_deck_id' => $deck->id,
        'mtgo_match_id' => $match->id,
        'is_opponent' => true,
        'confidence' => 0.9,
    ]);
}

it('returns an empty map when no matches exist', function () {
    $archetype = Archetype::factory()->create();

    $result = GetArchetypeVariantFacingWinrates::run($archetype);

    expect($result)->toBe([]);
});

it('buckets facing wins and losses by archetype_deck_id', function () {
    $archetype = Archetype::factory()->create();
    $variantA = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 2]);
    $variantB = ArchetypeDeck::factory()->for($archetype)->create(['seen_count' => 1]);

    recordFacingMatch($archetype, $variantA, localWon: true);
    recordFacingMatch($archetype, $variantA, localWon: false);
    recordFacingMatch($archetype, $variantB, localWon: true);

    $result = GetArchetypeVariantFacingWinrates::run($archetype);

    expect($result)->toHaveKey($variantA->id)
        ->and($result[$variantA->id])->toBe(['winrate' => 50, 'wins' => 1, 'losses' => 1])
        ->and($result)->toHaveKey($variantB->id)
        ->and($result[$variantB->id])->toBe(['winrate' => 100, 'wins' => 1, 'losses' => 0]);
});

it('excludes match_archetypes rows with null archetype_deck_id', function () {
    $archetype = Archetype::factory()->create();

    $match = createMatch();
    MatchArchetype::create([
        'archetype_id' => $archetype->id,
        'archetype_deck_id' => null,
        'mtgo_match_id' => $match->id,
        'is_opponent' => true,
        'confidence' => 0.9,
    ]);

    expect(GetArchetypeVariantFacingWinrates::run($archetype))->toBe([]);
});

it('excludes match_archetypes rows for the local player (no playing winrate)', function () {
    $archetype = Archetype::factory()->create();
    $variant = ArchetypeDeck::factory()->for($archetype)->create();

    $match = createMatch();
    MatchArchetype::create([
        'archetype_id' => $archetype->id,
        'archetype_deck_id' => $variant->id,
        'mtgo_match_id' => $match->id,
        'is_opponent' => false,
        'confidence' => 0.9,
    ]);

    expect(GetArchetypeVariantFacingWinrates::run($archetype))->toBe([]);
});

it('rounds winrate to nearest integer', function () {
    $archetype = Archetype::factory()->create();
    $variant = ArchetypeDeck::factory()->for($archetype)->create();

    recordFacingMatch($archetype, $variant, localWon: true);
    recordFacingMatch($archetype, $variant, localWon: true);
    recordFacingMatch($archetype, $variant, localWon: false);

    $result = GetArchetypeVariantFacingWinrates::run($archetype);

    expect($result[$variant->id])->toBe(['winrate' => 67, 'wins' => 2, 'losses' => 1]);
});

it('ignores matches that are not complete', function () {
    $archetype = Archetype::factory()->create();
    $variant = ArchetypeDeck::factory()->for($archetype)->create();

    $match = createMatch(['state' => 'in_progress', 'outcome' => null]);
    MatchArchetype::create([
        'archetype_id' => $archetype->id,
        'archetype_deck_id' => $variant->id,
        'mtgo_match_id' => $match->id,
        'is_opponent' => true,
        'confidence' => 0.9,
    ]);

    expect(GetArchetypeVariantFacingWinrates::run($archetype))->toBe([]);
});
