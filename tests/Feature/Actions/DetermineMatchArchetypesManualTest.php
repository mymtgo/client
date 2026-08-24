<?php

use App\Actions\DetermineMatchArchetypes;
use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

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

it('recomputes the local player row when only the opponent is manual, leaving the opponent untouched', function () {
    Http::fake([
        '*/api/archetypes/estimate' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    // The local player's deck matches a real archetype deck, so detection has
    // something concrete to (re)compute for them.
    $localArchetype = Archetype::factory()->create(['format' => 'modern', 'decklist_downloaded_at' => now()]);
    $card = Card::factory()->create(['mtgo_id' => 99001, 'oracle_id' => 'oracle-manual-local-check']);
    $deck = ArchetypeDeck::factory()->for($localArchetype)->create();
    $deck->cards()->attach($card->id, ['quantity' => 4, 'sideboard' => false]);

    $match = MtgoMatch::create([
        'mtgo_id' => '900002',
        'token' => 'mt-manual-local',
        'format' => 'CModern',
        'match_type' => 'League',
        'state' => MatchState::Complete,
        'started_at' => now(),
    ]);

    $opponent = Player::create(['username' => 'opp2']);
    $local = Player::create(['username' => 'me2']);

    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => 'g-manual-local',
        'started_at' => now(),
    ]);

    $game->players()->attach($opponent->id, ['is_local' => 0, 'instance_id' => 'i-2', 'deck_json' => []]);
    $game->players()->attach($local->id, ['is_local' => 1, 'instance_id' => 'i-1', 'deck_json' => [['mtgo_id' => 99001, 'quantity' => 4]]]);

    $pickedOpponentArchetype = Archetype::factory()->create(['name' => 'Manual Opp Pick', 'format' => 'modern']);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $pickedOpponentArchetype->id,
        'confidence' => 1.0,
        'manual' => true,
    ]);

    DetermineMatchArchetypes::run($match->fresh(['games.players']));

    $opponentRows = MatchArchetype::where('mtgo_match_id', $match->id)->where('player_id', $opponent->id)->get();
    $localRows = MatchArchetype::where('mtgo_match_id', $match->id)->where('player_id', $local->id)->get();

    expect($opponentRows)->toHaveCount(1);
    expect($opponentRows->first()->manual)->toBeTrue();
    expect($opponentRows->first()->archetype_id)->toBe($pickedOpponentArchetype->id);

    expect($localRows)->toHaveCount(1);
    expect($localRows->first()->manual)->toBeFalse();
    expect($localRows->first()->archetype_id)->toBe($localArchetype->id);
});

it('recreates a non-manual row with a freshly detected archetype', function () {
    Http::fake([
        '*/api/archetypes/estimate' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    $freshArchetype = Archetype::factory()->create(['format' => 'modern', 'decklist_downloaded_at' => now()]);
    $card = Card::factory()->create(['mtgo_id' => 99002, 'oracle_id' => 'oracle-recreate-check']);
    $deck = ArchetypeDeck::factory()->for($freshArchetype)->create();
    $deck->cards()->attach($card->id, ['quantity' => 4, 'sideboard' => false]);

    $match = MtgoMatch::create([
        'mtgo_id' => '900003',
        'token' => 'mt-recreate',
        'format' => 'CModern',
        'match_type' => 'League',
        'state' => MatchState::Complete,
        'started_at' => now(),
    ]);

    $opponent = Player::create(['username' => 'opp3']);
    $local = Player::create(['username' => 'me3']);

    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => 'g-recreate',
        'started_at' => now(),
    ]);

    $game->players()->attach($opponent->id, ['is_local' => 0, 'instance_id' => 'i-2', 'deck_json' => [['mtgo_id' => 99002, 'quantity' => 4]]]);
    $game->players()->attach($local->id, ['is_local' => 1, 'instance_id' => 'i-1', 'deck_json' => []]);

    $stale = Archetype::factory()->create(['name' => 'Stale Guess', 'format' => 'modern']);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $opponent->id,
        'archetype_id' => $stale->id,
        'confidence' => 0.4,
        'manual' => false,
    ]);

    DetermineMatchArchetypes::run($match->fresh(['games.players']));

    $rows = MatchArchetype::where('mtgo_match_id', $match->id)->where('player_id', $opponent->id)->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->archetype_id)->toBe($freshArchetype->id);
    expect($rows->first()->manual)->toBeFalse();
});
