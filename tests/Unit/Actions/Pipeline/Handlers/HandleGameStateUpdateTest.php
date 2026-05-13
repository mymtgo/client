<?php

use App\Actions\Pipeline\Handlers\HandleGameStateUpdate;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('creates a game shell when the match is known', function () {
    $match = MtgoMatch::factory()->create(['token' => 'match-abc']);
    $event = LogEvent::factory()->create([
        'event_type' => 'game_state_update',
        'match_token' => 'match-abc',
        'game_id' => 555,
    ]);

    $context = new PipelineContext;
    $context->rememberMatch($match);

    (new HandleGameStateUpdate)->handle($event, $context);

    expect(Game::where('mtgo_id', 555)->count())->toBe(1);
    expect(Game::where('mtgo_id', 555)->first()->match_id)->toBe($match->id);
});

it('is idempotent on repeated calls for the same game_id', function () {
    $match = MtgoMatch::factory()->create(['token' => 'match-abc']);
    $event = LogEvent::factory()->create([
        'event_type' => 'game_state_update',
        'match_token' => 'match-abc',
        'game_id' => 777,
    ]);

    $context = new PipelineContext;
    $context->rememberMatch($match);

    (new HandleGameStateUpdate)->handle($event, $context);
    (new HandleGameStateUpdate)->handle($event, $context);

    expect(Game::where('mtgo_id', 777)->count())->toBe(1);
});

it('skips when no match is known for the token', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'game_state_update',
        'match_token' => 'unknown-token',
        'game_id' => 999,
    ]);

    (new HandleGameStateUpdate)->handle($event, new PipelineContext);

    expect(Game::count())->toBe(0);
});

it('skips when game_id is missing', function () {
    $match = MtgoMatch::factory()->create(['token' => 'match-abc']);
    $event = LogEvent::factory()->create([
        'event_type' => 'game_state_update',
        'match_token' => 'match-abc',
        'game_id' => null,
    ]);

    $context = new PipelineContext;
    $context->rememberMatch($match);

    (new HandleGameStateUpdate)->handle($event, $context);

    expect(Game::count())->toBe(0);
});
