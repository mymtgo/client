<?php

use App\Actions\Pipeline\MetaMessage\ApplyJoined;
use App\Enums\MetaMessageKind;
use App\Facades\Mtgo;
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

    $context = new PipelineContext;
    $context->setLocalUsername('alice');

    (new ApplyJoined)->apply($event, joinedParsed('alice'), $context);

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

    $contextA = new PipelineContext;
    $contextA->setLocalUsername('alice');
    $contextB = new PipelineContext;
    $contextB->setLocalUsername('alice');

    (new ApplyJoined)->apply($event, joinedParsed('alice'), $contextA);
    (new ApplyJoined)->apply($event, joinedParsed('alice'), $contextB);

    expect($game->players()->count())->toBe(1);
    expect(Player::where('username', 'alice')->count())->toBe(1);
});

it('does not attach the player when the local username cannot be resolved', function () {
    Mtgo::shouldReceive('resolveUsername')->andReturn(null);

    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 42]);
    $event = LogEvent::factory()->create(['game_id' => 42]);

    // Brand new context — no setLocalUsername, so it will lazy-resolve to null via the facade.
    $context = new PipelineContext;

    (new ApplyJoined)->apply($event, joinedParsed('alice'), $context);

    expect(Player::where('username', 'alice')->exists())->toBeFalse();
    expect($game->players()->count())->toBe(0);
});
