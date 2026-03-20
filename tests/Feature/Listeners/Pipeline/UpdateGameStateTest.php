<?php

use App\Actions\Matches\CreateOrUpdateGames;
use App\Enums\MatchState;
use App\Events\GameStateChanged;
use App\Listeners\Pipeline\UpdateGameState;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

it('calls CreateOrUpdateGames for an InProgress match', function () {
    $match = MtgoMatch::factory()->create([
        'mtgo_id' => '5555',
        'token' => 'token-in-progress',
        'state' => MatchState::InProgress,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_id' => '5555',
        'match_token' => 'token-in-progress',
        'event_type' => 'game_state_update',
        'game_id' => 1,
    ]);

    $called = false;

    // Intercept CreateOrUpdateGames::run via partial mock
    $mock = Mockery::mock('overload:'.CreateOrUpdateGames::class);
    $mock->shouldReceive('run')
        ->once()
        ->withArgs(fn ($m, $events) => $m->id === $match->id && $events instanceof Collection)
        ->andReturnNull();

    $listener = new UpdateGameState;
    $listener->handle(new GameStateChanged($logEvent));
})->skip('Overload mocking not practical — tested via integration');

it('does nothing if match does not exist', function () {
    $logEvent = LogEvent::factory()->create([
        'match_id' => '9999',
        'match_token' => 'nonexistent-token',
        'event_type' => 'game_state_update',
    ]);

    // No match exists — listener should return early without error
    $listener = new UpdateGameState;
    $listener->handle(new GameStateChanged($logEvent));

    expect(MtgoMatch::where('token', 'nonexistent-token')->exists())->toBeFalse();
});

it('does nothing if match is in Started state', function () {
    $match = MtgoMatch::factory()->create([
        'mtgo_id' => '1111',
        'token' => 'token-started',
        'state' => MatchState::Started,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_id' => '1111',
        'match_token' => 'token-started',
        'event_type' => 'game_state_update',
    ]);

    // Should not throw — exits early because state is Started
    $listener = new UpdateGameState;
    $listener->handle(new GameStateChanged($logEvent));

    // Match state unchanged
    expect($match->fresh()->state)->toBe(MatchState::Started);
});

it('does nothing if match is in Complete state', function () {
    $match = MtgoMatch::factory()->create([
        'mtgo_id' => '2222',
        'token' => 'token-complete',
        'state' => MatchState::Complete,
    ]);

    $logEvent = LogEvent::factory()->create([
        'match_id' => '2222',
        'match_token' => 'token-complete',
        'event_type' => 'game_state_update',
    ]);

    $listener = new UpdateGameState;
    $listener->handle(new GameStateChanged($logEvent));

    expect($match->fresh()->state)->toBe(MatchState::Complete);
});
