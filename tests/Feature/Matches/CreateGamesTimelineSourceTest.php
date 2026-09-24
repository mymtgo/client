<?php

use App\Actions\Matches\CreateGames;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function stateEvent(LogInstance $instance, int $gameId, int $matchId, string $timestamp, array $cards = []): LogEvent
{
    $snapshot = json_encode([
        'Players' => [['Id' => 0, 'Name' => 'local.player'], ['Id' => 1, 'Name' => 'Opp_Name']],
        'Cards' => $cards,
    ]);

    return LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'event_type' => 'game_state_update',
        'game_id' => $gameId,
        'match_id' => $matchId,
        'timestamp' => $timestamp,
        'raw_text' => "Game ID: {$gameId}, Match ID: {$matchId} {$snapshot}",
    ]);
}

beforeEach(function () {
    $this->instance = LogInstance::factory()->create();
    $this->match = MtgoMatch::factory()->create(['mtgo_id' => '288955358']);
});

it('marks a log-built timeline as log owned', function () {
    $events = collect([
        stateEvent($this->instance, 958291826, 288955358, '13:16:41'),
        stateEvent($this->instance, 958291826, 288955358, '13:16:45'),
    ]);

    CreateGames::run($this->match, 958291826, $events, 0, []);

    $game = Game::where('mtgo_id', 958291826)->first();
    expect($game->timeline_source)->toBe('log')
        ->and(GameTimeline::where('game_id', $game->id)->count())->toBe(2);
});

it('leaves a sidecar owned timeline untouched on log reprocess', function () {
    $game = Game::factory()->create(['match_id' => $this->match->id, 'mtgo_id' => 958291826, 'timeline_source' => 'sidecar']);
    GameTimeline::create(['game_id' => $game->id, 'timestamp' => '13:16:41.050', 'content' => ['Turn' => 2, 'Players' => [], 'Cards' => []]]);

    $events = collect([
        stateEvent($this->instance, 958291826, 288955358, '13:16:41'),
        stateEvent($this->instance, 958291826, 288955358, '13:16:45'),
    ]);

    CreateGames::run($this->match, 958291826, $events, 0, []);

    $rows = GameTimeline::where('game_id', $game->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->timestamp)->toBe('13:16:41.050')
        ->and($game->fresh()->timeline_source)->toBe('sidecar');
});
