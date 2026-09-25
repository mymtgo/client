<?php

use App\Actions\Replays\BuildReplayFrames;
use App\Models\Card;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A game between local.player (seat 1) and Opp_Name (seat 2) with one frame showing one card. */
function framesGame(): Game
{
    $game = Game::factory()->for(MtgoMatch::factory(), 'match')->create();
    $local = Player::factory()->create(['username' => 'local.player']);
    $opponent = Player::factory()->create(['username' => 'Opp_Name']);
    $game->players()->attach($local->id, ['instance_id' => 1, 'is_local' => true, 'on_play' => true]);
    $game->players()->attach($opponent->id, ['instance_id' => 2, 'is_local' => false, 'on_play' => false]);

    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => '2026-09-20 18:00:01',
        'content' => [
            'Players' => [['Id' => 1, 'Name' => 'local.player'], ['Id' => 2, 'Name' => 'Opp_Name']],
            'Cards' => [['Id' => 10, 'CatalogID' => 5001, 'Zone' => 'Battlefield', 'Owner' => 2]],
        ],
    ]);

    return $game;
}

it('resolves card fields and marks the local player', function () {
    $game = framesGame();
    Card::factory()->create(['mtgo_id' => 5001, 'name' => 'Ragavan, Nimble Pilferer', 'type' => 'Legendary Creature', 'image' => 'https://cards.scryfall.io/a.jpg']);

    $frames = BuildReplayFrames::run($game);

    expect($frames[0]['content']['Cards'][0]['name'])->toBe('Ragavan, Nimble Pilferer')
        ->and($frames[0]['content']['Cards'][0]['type'])->toBe('Legendary Creature')
        ->and($frames[0]['content']['Players'][0]['IsLocal'])->toBeTrue()
        ->and($frames[0]['content']['Players'][1]['IsLocal'])->toBeFalse();
});

it('prefers the local image on the desktop page', function () {
    $game = framesGame();
    Card::factory()->create(['mtgo_id' => 5001, 'image' => 'https://cards.scryfall.io/a.jpg', 'local_image' => 'a.jpg']);

    $frames = BuildReplayFrames::run($game);

    expect($frames[0]['content']['Cards'][0]['image'])->not->toStartWith('https://cards.scryfall.io');
});

it('uses only remote https images in portable mode', function (?string $image, ?string $expected) {
    $game = framesGame();
    Card::factory()->create(['mtgo_id' => 5001, 'image' => $image, 'local_image' => 'a.jpg']);

    $frames = BuildReplayFrames::run($game, portableImages: true);

    expect($frames[0]['content']['Cards'][0]['image'])->toBe($expected);
})->with([
    'remote https' => ['https://cards.scryfall.io/a.jpg', 'https://cards.scryfall.io/a.jpg'],
    'plain http' => ['http://example.com/a.jpg', null],
    'no remote image' => [null, null],
]);

it('leaves a card unknown to the catalog with null fields', function () {
    $frames = BuildReplayFrames::run(framesGame(), portableImages: true);

    expect($frames[0]['content']['Cards'][0]['name'])->toBeNull()
        ->and($frames[0]['content']['Cards'][0]['image'])->toBeNull();
});

it('names a card the catalog has no row for with the name MTGO gave it', function () {
    $game = framesGame();
    $timeline = $game->timeline()->first();
    $content = $timeline->content;
    $content['Cards'][0]['Name'] = 'Eldrazi Spawn';
    $timeline->update(['content' => $content]);

    $card = BuildReplayFrames::run($game)[0]['content']['Cards'][0];

    expect($card['name'])->toBe('Eldrazi Spawn')->and($card)->not->toHaveKey('Name');
});
