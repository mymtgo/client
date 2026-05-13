<?php

use App\Actions\Pipeline\MetaMessage\ApplyStartingHand;
use App\Enums\MetaMessageKind;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

function startingHandParsed(string $player, int $value): array
{
    return [
        'type' => 2,
        'kind' => MetaMessageKind::StartingHand->value,
        'text' => null,
        'cards' => null,
        'event' => ['action' => 'starting_hand', 'player' => $player, 'value' => $value],
    ];
}

function makeStartingHandGame(int $initial = 7): array
{
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 42]);
    $alice = Player::factory()->create(['username' => 'alice']);
    $game->players()->attach($alice->id, [
        'is_local' => true,
        'instance_id' => 1,
        'starting_hand_size' => $initial,
    ]);

    return [$game, $alice];
}

it('lowers starting_hand_size when the new value is smaller', function () {
    [$game, $alice] = makeStartingHandGame(7);
    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyStartingHand)->apply($event, startingHandParsed('alice', 5), new PipelineContext);

    expect($game->players()->where('player_id', $alice->id)->first()->pivot->starting_hand_size)->toBe(5);
});

it('does not raise starting_hand_size after a previous mulligan lowered it', function () {
    [$game, $alice] = makeStartingHandGame(6);
    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyStartingHand)->apply($event, startingHandParsed('alice', 7), new PipelineContext);

    expect($game->players()->where('player_id', $alice->id)->first()->pivot->starting_hand_size)->toBe(6);
});

it('is idempotent', function () {
    [$game, $alice] = makeStartingHandGame(7);
    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyStartingHand)->apply($event, startingHandParsed('alice', 6), new PipelineContext);
    (new ApplyStartingHand)->apply($event, startingHandParsed('alice', 6), new PipelineContext);

    expect($game->players()->where('player_id', $alice->id)->first()->pivot->starting_hand_size)->toBe(6);
});
