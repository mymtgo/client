<?php

use App\Actions\Matches\ExtractGameHandData;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createGameWithTimeline(array $overrides = [], array $pivotOverrides = [], array $timelineSnapshots = []): Game
{
    $match = MtgoMatch::create([
        'token' => 'test-token',
        'mtgo_id' => '12345',
        'format' => 'modern',
        'match_type' => 'league',
        'result' => 'win',
        'games_won' => 2,
        'games_lost' => 1,
        'started_at' => now()->subMinutes(30),
        'ended_at' => now(),
    ]);

    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => 'game-1',
        'won' => $overrides['won'] ?? true,
        'started_at' => now()->subMinutes(20),
        'ended_at' => now()->subMinutes(10),
    ]);

    $localPlayer = Player::create(['username' => 'local_player']);
    $opponentPlayer = Player::create(['username' => 'opponent_player']);

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

it('returns the expected shape with correct keys', function () {
    $game = createGameWithTimeline(
        overrides: ['won' => true],
        pivotOverrides: ['on_play' => true],
        timelineSnapshots: [
            // Snapshot 1: Pre-draw (hand empty, library at 60)
            [
                'Players' => [
                    ['Id' => 1, 'HandCount' => 0, 'LibraryCount' => 60],
                    ['Id' => 2, 'HandCount' => 0, 'LibraryCount' => 60],
                ],
                'Cards' => [],
            ],
            // Snapshot 2: Opening hand drawn (7 cards)
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
            // Snapshot 3: Game begins — a card hits the battlefield
            [
                'Players' => [
                    ['Id' => 1, 'HandCount' => 6, 'LibraryCount' => 53],
                    ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
                ],
                'Cards' => [
                    ['Id' => 101, 'CatalogID' => 1001, 'Owner' => 1, 'Zone' => 'Battlefield'],
                    ['Id' => 102, 'CatalogID' => 1002, 'Owner' => 1, 'Zone' => 'Hand'],
                ],
            ],
        ],
    );

    $result = ExtractGameHandData::run($game);

    expect($result)->toHaveKeys([
        'mulligan_count',
        'starting_hand_size',
        'kept_hand',
        'opponent_mulligan_count',
        'on_play',
        'won',
    ]);

    expect($result['mulligan_count'])->toBe(0);
    expect($result['starting_hand_size'])->toBe(7);
    expect($result['kept_hand'])->toBe([1001, 1002, 1003, 1004, 1005, 1006, 1007]);
    expect($result['opponent_mulligan_count'])->toBe(0);
    expect($result['on_play'])->toBeTrue();
    expect($result['won'])->toBeTrue();
});

it('detects a mulligan and returns the kept hand', function () {
    $game = createGameWithTimeline(
        overrides: ['won' => false],
        pivotOverrides: ['on_play' => false],
        timelineSnapshots: [
            // Snapshot 1: Pre-draw
            [
                'Players' => [
                    ['Id' => 1, 'HandCount' => 0, 'LibraryCount' => 60],
                    ['Id' => 2, 'HandCount' => 0, 'LibraryCount' => 60],
                ],
                'Cards' => [],
            ],
            // Snapshot 2: First hand (7 cards)
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
            // Snapshot 3: Mulligan — entirely new instance IDs (7 new cards)
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
            // Snapshot 4: Bottom 1 card (hand shrinks from 7 to 6)
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
            // Snapshot 5: Game starts — card enters battlefield
            [
                'Players' => [
                    ['Id' => 1, 'HandCount' => 5, 'LibraryCount' => 54],
                    ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
                ],
                'Cards' => [
                    ['Id' => 201, 'CatalogID' => 2001, 'Owner' => 1, 'Zone' => 'Battlefield'],
                ],
            ],
        ],
    );

    $result = ExtractGameHandData::run($game);

    expect($result['mulligan_count'])->toBe(1);
    expect($result['starting_hand_size'])->toBe(6);
    expect($result['kept_hand'])->toBe([2001, 2002, 2003, 2004, 2005, 2006]);
    expect($result['on_play'])->toBeFalse();
    expect($result['won'])->toBeFalse();
});

it('detects opponent mulligans via library count', function () {
    $game = createGameWithTimeline(
        timelineSnapshots: [
            // Snapshot 1: Both pre-draw, opponent library starts at 60
            [
                'Players' => [
                    ['Id' => 1, 'HandCount' => 0, 'LibraryCount' => 60],
                    ['Id' => 2, 'HandCount' => 0, 'LibraryCount' => 60],
                ],
                'Cards' => [],
            ],
            // Snapshot 2: Local draws 7. Opponent library at 54 (lost 6 = drew 7, shuffled back, drew 6)
            // opponentFirstHandLibrary=54, opponentStartLibrary=60 → 54 - (60-7) = 1 mulligan
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
            // Snapshot 3: Game starts
            [
                'Players' => [
                    ['Id' => 1, 'HandCount' => 6, 'LibraryCount' => 53],
                    ['Id' => 2, 'HandCount' => 6, 'LibraryCount' => 54],
                ],
                'Cards' => [
                    ['Id' => 101, 'CatalogID' => 1001, 'Owner' => 1, 'Zone' => 'Battlefield'],
                ],
            ],
        ],
    );

    $result = ExtractGameHandData::run($game);

    expect($result['opponent_mulligan_count'])->toBe(1);
});

it('handles a game with no timeline gracefully', function () {
    $game = createGameWithTimeline(timelineSnapshots: []);

    $result = ExtractGameHandData::run($game);

    expect($result['mulligan_count'])->toBe(0);
    expect($result['starting_hand_size'])->toBe(0);
    expect($result['kept_hand'])->toBe([]);
    expect($result['opponent_mulligan_count'])->toBe(0);
});
