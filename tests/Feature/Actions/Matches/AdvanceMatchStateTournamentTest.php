<?php

use App\Actions\Matches\AdvanceMatchState;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stamps tournament_event_id and tournament_round on join when Description carries Tournament:N Round:M', function () {
    $rawJoin = file_get_contents(base_path('tests/Fixtures/log_samples/tournament_match_joined.txt'));
    // Use just the first block
    [$firstBlock] = explode("\n\n", trim($rawJoin));

    $matchToken = '2212f089-c748-42c8-ab3f-61c463a4278f';
    $matchId = 286451927;

    // Simulate the raw log being ingested as a game_management_json event.
    LogEvent::create([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => strlen($firstBlock),
        'timestamp' => '16:00:06',
        'level' => 'INF',
        'category' => 'Game Management',
        'context' => 'Processing Registered Handler for GsMessageMessage in TournamentMatchJoinedEventUnderwayState',
        'raw_text' => $firstBlock,
        'ingested_at' => now(),
        'logged_at' => now(),
        'event_type' => 'game_management_json',
        'match_token' => $matchToken,
        'match_id' => $matchId,
        'username' => 'testuser',
    ]);

    $match = AdvanceMatchState::run($matchToken, $matchId);

    expect($match)->not->toBeNull();
    expect($match->tournament_event_id)->toBe(12839714);
    expect($match->tournament_round)->toBe(1);
    expect($match->token)->toBe($matchToken);
    expect((int) $match->mtgo_id)->toBe($matchId);
});

it('leaves tournament fields null for non-tournament matches', function () {
    $matchToken = 'aaaa0000-0000-0000-0000-000000000000';
    $matchId = 999999999;

    $rawJoin = '12:00:00 [INF] (Game Management|Processing Registered Handler for GsMessageMessage in MatchJoinedEventUnderwayState) Processor: MatchJoinedEventUnderwayState Message: {"MatchToken":"'.$matchToken.'","MatchID":'.$matchId.',"GameID":1} Receiver: Event Token='.$matchToken.'Event Id:'.$matchId.'CurrentStateProcessor=MatchJoinedEventUnderwayStateCurrentState=Joined, EventUnderway, ConnectedDescription=LeagueMatch Id:'.$matchId.'Match Token:'.$matchToken.'PlayFormatCd=CMODERNGameStructureCd= ModernJoinedToGame=TrueAmIHost=FalsePlayerIds=964394,123';

    LogEvent::create([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => strlen($rawJoin),
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Game Management',
        'context' => 'Processing Registered Handler for GsMessageMessage in MatchJoinedEventUnderwayState',
        'raw_text' => $rawJoin,
        'ingested_at' => now(),
        'logged_at' => now(),
        'event_type' => 'game_management_json',
        'match_token' => $matchToken,
        'match_id' => $matchId,
        'username' => 'testuser',
    ]);

    $match = AdvanceMatchState::run($matchToken, $matchId);

    expect($match)->not->toBeNull();
    expect($match->tournament_event_id)->toBeNull();
    expect($match->tournament_round)->toBeNull();
});
