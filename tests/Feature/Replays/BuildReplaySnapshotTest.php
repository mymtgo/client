<?php

use App\Actions\Replays\BuildReplaySnapshot;
use App\Models\Card;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Two games of local.player against Opp_Name: game 1 with one frame, game 2
 * with none. One card has a remote image, one a local-cache image only.
 */
function replayFixtureMatch(): MtgoMatch
{
    Card::create(['mtgo_id' => '1234', 'oracle_id' => 'o-rag', 'name' => 'Ragavan, Nimble Pilferer', 'type' => 'Legendary Creature', 'image' => 'https://cards.scryfall.io/a.jpg']);
    Card::create(['mtgo_id' => '5678', 'oracle_id' => 'o-isl', 'name' => 'Island', 'type' => 'Basic Land', 'image' => null]);

    $match = MtgoMatch::create([
        'mtgo_id' => '500001', 'token' => 'tok-replay', 'format' => 'CModern',
        'match_type' => 'League', 'started_at' => '2026-09-20 18:00:00',
    ]);
    $local = Player::create(['username' => 'local.player']);
    $opponent = Player::create(['username' => 'Opp_Name']);

    $first = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-1', 'started_at' => '2026-09-20 18:00:00', 'won' => true]);
    $second = Game::create(['match_id' => $match->id, 'mtgo_id' => 'g-2', 'started_at' => '2026-09-20 18:30:00', 'won' => false]);

    foreach ([$first, $second] as $game) {
        $game->players()->attach($local->id, ['is_local' => 1, 'instance_id' => 1]);
        $game->players()->attach($opponent->id, ['is_local' => 0, 'instance_id' => 2]);
    }

    GameTimeline::create([
        'game_id' => $first->id,
        'timestamp' => '18:00:01',
        'content' => [
            'Players' => [['Id' => 1, 'Name' => 'local.player'], ['Id' => 2, 'Name' => 'Opp_Name']],
            'Cards' => [
                ['Id' => 10, 'CatalogID' => 1234, 'Zone' => 'Battlefield', 'Owner' => 2],
                ['Id' => 11, 'CatalogID' => 5678, 'Zone' => 'Battlefield', 'Owner' => 1],
            ],
        ],
    ]);

    return $match;
}

it('builds the snapshot the desktop has always shared', function () {
    $snapshot = BuildReplaySnapshot::run(replayFixtureMatch());

    expect($snapshot['version'])->toBe(2)
        ->and($snapshot['meta']['format'])->toBe('modern')
        ->and($snapshot['meta']['games_in_match'])->toBe(2)
        ->and($snapshot['games'])->toHaveCount(1)
        ->and($snapshot['games'][0]['game_number'])->toBe(1)
        ->and($snapshot['games'][0]['won'])->toBeTrue()
        ->and($snapshot['games'][0]['frames'][0]['timestamp'])->toBe('18:00:01')
        ->and($snapshot['games'][0]['frames'][0]['content']['Players'][0]['IsLocal'])->toBeTrue()
        ->and($snapshot['games'][0]['frames'][0]['content']['Players'][1]['IsLocal'])->toBeFalse()
        ->and($snapshot['games'][0]['frames'][0]['content']['Cards'][0])->toBe([
            'Id' => 10, 'CatalogID' => 1234, 'Zone' => 'Battlefield', 'Owner' => 2,
            'image' => 'https://cards.scryfall.io/a.jpg', 'type' => 'Legendary Creature', 'name' => 'Ragavan, Nimble Pilferer',
        ])
        ->and($snapshot['games'][0]['frames'][0]['content']['Cards'][1]['image'])->toBeNull();
});
