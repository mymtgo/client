<?php

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GameFieldDiff;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores a game event with json casts', function () {
    $instance = LogInstance::factory()->create();

    $event = GameEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'data' => ['c' => '445'],
        'ref' => ['thing' => 445],
    ]);

    expect($event->fresh()->data)->toBe(['c' => '445'])
        ->and($event->fresh()->ref)->toBe(['thing' => 445])
        ->and($event->fresh()->verified)->toBeTrue()
        ->and($event->fresh()->session_started_at)->toBeInstanceOf(CarbonInterface::class);
});

it('rejects a duplicate seq within one instance', function () {
    $instance = LogInstance::factory()->create();
    GameEvent::factory()->create(['log_instance_id' => $instance->id, 'seq' => 7]);

    expect(fn () => GameEvent::factory()->create(['log_instance_id' => $instance->id, 'seq' => 7]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows the same seq in a different instance', function () {
    $a = LogInstance::factory()->create();
    $b = LogInstance::factory()->create();
    GameEvent::factory()->create(['log_instance_id' => $a->id, 'seq' => 7]);
    GameEvent::factory()->create(['log_instance_id' => $b->id, 'seq' => 7]);

    expect(GameEvent::count())->toBe(2);
});

it('stores one diff per match, game and field', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id]);

    GameFieldDiff::create([
        'match_id' => $match->id,
        'game_id' => $game->id,
        'field' => 'game_result',
        'log_value' => ['won' => true],
        'sidecar_value' => ['won' => false],
        'chosen_source' => 'log',
    ]);

    expect(fn () => GameFieldDiff::create([
        'match_id' => $match->id,
        'game_id' => $game->id,
        'field' => 'game_result',
        'log_value' => null,
        'sidecar_value' => null,
        'chosen_source' => 'log',
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect($game->fieldDiffs()->count())->toBe(1);
});

it('adds nullable clock columns to game_player', function () {
    expect(Schema::hasColumns('game_player', ['clock_remaining_ms_start', 'clock_remaining_ms_end', 'clock_remaining_ms_min', 'sideboard_ms_used']))->toBeTrue();
});
