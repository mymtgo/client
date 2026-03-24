<?php

use App\Actions\Matches\ParseOpeningHand;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createGameForHandTest(array $overrides = [], array $pivotOverrides = [], array $timelineSnapshots = []): Game
{
    $match = MtgoMatch::create([
        'token' => fake()->uuid(),
        'mtgo_id' => (string) fake()->randomNumber(5),
        'format' => 'modern',
        'match_type' => 'league',
        'outcome' => 'win',
        'started_at' => now()->subMinutes(30),
        'ended_at' => now(),
    ]);

    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => 'game-'.fake()->randomNumber(3),
        'won' => $overrides['won'] ?? true,
        'started_at' => now()->subMinutes(20),
        'ended_at' => now()->subMinutes(10),
    ]);

    $localPlayer = Player::create(['username' => 'local_'.fake()->randomNumber(3)]);
    $opponentPlayer = Player::create(['username' => 'opponent_'.fake()->randomNumber(3)]);

    $game->players()->attach($localPlayer->id, [
        'instance_id' => $pivotOverrides['local_instance_id'] ?? 1,
        'is_local' => true,
        'on_play' => $pivotOverrides['on_play'] ?? true,
        'starting_hand_size' => 7,
    ]);

    $game->players()->attach($opponentPlayer->id, [
        'instance_id' => $pivotOverrides['opponent_instance_id'] ?? 2,
        'is_local' => false,
        'on_play' => ! ($pivotOverrides['on_play'] ?? true),
        'starting_hand_size' => 7,
    ]);

    foreach ($timelineSnapshots as $i => $content) {
        GameTimeline::create([
            'game_id' => $game->id,
            'timestamp' => now()->subMinutes(15)->addSeconds($i),
            'content' => $content,
        ]);
    }

    return $game->fresh()->load(['players', 'timeline']);
}

it('returns no mulligans when player keeps opening 7', function () {
    $game = createGameForHandTest(timelineSnapshots: [
        [
            'Players' => [
                ['Id' => 1, 'HandCount' => 0, 'LibraryCount' => 60],
                ['Id' => 2, 'HandCount' => 0, 'LibraryCount' => 60],
            ],
            'Cards' => [],
        ],
        [
            'Players' => [
                ['Id' => 1, 'HandCount' => 7, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 1001, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 102, 'CatalogID' => 1002, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 103, 'CatalogID' => 1003, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 104, 'CatalogID' => 1004, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 105, 'CatalogID' => 1005, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 106, 'CatalogID' => 1006, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 107, 'CatalogID' => 1007, 'Owner' => 1, 'Zone' => 'Hand'],
            ],
        ],
        [
            'Players' => [
                ['Id' => 1, 'HandCount' => 6, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 1001, 'Owner' => 1, 'Zone' => 'Battlefield'],
            ],
        ],
    ]);

    $result = ParseOpeningHand::run($game, 1, 2);

    expect($result['mulliganed_hands'])->toBe([]);
    expect(array_values($result['kept_hand']))->toBe([1001, 1002, 1003, 1004, 1005, 1006, 1007]);
    expect($result['bottomed_instance_ids'])->toBe([]);
    expect($result['opponent_mulligans'])->toBe(0);
});

it('detects a mulligan with complete hand replacement', function () {
    $game = createGameForHandTest(timelineSnapshots: [
        [
            'Players' => [
                ['Id' => 1, 'HandCount' => 0, 'LibraryCount' => 60],
                ['Id' => 2, 'HandCount' => 0, 'LibraryCount' => 60],
            ],
            'Cards' => [],
        ],
        // First hand
        [
            'Players' => [
                ['Id' => 1, 'HandCount' => 7, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 1001, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 102, 'CatalogID' => 1002, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 103, 'CatalogID' => 1003, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 104, 'CatalogID' => 1004, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 105, 'CatalogID' => 1005, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 106, 'CatalogID' => 1006, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 107, 'CatalogID' => 1007, 'Owner' => 1, 'Zone' => 'Hand'],
            ],
        ],
        // Mulligan — entirely new cards
        [
            'Players' => [
                ['Id' => 1, 'HandCount' => 7, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 201, 'CatalogID' => 2001, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 202, 'CatalogID' => 2002, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 203, 'CatalogID' => 2003, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 204, 'CatalogID' => 2004, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 205, 'CatalogID' => 2005, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 206, 'CatalogID' => 2006, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 207, 'CatalogID' => 2007, 'Owner' => 1, 'Zone' => 'Hand'],
            ],
        ],
        // Game starts
        [
            'Players' => [
                ['Id' => 1, 'HandCount' => 6, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 201, 'CatalogID' => 2001, 'Owner' => 1, 'Zone' => 'Battlefield'],
            ],
        ],
    ]);

    $result = ParseOpeningHand::run($game, 1, 2);

    expect($result['mulliganed_hands'])->toHaveCount(1);
    // The mulliganed hand should contain the first hand's catalog IDs
    expect(array_values($result['mulliganed_hands'][0]))->toBe([1001, 1002, 1003, 1004, 1005, 1006, 1007]);
    // The kept hand should be the second hand
    expect(array_values($result['kept_hand']))->toBe([2001, 2002, 2003, 2004, 2005, 2006, 2007]);
});

it('detects bottomed cards after mulligan', function () {
    $game = createGameForHandTest(timelineSnapshots: [
        [
            'Players' => [
                ['Id' => 1, 'HandCount' => 0, 'LibraryCount' => 60],
                ['Id' => 2, 'HandCount' => 0, 'LibraryCount' => 60],
            ],
            'Cards' => [],
        ],
        // First hand
        [
            'Players' => [
                ['Id' => 1, 'HandCount' => 7, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 1001, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 102, 'CatalogID' => 1002, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 103, 'CatalogID' => 1003, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 104, 'CatalogID' => 1004, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 105, 'CatalogID' => 1005, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 106, 'CatalogID' => 1006, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 107, 'CatalogID' => 1007, 'Owner' => 1, 'Zone' => 'Hand'],
            ],
        ],
        // Mulligan
        [
            'Players' => [
                ['Id' => 1, 'HandCount' => 7, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 201, 'CatalogID' => 2001, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 202, 'CatalogID' => 2002, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 203, 'CatalogID' => 2003, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 204, 'CatalogID' => 2004, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 205, 'CatalogID' => 2005, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 206, 'CatalogID' => 2006, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 207, 'CatalogID' => 2007, 'Owner' => 1, 'Zone' => 'Hand'],
            ],
        ],
        // Bottom 1 card (instance 207 removed)
        [
            'Players' => [
                ['Id' => 1, 'HandCount' => 6, 'LibraryCount' => 54],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 201, 'CatalogID' => 2001, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 202, 'CatalogID' => 2002, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 203, 'CatalogID' => 2003, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 204, 'CatalogID' => 2004, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 205, 'CatalogID' => 2005, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 206, 'CatalogID' => 2006, 'Owner' => 1, 'Zone' => 'Hand'],
            ],
        ],
        // Game starts
        [
            'Players' => [
                ['Id' => 1, 'HandCount' => 5, 'LibraryCount' => 54],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 201, 'CatalogID' => 2001, 'Owner' => 1, 'Zone' => 'Battlefield'],
            ],
        ],
    ]);

    $result = ParseOpeningHand::run($game, 1, 2);

    expect($result['mulliganed_hands'])->toHaveCount(1);
    expect($result['bottomed_instance_ids'])->toBe([207]);
    // hand_before_bottoming preserves the full hand including the card that was bottomed
    expect($result['hand_before_bottoming'])->toHaveCount(7);
    expect($result['hand_before_bottoming'][207])->toBe(2007);
    // kept_hand is the hand after bottoming
    expect($result['kept_hand'])->toHaveCount(6);
    expect($result['kept_hand'])->not->toHaveKey(207);
});

it('detects opponent mulligans via library count difference', function () {
    $game = createGameForHandTest(timelineSnapshots: [
        [
            'Players' => [
                ['Id' => 1, 'HandCount' => 0, 'LibraryCount' => 60],
                ['Id' => 2, 'HandCount' => 0, 'LibraryCount' => 60],
            ],
            'Cards' => [],
        ],
        // Opponent library at 54 after drawing = drew 7, shuffled, drew 6 = 1 mulligan
        [
            'Players' => [
                ['Id' => 1, 'HandCount' => 7, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 6, 'LibraryCount' => 54],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 1001, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 102, 'CatalogID' => 1002, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 103, 'CatalogID' => 1003, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 104, 'CatalogID' => 1004, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 105, 'CatalogID' => 1005, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 106, 'CatalogID' => 1006, 'Owner' => 1, 'Zone' => 'Hand'],
                ['Id' => 107, 'CatalogID' => 1007, 'Owner' => 1, 'Zone' => 'Hand'],
            ],
        ],
        [
            'Players' => [
                ['Id' => 1, 'HandCount' => 6, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 6, 'LibraryCount' => 54],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 1001, 'Owner' => 1, 'Zone' => 'Battlefield'],
            ],
        ],
    ]);

    $result = ParseOpeningHand::run($game, 1, 2);

    expect($result['opponent_mulligans'])->toBe(1);
});

it('returns empty structure for game with no timeline', function () {
    $game = createGameForHandTest(timelineSnapshots: []);

    $result = ParseOpeningHand::run($game, 1, 2);

    expect($result['mulliganed_hands'])->toBe([]);
    expect($result['kept_hand'])->toBe([]);
    expect($result['bottomed_instance_ids'])->toBe([]);
    expect($result['hand_before_bottoming'])->toBe([]);
    expect($result['opponent_mulligans'])->toBe(0);
});
