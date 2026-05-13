<?php

use App\Actions\Pipeline\MetaMessage\ApplyTurnStart;
use App\Enums\MetaMessageKind;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

function turnStartParsed(int $value): array
{
    return [
        'type' => 3,
        'kind' => MetaMessageKind::TurnStart->value,
        'text' => null,
        'cards' => null,
        'event' => ['action' => 'turn_start', 'player' => 'p', 'value' => $value],
    ];
}

it('records the highest seen turn number on the game row', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 13, 'turn_count' => null]);
    $event = LogEvent::factory()->create(['game_id' => 13]);

    foreach ([1, 2, 3, 2] as $turn) {
        (new ApplyTurnStart)->apply($event, turnStartParsed($turn), new PipelineContext);
    }

    expect($game->fresh()->turn_count)->toBe(3);
});

it('is idempotent and does not lower an existing turn count', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => 13, 'turn_count' => 5]);
    $event = LogEvent::factory()->create(['game_id' => 13]);

    (new ApplyTurnStart)->apply($event, turnStartParsed(3), new PipelineContext);
    (new ApplyTurnStart)->apply($event, turnStartParsed(3), new PipelineContext);

    expect($game->fresh()->turn_count)->toBe(5);
});

it('skips when the game cannot be resolved', function () {
    $event = LogEvent::factory()->create(['game_id' => 9999]);

    (new ApplyTurnStart)->apply($event, turnStartParsed(2), new PipelineContext);

    expect(Game::count())->toBe(0);
});
