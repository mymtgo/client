<?php

use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());
});

it('returns opponent cards aggregated across games with quantity capped at 4', function () {
    Http::fake([
        '*/api/cards/resolve' => Http::response([
            'cards' => [
                [
                    'mtgo_id' => 12345,
                    'oracle_id' => 'oracle-bolt',
                    'name' => 'Lightning Bolt',
                    'type' => 'Instant',
                    'image' => null,
                    'art_crop' => null,
                    'cmc' => 1,
                    'identity' => 'R',
                ],
                [
                    'mtgo_id' => 67890,
                    'oracle_id' => 'oracle-mountain',
                    'name' => 'Mountain',
                    'type' => 'Basic Land — Mountain',
                    'image' => null,
                    'art_crop' => null,
                    'cmc' => 0,
                    'identity' => '',
                ],
            ],
        ]),
    ]);

    $match = MtgoMatch::factory()->create();
    $opponent = Player::create(['username' => 'opponent']);

    $game1 = Game::factory()->create(['match_id' => $match->id]);
    $game1->players()->attach($opponent->id, [
        'instance_id' => 1,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
        'deck_json' => [
            ['mtgo_id' => 12345, 'quantity' => 3],
            ['mtgo_id' => 67890, 'quantity' => 10],
        ],
    ]);

    $game2 = Game::factory()->create(['match_id' => $match->id]);
    $game2->players()->attach($opponent->id, [
        'instance_id' => 2,
        'is_local' => false,
        'on_play' => true,
        'starting_hand_size' => 7,
        'deck_json' => [
            ['mtgo_id' => 12345, 'quantity' => 2],
        ],
    ]);

    $response = $this->postJson("/archetypes/scan-match/{$match->id}");

    $response->assertOk();
    $response->assertJsonStructure([
        'cards' => [['mtgo_id', 'name', 'quantity', 'sideboard']],
        'color_identity',
    ]);

    $cards = collect($response->json('cards'));
    $bolt = $cards->firstWhere('mtgo_id', 12345);
    $mountain = $cards->firstWhere('mtgo_id', 67890);

    expect($bolt['quantity'])->toBe(4); // 3+2 capped at 4
    expect($mountain['quantity'])->toBe(4); // 10 capped at 4
    expect($bolt['sideboard'])->toBeFalse();
    expect($response->json('color_identity'))->toBe('R');
});

it('ignores local player cards when scanning a match', function () {
    Http::fake([
        '*/api/cards/resolve' => Http::response([
            'cards' => [
                [
                    'mtgo_id' => 12345,
                    'oracle_id' => 'oracle-bolt',
                    'name' => 'Lightning Bolt',
                    'type' => 'Instant',
                    'image' => null,
                    'art_crop' => null,
                    'cmc' => 1,
                    'identity' => 'R',
                ],
            ],
        ]),
    ]);

    $match = MtgoMatch::factory()->create();
    $localPlayer = Player::create(['username' => 'me']);
    $opponent = Player::create(['username' => 'opponent']);

    $game = Game::factory()->create(['match_id' => $match->id]);
    $game->players()->attach($localPlayer->id, [
        'instance_id' => 1,
        'is_local' => true,
        'on_play' => true,
        'starting_hand_size' => 7,
        'deck_json' => [
            ['mtgo_id' => 99999, 'quantity' => 4],
        ],
    ]);
    $game->players()->attach($opponent->id, [
        'instance_id' => 2,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
        'deck_json' => [
            ['mtgo_id' => 12345, 'quantity' => 2],
        ],
    ]);

    $response = $this->postJson("/archetypes/scan-match/{$match->id}");

    $response->assertOk();
    $cards = collect($response->json('cards'));

    expect($cards)->toHaveCount(1);
    expect($cards->first()['mtgo_id'])->toBe(12345);
});

it('returns 422 when match has no opponent deck data', function () {
    $match = MtgoMatch::factory()->create();

    $response = $this->postJson("/archetypes/scan-match/{$match->id}");

    $response->assertUnprocessable();
    $response->assertJsonPath('message', 'No opponent cards found in this match.');
});
