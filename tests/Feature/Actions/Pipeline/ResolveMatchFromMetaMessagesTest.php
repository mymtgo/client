<?php

use App\Actions\Pipeline\ResolveMatchFromMetaMessages;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use App\Models\Player;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function resolveMetaTest_makeMatch(string $token, MatchState $state = MatchState::InProgress): MtgoMatch
{
    return MtgoMatch::factory()->create([
        'token' => $token,
        'state' => $state,
        'mtgo_id' => 123,
    ]);
}

function resolveMetaTest_setupMatchWithPlayers(string $token, string $localUsername = 'TestPlayer', string $oppUsername = 'Opp', MatchState $state = MatchState::InProgress): MtgoMatch
{
    $match = MtgoMatch::factory()->create([
        'token' => $token,
        'state' => $state,
        'mtgo_id' => 123,
    ]);

    $localPlayer = Player::factory()->create(['username' => $localUsername]);
    $opponent = Player::factory()->create(['username' => $oppUsername]);

    $game = Game::factory()->create(['match_id' => $match->id]);
    $game->players()->attach($localPlayer->id, ['is_local' => 1, 'instance_id' => 1]);
    $game->players()->attach($opponent->id, ['is_local' => 0, 'instance_id' => 2]);

    return $match;
}

function resolveMetaTest_seedCompletedSignal(string $token, LogInstance $instance, string $variant = 'LeagueMatchJoinedCompletedState'): void
{
    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'match_token' => $token,
        'event_type' => 'match_state_changed',
        'timestamp' => Carbon::parse('2026-05-26 10:00:30'),
        'byte_offset_start' => 9000,
        'raw_text' => "02:00:30 [INF] (Game Management|Match State Changed for {$token} from LeagueMatchJoinedEventUnderwayState to {$variant})",
    ]);
}

function resolveMetaTest_seedMetaMessage(string $token, LogInstance $instance, string $text, int $secondsOffset): void
{
    $textBytes = array_map('ord', str_split($text));
    $len = strlen($text);
    $bytes = array_merge(
        [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [$len, 0, 0, 0],
        $textBytes
    );

    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'match_token' => $token,
        'match_id' => 123,
        'game_id' => 999,
        'event_type' => 'game_management_json',
        'timestamp' => Carbon::parse('2026-05-26 10:00:00')->addSeconds($secondsOffset),
        'byte_offset_start' => $secondsOffset * 100,
        'raw_text' => '02:00:'.str_pad((string) $secondsOffset, 2, '0', STR_PAD_LEFT).' [INF] (Game Management|Processing) Message: {"MatchToken":"'.$token.'","MatchID":123,"GameID":999,"MetaMessage":['.implode(',', $bytes).']}',
    ]);
}

it('is a no-op when match has no players', function () {
    $token = 'a1111111-1111-1111-1111-111111111111';
    $match = resolveMetaTest_makeMatch($token);

    ResolveMatchFromMetaMessages::run($match);

    $match->refresh();
    expect($match->state)->toBe(MatchState::InProgress);
});

it('is a no-op when CompletedState signal is absent', function () {
    $token = 'a2222222-2222-2222-2222-222222222222';
    $match = resolveMetaTest_setupMatchWithPlayers($token, 'TestPlayer', 'Opp');
    $instance = LogInstance::factory()->create();

    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer rolled a 1.', 1);
    resolveMetaTest_seedMetaMessage($token, $instance, '@POpp rolled a 6.', 2);

    Mtgo::shouldReceive('resolveUsername')->andReturn('TestPlayer');

    ResolveMatchFromMetaMessages::run($match);

    $match->refresh();
    expect($match->state)->toBe(MatchState::InProgress);
});

it('marks match Complete when CompletedState + decisive entries present', function () {
    $token = 'a3333333-3333-3333-3333-333333333333';
    $match = resolveMetaTest_setupMatchWithPlayers($token, 'TestPlayer', 'Opp');
    $instance = LogInstance::factory()->create();

    resolveMetaTest_seedMetaMessage($token, $instance, '@POpp rolled a 1.', 1);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer rolled a 5.', 2);
    resolveMetaTest_seedMetaMessage($token, $instance, '@P@PTestPlayer joined the game.', 3);
    resolveMetaTest_seedMetaMessage($token, $instance, '@P@POpp joined the game.', 4);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer chooses to play first.', 5);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer begins the game with seven cards in hand.', 6);
    resolveMetaTest_seedMetaMessage($token, $instance, '@POpp begins the game with seven cards in hand.', 7);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer wins the game.', 8);

    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer rolled a 4.', 10);
    resolveMetaTest_seedMetaMessage($token, $instance, '@POpp rolled a 2.', 11);
    resolveMetaTest_seedMetaMessage($token, $instance, '@P@PTestPlayer joined the game.', 12);
    resolveMetaTest_seedMetaMessage($token, $instance, '@P@POpp joined the game.', 13);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer chooses to play first.', 14);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer begins the game with seven cards in hand.', 15);
    resolveMetaTest_seedMetaMessage($token, $instance, '@POpp begins the game with seven cards in hand.', 16);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer wins the game.', 17);

    resolveMetaTest_seedCompletedSignal($token, $instance);

    Mtgo::shouldReceive('resolveUsername')->andReturn('TestPlayer');

    ResolveMatchFromMetaMessages::run($match);

    $match->refresh();
    expect($match->state)->toBe(MatchState::Complete)
        ->and($match->outcome)->toBe(MatchOutcome::Win)
        ->and($match->ended_at)->not->toBeNull();
});

it('marks match Complete for concede end_reason', function () {
    $token = 'a4444444-4444-4444-4444-444444444444';
    $match = resolveMetaTest_setupMatchWithPlayers($token, 'TestPlayer', 'Opp');
    $instance = LogInstance::factory()->create();

    resolveMetaTest_seedMetaMessage($token, $instance, '@POpp rolled a 1.', 1);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer rolled a 5.', 2);
    resolveMetaTest_seedMetaMessage($token, $instance, '@P@PTestPlayer joined the game.', 3);
    resolveMetaTest_seedMetaMessage($token, $instance, '@P@POpp joined the game.', 4);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer chooses to play first.', 5);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer begins the game with seven cards in hand.', 6);
    resolveMetaTest_seedMetaMessage($token, $instance, '@POpp begins the game with seven cards in hand.', 7);
    resolveMetaTest_seedMetaMessage($token, $instance, '@POpp has conceded from the game.', 8);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer wins the game.', 9);

    resolveMetaTest_seedCompletedSignal($token, $instance);

    Mtgo::shouldReceive('resolveUsername')->andReturn('TestPlayer');

    ResolveMatchFromMetaMessages::run($match);

    $match->refresh();
    expect($match->state)->toBe(MatchState::Complete)
        ->and($match->outcome)->toBe(MatchOutcome::Win);
});

it('detects the tournament MatchJoinedCompletedState variant', function () {
    $token = 'a5555555-5555-5555-5555-555555555555';
    $match = resolveMetaTest_setupMatchWithPlayers($token, 'TestPlayer', 'Opp');
    $instance = LogInstance::factory()->create();

    resolveMetaTest_seedMetaMessage($token, $instance, '@POpp rolled a 1.', 1);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer rolled a 5.', 2);
    resolveMetaTest_seedMetaMessage($token, $instance, '@P@PTestPlayer joined the game.', 3);
    resolveMetaTest_seedMetaMessage($token, $instance, '@P@POpp joined the game.', 4);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer chooses to play first.', 5);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer wins the game.', 6);

    resolveMetaTest_seedCompletedSignal($token, $instance, 'MatchJoinedCompletedState');

    Mtgo::shouldReceive('resolveUsername')->andReturn('TestPlayer');

    ResolveMatchFromMetaMessages::run($match);

    $match->refresh();
    expect($match->state)->toBe(MatchState::Complete);
});

it('is idempotent — second run is no-op on already-Complete match', function () {
    $token = 'a6666666-6666-6666-6666-666666666666';
    $match = resolveMetaTest_setupMatchWithPlayers($token, 'TestPlayer', 'Opp');
    $instance = LogInstance::factory()->create();

    resolveMetaTest_seedMetaMessage($token, $instance, '@POpp rolled a 1.', 1);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer rolled a 5.', 2);
    resolveMetaTest_seedMetaMessage($token, $instance, '@P@PTestPlayer joined the game.', 3);
    resolveMetaTest_seedMetaMessage($token, $instance, '@P@POpp joined the game.', 4);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer chooses to play first.', 5);
    resolveMetaTest_seedMetaMessage($token, $instance, '@PTestPlayer wins the game.', 6);
    resolveMetaTest_seedCompletedSignal($token, $instance);

    Mtgo::shouldReceive('resolveUsername')->andReturn('TestPlayer');

    ResolveMatchFromMetaMessages::run($match);
    $match->refresh();

    ResolveMatchFromMetaMessages::run($match);
    $match->refresh();

    expect($match->state)->toBe(MatchState::Complete);
});
