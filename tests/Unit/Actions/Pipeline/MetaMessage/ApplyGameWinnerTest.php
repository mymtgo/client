<?php

use App\Actions\Pipeline\MetaMessage\ApplyGameWinner;
use App\Enums\MetaMessageKind;
use App\Facades\Mtgo;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

function gameWinnerParsed(string $winner): array
{
    return [
        'type' => 2,
        'kind' => MetaMessageKind::GameWinner->value,
        'text' => null,
        'cards' => null,
        'event' => ['action' => 'game_winner', 'player' => $winner, 'value' => null],
    ];
}

it('records games.won = true when local username matches the winner', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 42, 'ended_at' => null, 'won' => null]);
    $event = LogEvent::factory()->create(['game_id' => 42, 'timestamp' => '14:33:21']);

    $context = new PipelineContext;
    $context->setLocalUsername('alice');

    (new ApplyGameWinner)->apply($event, gameWinnerParsed('alice'), $context);

    $fresh = $game->fresh();
    expect($fresh->won)->toBeTrue();
    expect($fresh->ended_at)->not->toBeNull();
});

it('records games.won = false when the local username does not match', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 42, 'ended_at' => null, 'won' => null]);
    $event = LogEvent::factory()->create(['game_id' => 42, 'timestamp' => '14:33:21']);

    $context = new PipelineContext;
    $context->setLocalUsername('alice');

    (new ApplyGameWinner)->apply($event, gameWinnerParsed('bob'), $context);

    expect($game->fresh()->won)->toBeFalse();
});

it('does not update the game when the local username cannot be resolved', function () {
    Mtgo::shouldReceive('resolveUsername')->andReturn(null);

    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create([
        'match_id' => $match->id,
        'mtgo_id' => 42,
        'ended_at' => null,
        'won' => null,
    ]);
    $event = LogEvent::factory()->create(['game_id' => 42, 'timestamp' => '14:33:21']);

    // Brand new context — no setLocalUsername call, so it will lazy-resolve via Mtgo facade.
    $context = new PipelineContext;

    (new ApplyGameWinner)->apply($event, gameWinnerParsed('bob'), $context);

    $fresh = $game->fresh();
    expect($fresh->won)->toBeNull();
    expect($fresh->ended_at)->toBeNull();
});

it('is idempotent and does not overwrite an already-settled game', function () {
    $match = MtgoMatch::factory()->create();
    $endedAt = now()->subMinutes(5)->startOfSecond();
    $game = Game::factory()->create([
        'match_id' => $match->id,
        'mtgo_id' => 42,
        'ended_at' => $endedAt,
        'won' => true,
    ]);
    $event = LogEvent::factory()->create(['game_id' => 42, 'timestamp' => '14:33:21']);

    $context = new PipelineContext;
    $context->setLocalUsername('alice');

    (new ApplyGameWinner)->apply($event, gameWinnerParsed('bob'), $context);

    $fresh = $game->fresh();
    expect($fresh->won)->toBeTrue();
    expect($fresh->ended_at->equalTo($endedAt))->toBeTrue();
});
