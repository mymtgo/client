<?php

use App\Actions\Challenges\ProcessChallengeEvents;
use App\Enums\ChallengeTimelineEventType;
use App\Enums\TournamentState;
use App\Models\Challenge;
use App\Models\ChallengeStanding;
use App\Models\LogEvent;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createChallengeLogEvent(array $overrides = []): LogEvent
{
    return LogEvent::create(array_merge([
        'file_path' => '/test/log.txt',
        'byte_offset_start' => rand(1, 999999),
        'byte_offset_end' => rand(1, 999999),
        'timestamp' => now(),
        'level' => 'INF',
        'category' => 'Game Management',
        'context' => '',
        'raw_text' => '',
        'event_type' => 'challenge_state_changed',
        'match_token' => 'aaa-bbb-ccc',
        'ingested_at' => now(),
        'logged_at' => now(),
    ], $overrides));
}

it('does not create a challenge from a state changed event alone', function () {
    createChallengeLogEvent([
        'raw_text' => '18:42:27 [INF] (Game Management|Tournament State Changed for aaa-bbb-ccc from TournamentUninitializedState to PremierNotJoinedAwaitingMinPlayersState)',
        'event_type' => 'challenge_state_changed',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '2026-03-18 18:42:27',
        'logged_at' => '2026-03-18 18:42:27',
    ]);

    ProcessChallengeEvents::run();

    expect(Challenge::where('token', 'aaa-bbb-ccc')->first())->toBeNull();
});

it('leaves state change events unprocessed when no challenge exists for retry', function () {
    $event = createChallengeLogEvent([
        'raw_text' => '18:42:27 [INF] (Game Management|Tournament State Changed for aaa-bbb-ccc from TournamentUninitializedState to PremierNotJoinedAwaitingMinPlayersState)',
        'event_type' => 'challenge_state_changed',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '2026-03-18 18:42:27',
        'logged_at' => '2026-03-18 18:42:27',
    ]);

    ProcessChallengeEvents::run();

    $event->refresh();
    expect($event->processed_at)->toBeNull();
});

it('updates challenge state on subsequent state changes', function () {
    $challenge = Challenge::factory()->create([
        'token' => 'aaa-bbb-ccc',
        'state' => TournamentState::AwaitingPlayers,
    ]);

    createChallengeLogEvent([
        'raw_text' => '18:50:00 [INF] (Game Management|Tournament State Changed for aaa-bbb-ccc from PremierNotJoinedAwaitingMinPlayersState to TournamentNotJoinedRoundInProgressState)',
        'event_type' => 'challenge_state_changed',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '2026-03-18 18:50:00',
        'logged_at' => '2026-03-18 18:50:00',
    ]);

    ProcessChallengeEvents::run();

    $challenge->refresh();
    expect($challenge->state)->toBe(TournamentState::RoundInProgress);
});

it('creates timeline events for state changes on existing challenges', function () {
    $challenge = Challenge::factory()->create([
        'token' => 'aaa-bbb-ccc',
        'state' => TournamentState::AwaitingPlayers,
    ]);

    createChallengeLogEvent([
        'raw_text' => '18:50:00 [INF] (Game Management|Tournament State Changed for aaa-bbb-ccc from PremierNotJoinedAwaitingMinPlayersState to TournamentNotJoinedRoundInProgressState)',
        'event_type' => 'challenge_state_changed',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '2026-03-18 18:50:00',
        'logged_at' => '2026-03-18 18:50:00',
    ]);

    ProcessChallengeEvents::run();

    $challenge->refresh();
    expect($challenge->timelineEvents)->toHaveCount(1)
        ->and($challenge->timelineEvents->first()->event_type)->toBe(ChallengeTimelineEventType::StateChanged);
});

it('processes round results into standings', function () {
    $challenge = Challenge::factory()->inProgress()->create([
        'token' => 'aaa-bbb-ccc',
    ]);

    $json = json_encode([
        'Token' => 'aaa-bbb-ccc',
        'Round' => 1,
        'Results' => [
            [
                'LoginID' => 12345,
                'Rank' => 1,
                'Points' => 3,
                'OpponentResults' => [['Round' => 1, 'LoginID' => 67890, 'Win' => 2, 'Loss' => 0, 'Draw' => 0, 'Bye' => 0]],
                'OpponentMatchWinPercentage' => '0.5556',
                'GameWinPercentage' => '0.8571',
            ],
        ],
    ]);

    createChallengeLogEvent([
        'raw_text' => "18:50:00 [INF] (Game Management|Processing Registered Handler for FlsTournamentRoundResultMessage) Message: {$json}",
        'event_type' => 'challenge_round_result',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '2026-03-18 18:50:00',
        'logged_at' => '2026-03-18 18:50:00',
    ]);

    ProcessChallengeEvents::run();

    $standing = ChallengeStanding::where('challenge_id', $challenge->id)->first();
    expect($standing)->not->toBeNull()
        ->and($standing->login_id)->toBe(12345)
        ->and($standing->rank)->toBe(1)
        ->and($standing->points)->toBe(3)
        ->and($standing->wins)->toBe(1)
        ->and($standing->losses)->toBe(0)
        ->and($standing->draws)->toBe(0);
});

it('resolves usernames from players table', function () {
    Player::create(['username' => 'TestPlayer', 'login_id' => 12345]);

    $challenge = Challenge::factory()->inProgress()->create(['token' => 'aaa-bbb-ccc']);

    $json = json_encode([
        'Token' => 'aaa-bbb-ccc',
        'Round' => 1,
        'Results' => [
            [
                'LoginID' => 12345,
                'Rank' => 1,
                'Points' => 3,
                'OpponentResults' => [['Round' => 1, 'LoginID' => 99999, 'Win' => 2, 'Loss' => 0, 'Draw' => 0, 'Bye' => 0]],
                'OpponentMatchWinPercentage' => '0.5000',
                'GameWinPercentage' => '1.0000',
            ],
        ],
    ]);

    createChallengeLogEvent([
        'raw_text' => "18:50:00 [INF] Message: {$json}",
        'event_type' => 'challenge_round_result',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '2026-03-18 18:50:00',
        'logged_at' => '2026-03-18 18:50:00',
    ]);

    ProcessChallengeEvents::run();

    $standing = ChallengeStanding::first();
    expect($standing->username)->toBe('TestPlayer');
});

it('processes sync data and creates player mappings', function () {
    $json = json_encode([
        'EventToken' => 'aaa-bbb-ccc',
        'EventID' => 12835954,
        'Description' => 'Modern Challenge',
        'FormatDescription' => '[b]Modern[/b]',
        'Players' => [
            ['LoginID' => 111, 'PlayerName' => 'Alice', 'AvatarID' => 1, 'State' => 1, 'IsMatchConceded' => false],
            ['LoginID' => 222, 'PlayerName' => 'Bob', 'AvatarID' => 2, 'State' => 1, 'IsMatchConceded' => false],
        ],
        'GameStructureCd' => 'CMODERN',
        'PremiereEventSyncData' => [
            'TournamentSyncData' => [
                'TournamentStructureCd' => 'SWISS',
                'NumberOfRounds' => 7,
                'MinPlayers' => 32,
                'MaxPlayers' => 256,
            ],
        ],
    ]);

    createChallengeLogEvent([
        'raw_text' => "18:42:28 [INF] (Game Management|Processing Registered Handler for EventSyncData_t in TournamentUninitializedState) Message: {$json}",
        'event_type' => 'challenge_sync',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '2026-03-18 18:42:28',
        'logged_at' => '2026-03-18 18:42:28',
    ]);

    ProcessChallengeEvents::run();

    $challenge = Challenge::where('token', 'aaa-bbb-ccc')->first();
    expect($challenge)->not->toBeNull()
        ->and($challenge->name)->toBe('Modern Challenge')
        ->and($challenge->description)->toBe('Modern')
        ->and($challenge->tournament_structure->value)->toBe('swiss')
        ->and($challenge->max_rounds)->toBe(7);

    expect(Player::where('login_id', 111)->first()->username)->toBe('Alice')
        ->and(Player::where('login_id', 222)->first()->username)->toBe('Bob');
});

it('marks events as processed', function () {
    $challenge = Challenge::factory()->create([
        'token' => 'aaa-bbb-ccc',
        'state' => TournamentState::AwaitingPlayers,
    ]);

    $event = createChallengeLogEvent([
        'raw_text' => '18:50:00 [INF] (Game Management|Tournament State Changed for aaa-bbb-ccc from PremierNotJoinedAwaitingMinPlayersState to TournamentNotJoinedRoundInProgressState)',
        'event_type' => 'challenge_state_changed',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '2026-03-18 18:50:00',
        'logged_at' => '2026-03-18 18:50:00',
    ]);

    ProcessChallengeEvents::run();

    $event->refresh();
    expect($event->processed_at)->not->toBeNull();
});

it('is idempotent — reprocessing does not duplicate standings', function () {
    $challenge = Challenge::factory()->inProgress()->create(['token' => 'aaa-bbb-ccc']);

    $json = json_encode([
        'Token' => 'aaa-bbb-ccc',
        'Round' => 1,
        'Results' => [
            [
                'LoginID' => 12345,
                'Rank' => 1,
                'Points' => 3,
                'OpponentResults' => [['Round' => 1, 'LoginID' => 99999, 'Win' => 2, 'Loss' => 1, 'Draw' => 0, 'Bye' => 0]],
                'OpponentMatchWinPercentage' => '0.5000',
                'GameWinPercentage' => '0.8000',
            ],
        ],
    ]);

    foreach ([1, 2] as $i) {
        createChallengeLogEvent([
            'raw_text' => "18:50:0{$i} [INF] Message: {$json}",
            'event_type' => 'challenge_round_result',
            'match_token' => 'aaa-bbb-ccc',
            'timestamp' => "2026-03-18 18:50:0{$i}",
            'logged_at' => "2026-03-18 18:50:0{$i}",
        ]);
    }

    ProcessChallengeEvents::run();

    expect(ChallengeStanding::count())->toBe(1);
});
