<?php

use App\Actions\DetermineMatchArchetypes;
use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function manualArchetypeMatch(): array
{
    $match = MtgoMatch::create([
        'mtgo_id' => '900001',
        'token' => 'mt-manual',
        'format' => 'CModern',
        'match_type' => 'League',
        'state' => MatchState::Complete,
        'started_at' => now(),
    ]);

    $opponent = Player::create(['username' => 'opp']);
    $local = Player::create(['username' => 'me']);

    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => 'g-manual',
        'started_at' => now(),
    ]);

    $game->players()->attach($opponent->id, ['is_local' => 0, 'instance_id' => 'i-2', 'deck_json' => []]);
    $game->players()->attach($local->id, ['is_local' => 1, 'instance_id' => 'i-1', 'deck_json' => []]);

    return [$match, $opponent];
}

it('preserves a manual opponent archetype through detection', function () {
    [$match, $opponent] = manualArchetypeMatch();

    $picked = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $picked->id,
        'confidence' => 1.0,
        'manual' => true,
    ]);

    DetermineMatchArchetypes::run($match->fresh(['games.players']));

    $rows = MatchArchetype::where('mtgo_match_id', $match->id)
        ->where('player_id', $opponent->id)
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->archetype_id)->toBe($picked->id);
    expect($rows->first()->manual)->toBeTrue();
});

it('still deletes and recreates non-manual rows', function () {
    [$match, $opponent] = manualArchetypeMatch();

    $stale = Archetype::factory()->create(['name' => 'Stale Guess', 'format' => 'modern']);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $stale->id,
        'confidence' => 0.4,
        'manual' => false,
    ]);

    DetermineMatchArchetypes::run($match->fresh(['games.players']));

    expect(MatchArchetype::where('mtgo_match_id', $match->id)->where('archetype_id', $stale->id)->exists())
        ->toBeFalse();
});
