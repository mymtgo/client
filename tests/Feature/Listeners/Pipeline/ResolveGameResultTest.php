<?php

use App\Enums\MatchState;
use App\Events\GameResultDetermined;
use App\Listeners\Pipeline\ResolveGameResult;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sets won on game from game_result event', function () {
    $match = MtgoMatch::factory()->create([
        'token' => 'token-result',
        'state' => MatchState::InProgress,
    ]);

    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => 42,
        'won' => null,
        'started_at' => now(),
        'ended_at' => null,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-result',
        'game_id' => 42,
        'event_type' => 'game_result',
        'raw_text' => json_encode(['won' => true]),
        'processed_at' => null,
    ]);

    $listener = new ResolveGameResult;
    $listener->handle(new GameResultDetermined($logEvent));

    $game->refresh();
    expect($game->won)->toBeTrue();
    expect($game->ended_at)->not->toBeNull();

    $logEvent->refresh();
    expect($logEvent->processed_at)->not->toBeNull();
});

it('does nothing if game already has a result (idempotent)', function () {
    $match = MtgoMatch::factory()->create([
        'token' => 'token-idempotent',
        'state' => MatchState::InProgress,
    ]);

    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => 99,
        'won' => false,
        'started_at' => now(),
        'ended_at' => now()->subMinutes(5),
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-idempotent',
        'game_id' => 99,
        'event_type' => 'game_result',
        'raw_text' => json_encode(['won' => true]),
    ]);

    $listener = new ResolveGameResult;
    $listener->handle(new GameResultDetermined($logEvent));

    $game->refresh();
    // Won should NOT be overwritten
    expect($game->won)->toBeFalse();
});

it('does nothing if game does not exist (ordering resilience)', function () {
    $match = MtgoMatch::factory()->create([
        'token' => 'token-no-game',
        'state' => MatchState::InProgress,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_token' => 'token-no-game',
        'game_id' => 999,
        'event_type' => 'game_result',
        'raw_text' => json_encode(['won' => true]),
    ]);

    $listener = new ResolveGameResult;
    $listener->handle(new GameResultDetermined($logEvent));

    expect(Game::where('mtgo_id', 999)->exists())->toBeFalse();
});

it('does nothing if match does not exist', function () {
    $logEvent = LogEvent::factory()->create([
        'match_token' => 'nonexistent-token',
        'game_id' => 42,
        'event_type' => 'game_result',
        'raw_text' => json_encode(['won' => true]),
    ]);

    $listener = new ResolveGameResult;
    $listener->handle(new GameResultDetermined($logEvent));

    expect(Game::where('mtgo_id', 42)->exists())->toBeFalse();
});
