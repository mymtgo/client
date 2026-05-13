<?php

use App\Actions\Pipeline\MetaMessage\ApplyGameWinner;
use App\Enums\MetaMessageKind;
use App\Facades\Mtgo;
use App\Models\CardGameStat;
use App\Models\DeckVersion;
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

it('backfills won on existing card_game_stats rows', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 42, 'ended_at' => null, 'won' => null]);
    $event = LogEvent::factory()->create(['game_id' => 42, 'timestamp' => '14:33:21']);
    $deckVersion = DeckVersion::factory()->create();

    $baseStat = [
        'deck_version_id' => $deckVersion->id,
        'quantity' => 1,
        'kept' => 0,
        'seen' => 0,
        'won' => false,
        'is_postboard' => false,
        'sided_out' => false,
        'cast' => 0,
        'sided_in' => 0,
        'pregame_revealed' => false,
        'pregame_played' => false,
    ];

    $statForLocal = CardGameStat::create($baseStat + [
        'game_id' => $game->id,
        'oracle_id' => 'oracle-1',
        'opponent' => false,
    ]);
    $statForOpponent = CardGameStat::create($baseStat + [
        'game_id' => $game->id,
        'oracle_id' => 'oracle-2',
        'opponent' => true,
    ]);

    $otherGame = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 99]);
    $statOtherGame = CardGameStat::create($baseStat + [
        'game_id' => $otherGame->id,
        'oracle_id' => 'oracle-3',
        'opponent' => false,
    ]);

    $context = new PipelineContext;
    $context->setLocalUsername('alice');

    (new ApplyGameWinner)->apply($event, gameWinnerParsed('alice'), $context);

    expect($statForLocal->fresh()->won)->toBeTrue();
    expect($statForOpponent->fresh()->won)->toBeTrue();
    // Other games are untouched.
    expect($statOtherGame->fresh()->won)->toBeFalse();
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
