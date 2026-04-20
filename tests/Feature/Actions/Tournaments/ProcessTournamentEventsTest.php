<?php

use App\Actions\Tournaments\ProcessTournamentEvents;
use App\Enums\TournamentState;
use App\Enums\TournamentTimelineEventType;
use App\Enums\TournamentType;
use App\Models\LogEvent;
use App\Models\Player;
use App\Models\Tournament;
use App\Models\TournamentStanding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createTournamentLogEvent(array $overrides = []): LogEvent
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
        'event_type' => 'tournament_state_changed',
        'match_token' => 'aaa-bbb-ccc',
        'ingested_at' => now(),
        'logged_at' => now(),
    ], $overrides));
}

it('does not create a tournament from a state changed event alone', function () {
    createTournamentLogEvent([
        'raw_text' => '18:42:27 [INF] (Game Management|Tournament State Changed for aaa-bbb-ccc from TournamentUninitializedState to PremierNotJoinedAwaitingMinPlayersState)',
        'event_type' => 'tournament_state_changed',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '2026-03-18 18:42:27',
        'logged_at' => '2026-03-18 18:42:27',
    ]);

    ProcessTournamentEvents::run();

    expect(Tournament::where('token', 'aaa-bbb-ccc')->first())->toBeNull();
});

it('leaves state change events unprocessed when no tournament exists for retry', function () {
    $event = createTournamentLogEvent([
        'raw_text' => '18:42:27 [INF] (Game Management|Tournament State Changed for aaa-bbb-ccc from TournamentUninitializedState to PremierNotJoinedAwaitingMinPlayersState)',
        'event_type' => 'tournament_state_changed',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '2026-03-18 18:42:27',
        'logged_at' => '2026-03-18 18:42:27',
    ]);

    ProcessTournamentEvents::run();

    $event->refresh();
    expect($event->processed_at)->toBeNull();
});

it('updates tournament state on subsequent state changes', function () {
    $tournament = Tournament::factory()->create([
        'token' => 'aaa-bbb-ccc',
        'state' => TournamentState::AwaitingPlayers,
    ]);

    createTournamentLogEvent([
        'raw_text' => '18:50:00 [INF] (Game Management|Tournament State Changed for aaa-bbb-ccc from PremierNotJoinedAwaitingMinPlayersState to TournamentNotJoinedRoundInProgressState)',
        'event_type' => 'tournament_state_changed',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '2026-03-18 18:50:00',
        'logged_at' => '2026-03-18 18:50:00',
    ]);

    ProcessTournamentEvents::run();

    $tournament->refresh();
    expect($tournament->state)->toBe(TournamentState::RoundInProgress);
});

it('creates timeline events for state changes on existing tournaments', function () {
    $tournament = Tournament::factory()->create([
        'token' => 'aaa-bbb-ccc',
        'state' => TournamentState::AwaitingPlayers,
    ]);

    createTournamentLogEvent([
        'raw_text' => '18:50:00 [INF] (Game Management|Tournament State Changed for aaa-bbb-ccc from PremierNotJoinedAwaitingMinPlayersState to TournamentNotJoinedRoundInProgressState)',
        'event_type' => 'tournament_state_changed',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '2026-03-18 18:50:00',
        'logged_at' => '2026-03-18 18:50:00',
    ]);

    ProcessTournamentEvents::run();

    $tournament->refresh();
    expect($tournament->timelineEvents)->toHaveCount(1)
        ->and($tournament->timelineEvents->first()->event_type)->toBe(TournamentTimelineEventType::StateChanged);
});

it('processes round results into standings', function () {
    $tournament = Tournament::factory()->inProgress()->create([
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

    createTournamentLogEvent([
        'raw_text' => "18:50:00 [INF] (Game Management|Processing Registered Handler for FlsTournamentRoundResultMessage) Message: {$json}",
        'event_type' => 'tournament_round_result',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '2026-03-18 18:50:00',
        'logged_at' => '2026-03-18 18:50:00',
    ]);

    ProcessTournamentEvents::run();

    $standing = TournamentStanding::where('tournament_id', $tournament->id)->first();
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

    $tournament = Tournament::factory()->inProgress()->create(['token' => 'aaa-bbb-ccc']);

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

    createTournamentLogEvent([
        'raw_text' => "18:50:00 [INF] Message: {$json}",
        'event_type' => 'tournament_round_result',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '2026-03-18 18:50:00',
        'logged_at' => '2026-03-18 18:50:00',
    ]);

    ProcessTournamentEvents::run();

    $standing = TournamentStanding::first();
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

    createTournamentLogEvent([
        'raw_text' => "18:42:28 [INF] (Game Management|Processing Registered Handler for EventSyncData_t in TournamentUninitializedState) Message: {$json}",
        'event_type' => 'tournament_sync',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '2026-03-18 18:42:28',
        'logged_at' => '2026-03-18 18:42:28',
    ]);

    ProcessTournamentEvents::run();

    $tournament = Tournament::where('token', 'aaa-bbb-ccc')->first();
    expect($tournament)->not->toBeNull()
        ->and($tournament->name)->toBe('Modern Challenge')
        ->and($tournament->description)->toBe('Modern')
        ->and($tournament->tournament_structure->value)->toBe('swiss')
        ->and($tournament->max_rounds)->toBe(7);

    expect(Player::where('login_id', 111)->first()->username)->toBe('Alice')
        ->and(Player::where('login_id', 222)->first()->username)->toBe('Bob');
});

it('marks events as processed', function () {
    $tournament = Tournament::factory()->create([
        'token' => 'aaa-bbb-ccc',
        'state' => TournamentState::AwaitingPlayers,
    ]);

    $event = createTournamentLogEvent([
        'raw_text' => '18:50:00 [INF] (Game Management|Tournament State Changed for aaa-bbb-ccc from PremierNotJoinedAwaitingMinPlayersState to TournamentNotJoinedRoundInProgressState)',
        'event_type' => 'tournament_state_changed',
        'match_token' => 'aaa-bbb-ccc',
        'timestamp' => '2026-03-18 18:50:00',
        'logged_at' => '2026-03-18 18:50:00',
    ]);

    ProcessTournamentEvents::run();

    $event->refresh();
    expect($event->processed_at)->not->toBeNull();
});

it('is idempotent — reprocessing does not duplicate standings', function () {
    $tournament = Tournament::factory()->inProgress()->create(['token' => 'aaa-bbb-ccc']);

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
        createTournamentLogEvent([
            'raw_text' => "18:50:0{$i} [INF] Message: {$json}",
            'event_type' => 'tournament_round_result',
            'match_token' => 'aaa-bbb-ccc',
            'timestamp' => "2026-03-18 18:50:0{$i}",
            'logged_at' => "2026-03-18 18:50:0{$i}",
        ]);
    }

    ProcessTournamentEvents::run();

    expect(TournamentStanding::count())->toBe(1);
});

it('populates event_id and type from EventSyncData_t', function () {
    $json = json_encode([
        'EventToken' => 'abc-123',
        'EventID' => 12839688,
        'Description' => 'Modern Challenge',
        'PlayFormatCd' => 'CMODERN',
        'GameStructureCd' => 'CMODERN',
        'Players' => [],
        'PremiereEventSyncData' => [
            'TournamentSyncData' => [
                'TournamentStructureCd' => 'SWISS',
                'NumberOfRounds' => 7,
                'MinPlayers' => 32,
                'MaxPlayers' => 256,
            ],
        ],
    ]);

    createTournamentLogEvent([
        'raw_text' => "18:42:28 [INF] (Game Management|Processing Registered Handler for EventSyncData_t in TournamentUninitializedState) Message: {$json}",
        'event_type' => 'tournament_sync',
        'match_token' => 'abc-123',
        'timestamp' => '2026-03-18 18:42:28',
        'logged_at' => '2026-03-18 18:42:28',
    ]);

    ProcessTournamentEvents::run();

    $tournament = Tournament::where('token', 'abc-123')->firstOrFail();

    expect($tournament->event_id)->toBe(12839688)
        ->and($tournament->type)->toBe(TournamentType::Constructed);
});

it('enriches a stub tournament when sync data arrives after participation', function () {
    $stub = Tournament::factory()->create([
        'event_id' => 12839688,
        'token' => (string) Str::uuid(),
        'name' => null,
        'description' => null,
        'max_rounds' => null,
        'type' => TournamentType::Constructed,
        'state' => TournamentState::RoundInProgress,
        'participated' => true,
    ]);

    $json = json_encode([
        'EventToken' => 'real-token-123',
        'EventID' => 12839688,
        'Description' => 'Modern Challenge',
        'FormatDescription' => '[b]Modern[/b]',
        'PlayFormatCd' => 'CMODERN',
        'GameStructureCd' => 'CMODERN',
        'Players' => [
            ['LoginID' => 111, 'PlayerName' => 'Alice', 'AvatarID' => 1, 'State' => 1, 'IsMatchConceded' => false],
        ],
        'PremiereEventSyncData' => [
            'TournamentSyncData' => [
                'TournamentStructureCd' => 'SWISS',
                'NumberOfRounds' => 7,
                'MinPlayers' => 32,
                'MaxPlayers' => 256,
            ],
        ],
    ]);

    createTournamentLogEvent([
        'raw_text' => "18:42:28 [INF] (Game Management|Processing Registered Handler for EventSyncData_t in TournamentUninitializedState) Message: {$json}",
        'event_type' => 'tournament_sync',
        'match_token' => 'real-token-123',
        'timestamp' => '2026-03-18 18:42:28',
        'logged_at' => '2026-03-18 18:42:28',
    ]);

    ProcessTournamentEvents::run();

    expect(Tournament::where('event_id', 12839688)->count())->toBe(1);

    $stub->refresh();
    expect($stub->token)->toBe('real-token-123')
        ->and($stub->name)->toBe('Modern Challenge')
        ->and($stub->max_rounds)->toBe(7)
        ->and($stub->participated)->toBeTrue();
});
