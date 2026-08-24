<?php

use App\Actions\Archetypes\AggregateOpponentCards;
use App\Enums\MatchState;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sums opponent quantities across games and caps at four', function () {
    $match = MtgoMatch::create([
        'mtgo_id' => '910001', 'token' => 'mt-agg', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress, 'started_at' => now(),
    ]);

    $opponent = Player::create(['username' => 'opp']);
    $local = Player::create(['username' => 'me']);

    foreach (['g-1', 'g-2'] as $gameId) {
        $game = Game::create(['match_id' => $match->id, 'mtgo_id' => $gameId, 'started_at' => now()]);

        // deck_json must be a plain array, never json_encode(...): Game::players()
        // uses the GamePlayer pivot class, which casts deck_json to 'array', so a
        // pre-encoded string is encoded twice and reads back as a string.
        $game->players()->attach($opponent->id, [
            'is_local' => 0,
            'instance_id' => 'i-2',
            'deck_json' => [
                ['mtgo_id' => 101, 'quantity' => 3],
                ['mtgo_id' => 102, 'quantity' => 1],
            ],
        ]);

        $game->players()->attach($local->id, [
            'is_local' => 1,
            'instance_id' => 'i-1',
            'deck_json' => [['mtgo_id' => 999, 'quantity' => 4]],
        ]);
    }

    $result = AggregateOpponentCards::run($match->fresh(['games.players']));

    expect($result)->toHaveKey($opponent->id);
    expect($result)->not->toHaveKey($local->id);

    $cards = $result[$opponent->id]->keyBy('mtgo_id');

    expect($cards[101]['quantity'])->toBe(4);
    expect($cards[102]['quantity'])->toBe(2);
});

it('returns an empty array when no opponent has revealed cards', function () {
    $match = MtgoMatch::create([
        'mtgo_id' => '910002', 'token' => 'mt-agg-2', 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress, 'started_at' => now(),
    ]);

    expect(AggregateOpponentCards::run($match))->toBe([]);
});
