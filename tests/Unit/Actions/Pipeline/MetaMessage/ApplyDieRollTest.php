<?php

use App\Actions\Pipeline\MetaMessage\ApplyDieRoll;
use App\Enums\MetaMessageKind;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

function dieRollParsed(string $player, int $value): array
{
    return [
        'type' => 2,
        'kind' => MetaMessageKind::DieRoll->value,
        'text' => null,
        'cards' => null,
        'event' => ['action' => 'die_roll', 'player' => $player, 'value' => $value],
    ];
}

it('writes the dice_roll value on the matching player pivot', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 42]);
    $alice = Player::factory()->create(['username' => 'alice']);
    $bob = Player::factory()->create(['username' => 'bob']);
    $game->players()->attach($alice->id, ['is_local' => true, 'instance_id' => 1]);
    $game->players()->attach($bob->id, ['is_local' => false, 'instance_id' => 2]);

    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyDieRoll)->apply($event, dieRollParsed('alice', 17), new PipelineContext);

    expect($game->players()->where('player_id', $alice->id)->first()->pivot->dice_roll)->toBe(17);
    expect($game->players()->where('player_id', $bob->id)->first()->pivot->dice_roll)->toBeNull();
});

it('is idempotent', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 42]);
    $alice = Player::factory()->create(['username' => 'alice']);
    $game->players()->attach($alice->id, ['is_local' => true, 'instance_id' => 1]);

    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyDieRoll)->apply($event, dieRollParsed('alice', 9), new PipelineContext);
    (new ApplyDieRoll)->apply($event, dieRollParsed('alice', 9), new PipelineContext);

    expect($game->players()->where('player_id', $alice->id)->first()->pivot->dice_roll)->toBe(9);
});

it('skips when the player username is unknown', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 42]);
    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyDieRoll)->apply($event, dieRollParsed('ghost', 5), new PipelineContext);

    expect(Player::where('username', 'ghost')->exists())->toBeFalse();
});
