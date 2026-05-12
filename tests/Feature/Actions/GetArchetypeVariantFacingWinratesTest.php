<?php

use App\Actions\Archetypes\GetArchetypeVariantFacingWinrates;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
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

function recordFacingMatch(Archetype $archetype, ArchetypeDeck $deck, Player $local, Player $opponent, bool $localWon): void
{
    $match = createMatch([
        'outcome' => $localWon ? 'win' : 'loss',
    ]);

    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => fake()->unique()->numerify('######'),
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    $game->players()->attach($local->id, ['is_local' => true, 'instance_id' => 1, 'on_play' => true]);
    $game->players()->attach($opponent->id, ['is_local' => false, 'instance_id' => 2, 'on_play' => false]);

    MatchArchetype::create([
        'archetype_id' => $archetype->id,
        'archetype_deck_id' => $deck->id,
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
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
    $local = Player::create(['username' => 'localuser']);
    $opponent = Player::create(['username' => 'opponent']);

    recordFacingMatch($archetype, $variantA, $local, $opponent, localWon: true);
    recordFacingMatch($archetype, $variantA, $local, $opponent, localWon: false);
    recordFacingMatch($archetype, $variantB, $local, $opponent, localWon: true);

    $result = GetArchetypeVariantFacingWinrates::run($archetype);

    expect($result)->toHaveKey($variantA->id)
        ->and($result[$variantA->id])->toBe(['winrate' => 50, 'wins' => 1, 'losses' => 1])
        ->and($result)->toHaveKey($variantB->id)
        ->and($result[$variantB->id])->toBe(['winrate' => 100, 'wins' => 1, 'losses' => 0]);
});

it('excludes match_archetypes rows with null archetype_deck_id', function () {
    $archetype = Archetype::factory()->create();
    $local = Player::create(['username' => 'localuser']);
    $opponent = Player::create(['username' => 'opponent']);

    $match = createMatch();
    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => fake()->unique()->numerify('######'),
        'started_at' => now(),
        'ended_at' => now(),
    ]);
    $game->players()->attach($local->id, ['is_local' => true, 'instance_id' => 1, 'on_play' => true]);
    $game->players()->attach($opponent->id, ['is_local' => false, 'instance_id' => 2, 'on_play' => false]);

    MatchArchetype::create([
        'archetype_id' => $archetype->id,
        'archetype_deck_id' => null,
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'confidence' => 0.9,
    ]);

    expect(GetArchetypeVariantFacingWinrates::run($archetype))->toBe([]);
});

it('excludes match_archetypes rows for the local player (no playing winrate)', function () {
    $archetype = Archetype::factory()->create();
    $variant = ArchetypeDeck::factory()->for($archetype)->create();
    $local = Player::create(['username' => 'localuser']);
    $opponent = Player::create(['username' => 'opponent']);

    $match = createMatch();
    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => fake()->unique()->numerify('######'),
        'started_at' => now(),
        'ended_at' => now(),
    ]);
    $game->players()->attach($local->id, ['is_local' => true, 'instance_id' => 1, 'on_play' => true]);
    $game->players()->attach($opponent->id, ['is_local' => false, 'instance_id' => 2, 'on_play' => false]);

    MatchArchetype::create([
        'archetype_id' => $archetype->id,
        'archetype_deck_id' => $variant->id,
        'mtgo_match_id' => $match->id,
        'player_id' => $local->id,
        'confidence' => 0.9,
    ]);

    expect(GetArchetypeVariantFacingWinrates::run($archetype))->toBe([]);
});

it('rounds winrate to nearest integer', function () {
    $archetype = Archetype::factory()->create();
    $variant = ArchetypeDeck::factory()->for($archetype)->create();
    $local = Player::create(['username' => 'localuser']);
    $opponent = Player::create(['username' => 'opponent']);

    recordFacingMatch($archetype, $variant, $local, $opponent, localWon: true);
    recordFacingMatch($archetype, $variant, $local, $opponent, localWon: true);
    recordFacingMatch($archetype, $variant, $local, $opponent, localWon: false);

    $result = GetArchetypeVariantFacingWinrates::run($archetype);

    expect($result[$variant->id])->toBe(['winrate' => 67, 'wins' => 2, 'losses' => 1]);
});

it('ignores matches that are not complete', function () {
    $archetype = Archetype::factory()->create();
    $variant = ArchetypeDeck::factory()->for($archetype)->create();
    $local = Player::create(['username' => 'localuser']);
    $opponent = Player::create(['username' => 'opponent']);

    $match = createMatch(['state' => 'in_progress', 'outcome' => null]);
    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => fake()->unique()->numerify('######'),
        'started_at' => now(),
        'ended_at' => now(),
    ]);
    $game->players()->attach($local->id, ['is_local' => true, 'instance_id' => 1, 'on_play' => true]);
    $game->players()->attach($opponent->id, ['is_local' => false, 'instance_id' => 2, 'on_play' => false]);

    MatchArchetype::create([
        'archetype_id' => $archetype->id,
        'archetype_deck_id' => $variant->id,
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'confidence' => 0.9,
    ]);

    expect(GetArchetypeVariantFacingWinrates::run($archetype))->toBe([]);
});
