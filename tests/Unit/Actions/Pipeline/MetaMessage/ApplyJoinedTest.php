<?php

use App\Actions\Pipeline\MetaMessage\ApplyJoined;
use App\Enums\MetaMessageKind;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

function joinedParsed(string $player): array
{
    return [
        'type' => 2,
        'kind' => MetaMessageKind::Joined->value,
        'text' => null,
        'cards' => null,
        'event' => ['action' => 'joined', 'player' => $player, 'value' => null],
    ];
}

it('creates a player and attaches them to the game', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 42]);
    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyJoined)->apply($event, joinedParsed('alice'), new PipelineContext);

    expect(Player::where('username', 'alice')->exists())->toBeTrue();
    expect($game->players()->count())->toBe(1);
});

it('marks the attached player as local when context matches the username', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 42]);
    $event = LogEvent::factory()->create(['game_id' => 42]);

    $context = new PipelineContext;
    $context->setLocalUsername('alice');

    (new ApplyJoined)->apply($event, joinedParsed('alice'), $context);
    (new ApplyJoined)->apply($event, joinedParsed('bob'), $context);

    $alice = $game->players()->where('username', 'alice')->first();
    $bob = $game->players()->where('username', 'bob')->first();

    expect($alice->pivot->is_local)->toBeTrue();
    expect($bob->pivot->is_local)->toBeFalse();
});

it('is idempotent and does not attach the same player twice', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 42]);
    $event = LogEvent::factory()->create(['game_id' => 42]);

    (new ApplyJoined)->apply($event, joinedParsed('alice'), new PipelineContext);
    (new ApplyJoined)->apply($event, joinedParsed('alice'), new PipelineContext);

    expect($game->players()->count())->toBe(1);
    expect(Player::where('username', 'alice')->count())->toBe(1);
});
