<?php

use App\Actions\Matches\AdvanceMatchState;
use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Helper: create a log event with sensible defaults.
 */
function createLogEvent(array $overrides = []): LogEvent
{
    return LogEvent::create(array_merge([
        'file_path' => '/tmp/test.log',
        'byte_offset_start' => rand(0, 999999),
        'byte_offset_end' => rand(1000000, 9999999),
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Match',
        'context' => '',
        'raw_text' => '',
        'event_type' => null,
        'logged_at' => now(),
        'match_id' => null,
        'match_token' => null,
        'ingested_at' => now(),
    ], $overrides));
}

/**
 * Helper: build the raw_text that ExtractKeyValueBlock can parse.
 * The parser requires "Receiver:" to be present before the key-value lines.
 */
function buildJoinRawText(array $meta = []): string
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

// ─────────────────────────────────────────────────────────────────────────────

it('does not create a match without a join event', function () {
    $matchId = '99999';
    $matchToken = 'token-no-join';

    // Create a state change event that is NOT a join event
    createLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'SomeOtherState',
    ]);

    $result = AdvanceMatchState::run($matchToken, $matchId);

    expect($result)->toBeNull();
    expect(MtgoMatch::where('mtgo_id', $matchId)->exists())->toBeFalse();
});

it('creates a match in started state when join event exists', function () {
    $matchId = '10001';
    $matchToken = 'token-join';

    createLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawText(),
    ]);

    $result = AdvanceMatchState::run($matchToken, $matchId);

    expect($result)->not->toBeNull();
    expect($result->mtgo_id)->toBe($matchId);
    expect($result->state)->toBe(MatchState::Started);
    expect($result->format)->toBe('Pmodern');
    expect($result->match_type)->toBe('Constructed');
});

it('is idempotent — running twice does not duplicate', function () {
    $matchId = '10002';
    $matchToken = 'token-idempotent';

    createLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawText(),
    ]);

    $first = AdvanceMatchState::run($matchToken, $matchId);
    $second = AdvanceMatchState::run($matchToken, $matchId);

    expect(MtgoMatch::where('mtgo_id', $matchId)->count())->toBe(1);
    expect($first->id)->toBe($second->id);
});

it('does not regress state', function () {
    $match = MtgoMatch::create([
        'mtgo_id' => '10003',
        'token' => 'token-complete',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'started_at' => now()->subHour(),
        'ended_at' => now(),
        'state' => MatchState::Complete,
        'outcome' => 'win',
    ]);

    // Create a join event so the gate check passes
    createLogEvent([
        'match_id' => $match->mtgo_id,
        'match_token' => $match->token,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawText(),
    ]);

    $result = AdvanceMatchState::run($match->token, $match->mtgo_id);

    expect($result->state)->toBe(MatchState::Complete);
});

it('only shows complete matches in complete scope', function () {
    $states = [
        MatchState::Started,
        MatchState::InProgress,
        MatchState::Ended,
        MatchState::Complete,
        MatchState::Complete,
    ];

    foreach ($states as $i => $state) {
        MtgoMatch::create([
            'mtgo_id' => "scope-{$i}",
            'token' => "scope-token-{$i}",
            'format' => 'Pmodern',
            'match_type' => 'Constructed',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'state' => $state,
        ]);
    }

    expect(MtgoMatch::complete()->count())->toBe(2);
    expect(MtgoMatch::incomplete()->count())->toBe(3);
});

it('treats ended as the terminal state — does not advance further', function () {
    $matchId = '10004';
    $matchToken = 'token-ended-terminal';

    $match = MtgoMatch::create([
        'mtgo_id' => $matchId,
        'token' => $matchToken,
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'started_at' => now()->subHour(),
        'ended_at' => now(),
        'state' => MatchState::Ended,
    ]);

    // Create a join event so the gate check passes
    createLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawText(),
    ]);

    $result = AdvanceMatchState::run($matchToken, $matchId);

    expect($result->state)->toBe(MatchState::Ended);
});

it('does not create a match when game state has no local player', function () {
    $matchId = '10010';
    $matchToken = 'token-phantom';

    // Join event (first gate passes)
    createLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawText(),
    ]);

    // Game state with two players, but neither is the local user
    $stateJson = json_encode(['Players' => [
        ['Id' => 1, 'Name' => 'Stranger1'],
        ['Id' => 2, 'Name' => 'Stranger2'],
    ], 'Cards' => []]);

    createLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::GAME_STATE_UPDATE->value,
        'game_id' => 50001,
        'username' => 'LocalPlayer',
        'raw_text' => "12:00:01 [INF] (GameState|Update) Game ID: 50001, Match ID: {$matchId}\n{$stateJson}",
    ]);

    $result = AdvanceMatchState::run($matchToken, $matchId);

    expect($result)->toBeNull();
    expect(MtgoMatch::where('mtgo_id', $matchId)->exists())->toBeFalse();
});

it('does not create a match when game state has only one player', function () {
    $matchId = '10011';
    $matchToken = 'token-solitaire';

    createLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawText(),
    ]);

    $stateJson = json_encode(['Players' => [
        ['Id' => 1, 'Name' => 'LocalPlayer'],
    ], 'Cards' => []]);

    createLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::GAME_STATE_UPDATE->value,
        'game_id' => 50002,
        'username' => 'LocalPlayer',
        'raw_text' => "12:00:01 [INF] (GameState|Update) Game ID: 50002, Match ID: {$matchId}\n{$stateJson}",
    ]);

    $result = AdvanceMatchState::run($matchToken, $matchId);

    expect($result)->toBeNull();
    expect(MtgoMatch::where('mtgo_id', $matchId)->exists())->toBeFalse();
});

it('does not create a match when game state has no parseable players', function () {
    $matchId = '10012';
    $matchToken = 'token-no-players';

    createLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawText(),
    ]);

    // Game state update with empty JSON (no Players array)
    createLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::GAME_STATE_UPDATE->value,
        'game_id' => 50003,
        'username' => 'LocalPlayer',
        'raw_text' => "12:00:01 [INF] (GameState|Update) Game ID: 50003, Match ID: {$matchId}\n{}",
    ]);

    $result = AdvanceMatchState::run($matchToken, $matchId);

    expect($result)->toBeNull();
    expect(MtgoMatch::where('mtgo_id', $matchId)->exists())->toBeFalse();
});

it('reports invalid players when match has no games with both local and opponent', function () {
    $match = MtgoMatch::create([
        'mtgo_id' => '20001',
        'token' => 'token-no-valid-players',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'state' => MatchState::InProgress,
        'started_at' => now(),
    ]);

    // Game with only one player (solitaire)
    $game = $match->games()->create(['mtgo_id' => 30001, 'started_at' => now()]);
    $player = Player::create(['username' => 'SoloPlayer']);
    $game->players()->attach($player, [
        'instance_id' => 1,
        'is_local' => true,
        'on_play' => true,
        'deck_json' => [],
    ]);

    expect($match->hasValidPlayers())->toBeFalse();
});

it('reports valid players when game has local player and opponent', function () {
    $match = MtgoMatch::create([
        'mtgo_id' => '20002',
        'token' => 'token-valid-players',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'state' => MatchState::InProgress,
        'started_at' => now(),
    ]);

    $game = $match->games()->create(['mtgo_id' => 30002, 'started_at' => now()]);
    $local = Player::create(['username' => 'LocalPlayer']);
    $opponent = Player::create(['username' => 'Opponent']);
    $game->players()->attach($local, ['instance_id' => 1, 'is_local' => true, 'on_play' => true, 'deck_json' => []]);
    $game->players()->attach($opponent, ['instance_id' => 2, 'is_local' => false, 'on_play' => false, 'deck_json' => []]);

    expect($match->hasValidPlayers())->toBeTrue();
});

it('creates a match when game state confirms local player with opponent', function () {
    $matchId = '10013';
    $matchToken = 'token-legit';

    createLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawText(),
    ]);

    $stateJson = json_encode(['Players' => [
        ['Id' => 1, 'Name' => 'LocalPlayer'],
        ['Id' => 2, 'Name' => 'Opponent'],
    ], 'Cards' => []]);

    createLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::GAME_STATE_UPDATE->value,
        'game_id' => 50004,
        'username' => 'LocalPlayer',
        'raw_text' => "12:00:01 [INF] (GameState|Update) Game ID: 50004, Match ID: {$matchId}\n{$stateJson}",
    ]);

    $result = AdvanceMatchState::run($matchToken, $matchId);

    expect($result)->not->toBeNull();
    expect($result->mtgo_id)->toBe($matchId);
    expect(MtgoMatch::where('mtgo_id', $matchId)->exists())->toBeTrue();
});
