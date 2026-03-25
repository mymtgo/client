<?php

use App\Actions\Matches\BuildMatchGameData;
use App\Models\Card;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('handles missing cards in the card collection without crashing', function () {
    $match = MtgoMatch::create([
        'token' => 'test-token',
        'mtgo_id' => '12345',
        'format' => 'modern',
        'match_type' => 'league',
        'outcome' => 'win',
        'started_at' => now()->subMinutes(30),
        'ended_at' => now(),
    ]);

    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => 'game-1',
        'won' => true,
        'started_at' => now()->subMinutes(20),
        'ended_at' => now()->subMinutes(10),
    ]);

    $localPlayer = Player::create(['username' => 'local_player']);
    $opponentPlayer = Player::create(['username' => 'opponent_player']);

    $game->players()->attach($localPlayer->id, [
        'instance_id' => 1,
        'is_local' => true,
        'on_play' => true,
        'starting_hand_size' => 7,
        'deck_json' => [
            ['mtgo_id' => 9999, 'quantity' => 1, 'sideboard' => false],
        ],
    ]);

    $game->players()->attach($opponentPlayer->id, [
        'instance_id' => 2,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
        'deck_json' => [
            ['mtgo_id' => 8888, 'quantity' => 1],
        ],
    ]);

    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => now()->subMinutes(15),
        'content' => [
            'Players' => [
                ['Id' => 1, 'HandCount' => 7, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 9999, 'Owner' => 1, 'Zone' => 'Hand'],
            ],
        ],
    ]);

    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => now()->subMinutes(14),
        'content' => [
            'Players' => [
                ['Id' => 1, 'HandCount' => 6, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 9999, 'Owner' => 1, 'Zone' => 'Battlefield'],
            ],
        ],
    ]);

    $game = $game->fresh()->load(['players', 'timeline']);

    // Empty card collections — simulates missing/unknown cards
    $cardsByMtgoId = collect();
    $cardsByOracleId = collect();

    $result = BuildMatchGameData::run($game, 1, $cardsByMtgoId, $cardsByOracleId, []);

    expect($result)->toHaveKeys(['id', 'number', 'won', 'keptHand', 'opponentCardsSeen', 'localCardsPlayed']);

    // Card names should use the fallback "Unknown (id)" pattern
    expect($result['opponentCardsSeen'][0]['name'])->toContain('Unknown');
    expect($result['keptHand'][0]['name'])->toContain('Unknown');
});

it('resolves card names when cards exist in collection', function () {
    $match = MtgoMatch::create([
        'token' => 'test-token-2',
        'mtgo_id' => '12346',
        'format' => 'modern',
        'match_type' => 'league',
        'outcome' => 'win',
        'started_at' => now()->subMinutes(30),
        'ended_at' => now(),
    ]);

    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => 'game-2',
        'won' => true,
        'started_at' => now()->subMinutes(20),
        'ended_at' => now()->subMinutes(10),
    ]);

    $localPlayer = Player::create(['username' => 'local_player']);
    $opponentPlayer = Player::create(['username' => 'opponent_player']);

    $game->players()->attach($localPlayer->id, [
        'instance_id' => 1,
        'is_local' => true,
        'on_play' => true,
        'starting_hand_size' => 7,
    ]);

    $game->players()->attach($opponentPlayer->id, [
        'instance_id' => 2,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
    ]);

    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => now()->subMinutes(15),
        'content' => [
            'Players' => [
                ['Id' => 1, 'HandCount' => 7, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 5001, 'Owner' => 1, 'Zone' => 'Hand'],
            ],
        ],
    ]);

    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => now()->subMinutes(14),
        'content' => [
            'Players' => [
                ['Id' => 1, 'HandCount' => 6, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 5001, 'Owner' => 1, 'Zone' => 'Battlefield'],
            ],
        ],
    ]);

    $game = $game->fresh()->load(['players', 'timeline']);

    $card = Card::factory()->create(['mtgo_id' => 5001, 'name' => 'Lightning Bolt', 'type' => 'Instant']);
    $cardsByMtgoId = collect([$card->mtgo_id => $card]);
    $cardsByOracleId = collect();

    $result = BuildMatchGameData::run($game, 1, $cardsByMtgoId, $cardsByOracleId, []);

    expect($result['keptHand'][0]['name'])->toBe('Lightning Bolt');
});
