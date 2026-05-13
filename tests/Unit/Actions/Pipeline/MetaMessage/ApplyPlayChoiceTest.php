<?php

use App\Actions\Pipeline\MetaMessage\ApplyPlayChoice;
use App\Enums\MetaMessageKind;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

function playChoiceParsed(string $player, string $value = 'play'): array
{
    return [
        'type' => 2,
        'kind' => MetaMessageKind::PlayChoice->value,
        'text' => null,
        'cards' => null,
        'event' => ['action' => 'play_choice', 'player' => $player, 'value' => $value],
    ];
}

function makePlayChoiceGame(): array
{
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 42]);
    $alice = Player::factory()->create(['username' => 'alice']);
    $bob = Player::factory()->create(['username' => 'bob']);
    $game->players()->attach($alice->id, ['is_local' => true, 'instance_id' => 1]);
    $game->players()->attach($bob->id, ['is_local' => false, 'instance_id' => 2]);

    return [$game, $alice, $bob];
}

it('sets on_play true for the chooser', function () {
    [$game, $alice, $bob] = makePlayChoiceGame();
    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyPlayChoice)->apply($event, playChoiceParsed('alice', 'play'), new PipelineContext);

    expect($game->players()->where('player_id', $alice->id)->first()->pivot->on_play)->toBeTrue();
});

it('flips the other players on_play to the opposite value', function () {
    [$game, $alice, $bob] = makePlayChoiceGame();
    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyPlayChoice)->apply($event, playChoiceParsed('alice', 'play'), new PipelineContext);

    expect($game->players()->where('player_id', $bob->id)->first()->pivot->on_play)->toBeFalse();
});

it('is idempotent', function () {
    [$game, $alice, $bob] = makePlayChoiceGame();
    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyPlayChoice)->apply($event, playChoiceParsed('alice', 'play'), new PipelineContext);
    (new ApplyPlayChoice)->apply($event, playChoiceParsed('alice', 'play'), new PipelineContext);

    expect($game->players()->where('player_id', $alice->id)->first()->pivot->on_play)->toBeTrue();
    expect($game->players()->where('player_id', $bob->id)->first()->pivot->on_play)->toBeFalse();
});
