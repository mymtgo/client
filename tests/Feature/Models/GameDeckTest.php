<?php

use App\Models\Game;
use App\Models\GameDeck;
use App\Models\MtgoMatch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores a deck snapshot per game side', function () {
    $game = Game::factory()->create(['match_id' => MtgoMatch::factory()]);

    $local = GameDeck::factory()->create([
        'game_id' => $game->id,
        'is_opponent' => false,
        'deck_json' => ['Lightning Bolt' => 4],
    ]);
    $opponent = GameDeck::factory()->opponent()->create(['game_id' => $game->id]);

    expect($game->decks)->toHaveCount(2);
    expect($local->deck_json)->toBe(['Lightning Bolt' => 4]);
    expect($opponent->is_opponent)->toBeTrue();
    expect($local->game->id)->toBe($game->id);
});

it('enforces one deck per game side', function () {
    $game = Game::factory()->create(['match_id' => MtgoMatch::factory()]);
    GameDeck::factory()->create(['game_id' => $game->id, 'is_opponent' => false]);
    GameDeck::factory()->create(['game_id' => $game->id, 'is_opponent' => false]);
})->throws(QueryException::class);
