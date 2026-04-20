<?php

use App\Actions\Matches\AdvanceMatchState;
use App\Enums\LogEventType;
use App\Models\LogEvent;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Build raw_text for a tournament join event that ExtractKeyValueBlock can parse.
 * The parser requires "Receiver:" before the key=value lines.
 */
function tournamentJoinRawText(): string
{
    return implode("\n", [
        '12:00:00 [INF] (Match|TournamentMatchJoinedEventUnderwayState)',
        'Receiver:',
        'Event Token = bd477447-c54a-4009-b82d-394a9611b001',
        'Event Id = 286414549',
        'CurrentStateProcessor = TournamentMatchJoinedEventUnderwayState',
        'CurrentState = Joined, EventUnderway, Connected',
        'Description = Tournament:12839688 Round:1',
        'Match Id = 286414549',
        'Match Token = bd477447-c54a-4009-b82d-394a9611b001',
        'PlayFormatCd = CMODERN',
        'GameStructureCd = Modern',
        'JoinedToGame = True',
        'AmIHost = False',
        'PlayerIds = 964394,2714690',
    ]);
}

/**
 * Build raw_text for a regular match join event (no Tournament variant).
 */
function regularJoinRawText(): string
{
    return implode("\n", [
        '12:00:00 [INF] (Match|MatchJoinedEventUnderwayState)',
        'Receiver:',
        'Match Token = 11111111-1111-1111-1111-111111111111',
        'CurrentStateProcessor = MatchJoinedEventUnderwayState',
        'Description = League Match',
        'PlayFormatCd = CMODERN',
        'GameStructureCd = Modern',
    ]);
}

/**
 * Build a GAME_STATE_UPDATE raw_text that ExtractJson can parse with two players.
 */
function gameStateRawText(string $matchId, int $gameId, string $localPlayer, string $opponent): string
{
    $stateJson = json_encode(['Players' => [
        ['Id' => 1, 'Name' => $localPlayer],
        ['Id' => 2, 'Name' => $opponent],
    ], 'Cards' => []]);

    return "12:00:01 [INF] (GameState|Update) Game ID: {$gameId}, Match ID: {$matchId}\n{$stateJson}";
}

// ─────────────────────────────────────────────────────────────────────────────

it('links a tournament match to a tournament when the join event is a tournament variant', function () {
    $matchToken = 'bd477447-c54a-4009-b82d-394a9611b001';
    $matchId = '286414549';

    LogEvent::create([
        'file_path' => '/tmp/test.log',
        'byte_offset_start' => 1000,
        'byte_offset_end' => 2000,
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Match',
        'context' => 'Match State Changed for ... TournamentMatchJoinedEventUnderwayState',
        'raw_text' => tournamentJoinRawText(),
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'logged_at' => now(),
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'ingested_at' => now(),
    ]);

    LogEvent::create([
        'file_path' => '/tmp/test.log',
        'byte_offset_start' => 2001,
        'byte_offset_end' => 3000,
        'timestamp' => '12:00:01',
        'level' => 'INF',
        'category' => 'GameState',
        'context' => '',
        'raw_text' => gameStateRawText($matchId, 50010, 'LocalUser', 'Opponent'),
        'event_type' => LogEventType::GAME_STATE_UPDATE->value,
        'logged_at' => now(),
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'username' => 'LocalUser',
        'game_id' => 50010,
        'ingested_at' => now(),
    ]);

    $match = AdvanceMatchState::run($matchToken, $matchId);

    expect($match)->not->toBeNull();
    expect($match->tournament_id)->not->toBeNull();
    expect($match->tournament_round)->toBe(1);

    $tournament = Tournament::where('event_id', 12839688)->firstOrFail();
    expect($tournament->participated)->toBeTrue();
});

it('does not link regular match-joined events to a tournament', function () {
    $matchToken = '11111111-1111-1111-1111-111111111111';
    $matchId = '200001';

    LogEvent::create([
        'file_path' => '/tmp/test.log',
        'byte_offset_start' => 1000,
        'byte_offset_end' => 2000,
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Match',
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => regularJoinRawText(),
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'logged_at' => now(),
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'ingested_at' => now(),
    ]);

    LogEvent::create([
        'file_path' => '/tmp/test.log',
        'byte_offset_start' => 2001,
        'byte_offset_end' => 3000,
        'timestamp' => '12:00:01',
        'level' => 'INF',
        'category' => 'GameState',
        'context' => '',
        'raw_text' => gameStateRawText($matchId, 50020, 'LocalUser', 'Opponent'),
        'event_type' => LogEventType::GAME_STATE_UPDATE->value,
        'logged_at' => now(),
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'username' => 'LocalUser',
        'game_id' => 50020,
        'ingested_at' => now(),
    ]);

    $match = AdvanceMatchState::run($matchToken, $matchId);

    expect($match)->not->toBeNull();
    expect($match->tournament_id)->toBeNull();
    expect(Tournament::count())->toBe(0);
});
