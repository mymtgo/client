<?php

use App\Actions\Archetypes\GetArchetypeWinrates;
use App\Models\Archetype;
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

it('returns null when no match data exists', function () {
    $archetype = Archetype::factory()->create();

    $result = GetArchetypeWinrates::run($archetype);

    expect($result['playing'])->toBeNull();
    expect($result['facing'])->toBeNull();
});

it('calculates playing-as winrate across multiple matches', function () {
    $archetype = Archetype::factory()->create();
    $localPlayer = Player::create(['username' => 'localuser']);
    $opponent = Player::create(['username' => 'opponent']);

    // Create 3 matches: 2 wins, 1 loss
    foreach ([true, true, false] as $won) {
        $match = createMatch([
            'outcome' => $won ? 'win' : 'loss',
        ]);

        $game = Game::create(['match_id' => $match->id, 'mtgo_id' => fake()->unique()->numerify('######'), 'started_at' => now(), 'ended_at' => now()]);
        $game->players()->attach($localPlayer->id, ['is_local' => true, 'instance_id' => 1, 'on_play' => true]);
        $game->players()->attach($opponent->id, ['is_local' => false, 'instance_id' => 2, 'on_play' => false]);

        MatchArchetype::create([
            'archetype_id' => $archetype->id,
            'mtgo_match_id' => $match->id,
            'player_id' => $localPlayer->id,
            'confidence' => 0.9,
        ]);
    }

    $result = GetArchetypeWinrates::run($archetype);

    expect($result['playing'])->not->toBeNull();
    expect($result['playing']['wins'])->toBe(2);
    expect($result['playing']['losses'])->toBe(1);
    expect($result['playing']['winrate'])->toBe(67);
});

it('calculates facing winrate', function () {
    $archetype = Archetype::factory()->create();
    $localPlayer = Player::create(['username' => 'localuser2']);
    $opponent = Player::create(['username' => 'opponent2']);

    // Create 2 matches where opponent is on this archetype: local wins 1, loses 1
    foreach ([true, false] as $localWon) {
        $match = createMatch([
            'outcome' => $localWon ? 'win' : 'loss',
        ]);

        $game = Game::create(['match_id' => $match->id, 'mtgo_id' => fake()->unique()->numerify('######'), 'started_at' => now(), 'ended_at' => now()]);
        $game->players()->attach($localPlayer->id, ['is_local' => true, 'instance_id' => 1, 'on_play' => true]);
        $game->players()->attach($opponent->id, ['is_local' => false, 'instance_id' => 2, 'on_play' => false]);

        MatchArchetype::create([
            'archetype_id' => $archetype->id,
            'mtgo_match_id' => $match->id,
            'player_id' => $opponent->id,
            'confidence' => 0.9,
        ]);
    }

    $result = GetArchetypeWinrates::run($archetype);

    expect($result['facing'])->not->toBeNull();
    expect($result['facing']['wins'])->toBe(1);
    expect($result['facing']['losses'])->toBe(1);
    expect($result['facing']['winrate'])->toBe(50);
});
