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

it('localDeck returns the non-opponent GameDeck', function () {
    $game = Game::factory()->create(['match_id' => MtgoMatch::factory()]);
    GameDeck::factory()->create(['game_id' => $game->id, 'is_opponent' => false, 'deck_json' => ['Counterspell' => 4]]);
    GameDeck::factory()->create(['game_id' => $game->id, 'is_opponent' => true, 'deck_json' => ['Lightning Bolt' => 4]]);

    $localDeck = $game->localDeck();

    expect($localDeck)->not->toBeNull()
        ->and($localDeck)->toBeInstanceOf(GameDeck::class)
        ->and($localDeck->is_opponent)->toBeFalse()
        ->and($localDeck->deck_json)->toBe(['Counterspell' => 4]);
});

it('opponentDeck returns the opponent GameDeck', function () {
    $game = Game::factory()->create(['match_id' => MtgoMatch::factory()]);
    GameDeck::factory()->create(['game_id' => $game->id, 'is_opponent' => false, 'deck_json' => ['Counterspell' => 4]]);
    GameDeck::factory()->create(['game_id' => $game->id, 'is_opponent' => true, 'deck_json' => ['Lightning Bolt' => 4]]);

    $opponentDeck = $game->opponentDeck();

    expect($opponentDeck)->not->toBeNull()
        ->and($opponentDeck)->toBeInstanceOf(GameDeck::class)
        ->and($opponentDeck->is_opponent)->toBeTrue()
        ->and($opponentDeck->deck_json)->toBe(['Lightning Bolt' => 4]);
});

it('localDeck returns null when no local deck exists', function () {
    $game = Game::factory()->create(['match_id' => MtgoMatch::factory()]);

    expect($game->localDeck())->toBeNull();
});

it('opponentDeck returns null when no opponent deck exists', function () {
    $game = Game::factory()->create(['match_id' => MtgoMatch::factory()]);

    expect($game->opponentDeck())->toBeNull();
});
