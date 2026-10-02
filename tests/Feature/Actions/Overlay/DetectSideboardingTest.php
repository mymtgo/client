<?php

use App\Actions\Overlay\DetectSideboarding;
use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Models\Game;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sideboardingMatch(string $token): MtgoMatch
{
    return MtgoMatch::create([
        'mtgo_id' => 'm-'.$token, 'token' => $token, 'format' => 'CModern',
        'match_type' => 'League', 'state' => MatchState::InProgress, 'started_at' => now()->subMinutes(20),
    ]);
}

function sideboardingStateEvent(string $token, string $context, Carbon\Carbon $at): LogEvent
{
    $instance = LogInstance::factory()->create();

    return LogEvent::create([
        'log_instance_id' => $instance->id,
        'file_path' => 'mtgo.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => 1,
        'timestamp' => $at->format('H:i:s'),
        'logged_at' => $at->copy(),
        'level' => 'INF',
        'category' => 'Game Management',
        'context' => $context,
        'raw_text' => 'Match State Changed for '.$token.' — '.$context,
        'ingested_at' => now(),
        'match_token' => $token,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
    ]);
}

it('is false before any game has ended', function () {
    $match = sideboardingMatch('tok-sb-pre');

    sideboardingStateEvent(
        'tok-sb-pre',
        'Match State Changed from MatchJoinedEventUnderwayState to MatchJoinedSideboardingState',
        now()->subMinutes(19),
    );

    expect(DetectSideboarding::run($match))->toBeFalse();
});

it('is true when sideboarding follows a finished game', function () {
    $match = sideboardingMatch('tok-sb-post');

    Game::create([
        'match_id' => $match->id, 'mtgo_id' => 'g-1',
        'started_at' => now()->subMinutes(18), 'ended_at' => now()->subMinutes(5),
    ]);

    sideboardingStateEvent(
        'tok-sb-post',
        'Match State Changed from MatchJoinedGameStartedState to MatchJoinedSideboardingState',
        now()->subMinutes(4),
    );

    expect(DetectSideboarding::run($match))->toBeTrue();
});

it('recognises league and tournament state prefixes', function (string $context) {
    $match = sideboardingMatch('tok-sb-'.md5($context));

    Game::create([
        'match_id' => $match->id, 'mtgo_id' => 'g-1',
        'started_at' => now()->subMinutes(18), 'ended_at' => now()->subMinutes(5),
    ]);

    sideboardingStateEvent($match->token, $context, now()->subMinutes(4));

    expect(DetectSideboarding::run($match))->toBeTrue();
})->with([
    'Match State Changed to LeagueMatchJoinedSideboardingState',
    'Match State Changed to TournamentMatchJoinedSideboardingState',
]);

it('is false again once the next game has a snapshot', function () {
    $match = sideboardingMatch('tok-sb-resumed');

    Game::create([
        'match_id' => $match->id, 'mtgo_id' => 'g-1',
        'started_at' => now()->subMinutes(18), 'ended_at' => now()->subMinutes(5),
    ]);

    sideboardingStateEvent(
        'tok-sb-resumed',
        'Match State Changed to MatchJoinedSideboardingState',
        now()->subMinutes(4),
    );

    $game2 = Game::create([
        'match_id' => $match->id, 'mtgo_id' => 'g-2', 'started_at' => now()->subMinutes(3),
    ]);

    $game2->timeline()->create([
        'timestamp' => now()->subMinutes(2),
        'content' => ['Players' => []],
    ]);

    expect(DetectSideboarding::run($match))->toBeFalse();
});

it('is false when no sideboarding event exists', function () {
    $match = sideboardingMatch('tok-sb-absent');

    Game::create([
        'match_id' => $match->id, 'mtgo_id' => 'g-1',
        'started_at' => now()->subMinutes(18), 'ended_at' => now()->subMinutes(5),
    ]);

    expect(DetectSideboarding::run($match))->toBeFalse();
});

it('is true when the sideboarding transition lands in the same second the game ended', function () {
    $match = sideboardingMatch('tok-sb-same-second');
    $gameEnd = now()->subMinutes(5)->startOfSecond();

    Game::create([
        'match_id' => $match->id, 'mtgo_id' => 'g-1',
        'started_at' => now()->subMinutes(18), 'ended_at' => $gameEnd,
    ]);

    sideboardingStateEvent(
        'tok-sb-same-second',
        'Match State Changed from LeagueMatchJoinedEventUnderwayState to LeagueMatchJoinedSideboardingState',
        $gameEnd->copy(),
    );

    expect(DetectSideboarding::run($match))->toBeTrue();
});

it('is true when ended_at trails the sideboarding transition by several seconds', function () {
    $match = sideboardingMatch('tok-sb-trailing-end');
    $transitionAt = now()->subMinutes(5)->startOfSecond();

    Game::create([
        'match_id' => $match->id, 'mtgo_id' => 'g-1',
        'started_at' => now()->subMinutes(18), 'ended_at' => $transitionAt->copy()->addSeconds(5),
    ]);

    sideboardingStateEvent(
        'tok-sb-trailing-end',
        'Match State Changed from MatchJoinedEventUnderwayState to MatchJoinedSideboardingState',
        $transitionAt,
    );

    expect(DetectSideboarding::run($match))->toBeTrue();
});

it('stays false during a game whose sideboarding transition preceded its start', function () {
    $match = sideboardingMatch('tok-sb-mid-game');

    Game::create([
        'match_id' => $match->id, 'mtgo_id' => 'g-1',
        'started_at' => now()->subMinutes(18), 'ended_at' => now()->subMinutes(10),
    ]);

    sideboardingStateEvent(
        'tok-sb-mid-game',
        'Match State Changed from MatchJoinedEventUnderwayState to MatchJoinedSideboardingState',
        now()->subMinutes(10),
    );

    Game::create([
        'match_id' => $match->id, 'mtgo_id' => 'g-2',
        'started_at' => now()->subMinutes(9), 'ended_at' => now()->subMinute(),
    ]);

    expect(DetectSideboarding::run($match))->toBeFalse();
});

it('ignores the exit transition when it shares a second with the next game start', function () {
    $match = sideboardingMatch('tok-sb-exit-same-second');
    $nextGameStart = now()->subMinutes(2)->startOfSecond();

    Game::create([
        'match_id' => $match->id, 'mtgo_id' => 'g-1',
        'started_at' => now()->subMinutes(18), 'ended_at' => now()->subMinutes(5),
    ]);

    sideboardingStateEvent(
        'tok-sb-exit-same-second',
        'Match State Changed from LeagueMatchJoinedEventUnderwayState to LeagueMatchJoinedSideboardingState',
        now()->subMinutes(5),
    );

    sideboardingStateEvent(
        'tok-sb-exit-same-second',
        'Match State Changed from LeagueMatchJoinedSideboardingState to LeagueMatchSideboardingDeckSubmittedState',
        $nextGameStart->copy(),
    );

    Game::create([
        'match_id' => $match->id, 'mtgo_id' => 'g-2',
        'started_at' => $nextGameStart,
    ]);

    expect(DetectSideboarding::run($match))->toBeFalse();
});
