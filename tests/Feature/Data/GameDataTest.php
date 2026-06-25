<?php

use App\Data\Front\GameData;
use App\Models\Game;
use App\Models\GameDeck;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes per-game scalar columns', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create([
        'match_id' => $match->id,
        'local_on_play' => true,
        'local_mulligans' => 2,
        'opp_mulligans' => 1,
        'local_dice' => 6,
        'opp_dice' => 3,
    ]);

    $data = GameData::from($game);

    expect($data->id)->toBe($game->id);
    expect($data->localOnPlay)->toBeTrue();
    expect($data->localMulligans)->toBe(2);
    expect($data->opponentMulligans)->toBe(1);
    expect($data->localDice)->toBe(6);
    expect($data->opponentDice)->toBe(3);
});

it('defaults mulligan counts to zero when null', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create([
        'match_id' => $match->id,
        'local_mulligans' => null,
        'opp_mulligans' => null,
    ]);

    $data = GameData::from($game);

    expect($data->localMulligans)->toBe(0);
    expect($data->opponentMulligans)->toBe(0);
});

it('exposes null localOnPlay and dice when not set', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create([
        'match_id' => $match->id,
        'local_on_play' => null,
        'local_dice' => null,
        'opp_dice' => null,
    ]);

    $data = GameData::from($game);

    expect($data->localOnPlay)->toBeNull();
    expect($data->localDice)->toBeNull();
    expect($data->opponentDice)->toBeNull();
});

it('does not expose a players field', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id]);

    $data = GameData::from($game);

    expect($data)->not->toHaveProperty('players');
});

it('exposes localDeck and opponentDeck from game_decks rows', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id]);

    $localCards = [['name' => 'Lightning Bolt', 'quantity' => 4]];
    $oppCards = [['name' => 'Counterspell', 'quantity' => 2]];

    GameDeck::factory()->create([
        'game_id' => $game->id,
        'is_opponent' => false,
        'deck_json' => $localCards,
    ]);

    GameDeck::factory()->opponent()->create([
        'game_id' => $game->id,
        'deck_json' => $oppCards,
    ]);

    $game->load('decks');
    $array = GameData::from($game)->include('localDeck', 'opponentDeck')->toArray();

    expect($array['localDeck'])->toBe($localCards);
    expect($array['opponentDeck'])->toBe($oppCards);
});
