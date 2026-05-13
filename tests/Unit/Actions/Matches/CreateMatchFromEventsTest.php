<?php

use App\Actions\Matches\CreateMatchFromEvents;
use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Jobs\SubmitMatchLogSample;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

function buildJoinRawTextForCreate(array $meta = []): string
{
    $defaults = [
        'PlayFormatCd' => 'Pmodern',
        'GameStructureCd' => 'Constructed',
    ];

    $meta = array_merge($defaults, $meta);

    $lines = ['12:00:00 [INF] (Match|MatchJoinedEventUnderwayState)', 'Receiver:'];

    foreach ($meta as $key => $value) {
        $lines[] = "{$key} = {$value}";
    }

    return implode("\n", $lines);
}

it('creates a match in Started state from a join event', function () {
    $joinedState = LogEvent::factory()->make([
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'Match|MatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawTextForCreate(),
    ]);

    $match = CreateMatchFromEvents::run(
        matchToken: 'token-create',
        matchId: '50000',
        events: new Collection([$joinedState]),
        joinedState: $joinedState,
    );

    expect($match)->not->toBeNull();
    expect($match->mtgo_id)->toBe('50000');
    expect($match->token)->toBe('token-create');
    expect($match->state)->toBe(MatchState::Started);
    expect($match->format)->toBe('Pmodern');
    expect($match->match_type)->toBe('Constructed');
    expect($match->tournament_event_id)->toBeNull();
    expect($match->tournament_round)->toBeNull();
});

it('parses tournament_event_id and tournament_round from the Description', function () {
    $joinedState = LogEvent::factory()->make([
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'Match|TournamentMatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawTextForCreate(['Description' => 'Tournament:12345 Round:3']),
    ]);

    $match = CreateMatchFromEvents::run(
        matchToken: 'token-tourn',
        matchId: '50001',
        events: new Collection([$joinedState]),
        joinedState: $joinedState,
    );

    expect($match)->not->toBeNull();
    expect($match->tournament_event_id)->toBe(12345);
    expect($match->tournament_round)->toBe(3);
});

it('returns null when game-state players do not include the local user (phantom match)', function () {
    $joinedState = LogEvent::factory()->create([
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'Match|MatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawTextForCreate(),
        'match_id' => '50002',
        'match_token' => 'token-phantom',
    ]);

    $stateJson = json_encode(['Players' => [
        ['Id' => 1, 'Name' => 'Stranger1'],
        ['Id' => 2, 'Name' => 'Stranger2'],
    ], 'Cards' => []]);

    $gameStateEvent = LogEvent::factory()->create([
        'event_type' => LogEventType::GAME_STATE_UPDATE->value,
        'game_id' => 60001,
        'username' => 'LocalPlayer',
        'raw_text' => "12:00:01 [INF] (GameState|Update) Game ID: 60001, Match ID: 50002\n{$stateJson}",
        'match_id' => '50002',
        'match_token' => 'token-phantom',
    ]);

    $match = CreateMatchFromEvents::run(
        matchToken: 'token-phantom',
        matchId: '50002',
        events: new Collection([$joinedState, $gameStateEvent]),
        joinedState: $joinedState,
    );

    expect($match)->toBeNull();
    expect(MtgoMatch::where('mtgo_id', '50002')->exists())->toBeFalse();
});

it('returns null when game-state has only one player', function () {
    $joinedState = LogEvent::factory()->create([
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'Match|MatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawTextForCreate(),
        'match_id' => '50003',
        'match_token' => 'token-solo',
    ]);

    $stateJson = json_encode(['Players' => [
        ['Id' => 1, 'Name' => 'LocalPlayer'],
    ], 'Cards' => []]);

    $gameStateEvent = LogEvent::factory()->create([
        'event_type' => LogEventType::GAME_STATE_UPDATE->value,
        'game_id' => 60002,
        'username' => 'LocalPlayer',
        'raw_text' => "12:00:01 [INF] (GameState|Update) Game ID: 60002, Match ID: 50003\n{$stateJson}",
        'match_id' => '50003',
        'match_token' => 'token-solo',
    ]);

    $match = CreateMatchFromEvents::run(
        matchToken: 'token-solo',
        matchId: '50003',
        events: new Collection([$joinedState, $gameStateEvent]),
        joinedState: $joinedState,
    );

    expect($match)->toBeNull();
});

it('creates a match when game state confirms the local player with an opponent', function () {
    $joinedState = LogEvent::factory()->create([
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'Match|MatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawTextForCreate(),
        'match_id' => '50004',
        'match_token' => 'token-legit',
    ]);

    $stateJson = json_encode(['Players' => [
        ['Id' => 1, 'Name' => 'LocalPlayer'],
        ['Id' => 2, 'Name' => 'Opponent'],
    ], 'Cards' => []]);

    $gameStateEvent = LogEvent::factory()->create([
        'event_type' => LogEventType::GAME_STATE_UPDATE->value,
        'game_id' => 60003,
        'username' => 'LocalPlayer',
        'raw_text' => "12:00:01 [INF] (GameState|Update) Game ID: 60003, Match ID: 50004\n{$stateJson}",
        'match_id' => '50004',
        'match_token' => 'token-legit',
    ]);

    $match = CreateMatchFromEvents::run(
        matchToken: 'token-legit',
        matchId: '50004',
        events: new Collection([$joinedState, $gameStateEvent]),
        joinedState: $joinedState,
    );

    expect($match)->not->toBeNull();
    expect($match->mtgo_id)->toBe('50004');
});

it('dispatches a SubmitMatchLogSample job on creation', function () {
    $joinedState = LogEvent::factory()->make([
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'Match|MatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawTextForCreate(),
    ]);

    CreateMatchFromEvents::run(
        matchToken: 'token-sample',
        matchId: '50005',
        events: new Collection([$joinedState]),
        joinedState: $joinedState,
    );

    Queue::assertPushed(SubmitMatchLogSample::class);
});
