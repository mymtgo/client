<?php

use App\Actions\Matches\LinkMatchToTournament;
use App\Enums\LogEventType;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function roundInfoRaw(string $tournamentToken, string $matchToken): string
{
    return <<<LOG
15:43:44 [INF] (Game Management|Processing Registered Handler for FlsTournamentRoundInfoMessage in TournamentNotJoinedFiredState) Processor: TournamentNotJoinedFiredState Message: {"Token":"{$tournamentToken}","ID":12840476,"Round":{"Number":1,"Matches":[{"EventToken":"{$matchToken}","ParentToken":"{$tournamentToken}","MatchCreateInfo":{"MatchToken":"{$matchToken}","MatchID":286470836}}],"ByeList":[],"Results":[]}} Receiver: WotC.MtGO.Client.Model.Play.TournamentEvent.Tournament
LOG;
}

it('populates tournament_token when a round_info log event mentions the match', function () {
    $matchToken = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    $tournamentToken = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    LogEvent::factory()->create([
        'event_type' => LogEventType::TOURNAMENT_ROUND_INFO->value,
        'raw_text' => roundInfoRaw($tournamentToken, $matchToken),
        'tournament_token' => $tournamentToken,
    ]);

    $match = MtgoMatch::factory()->create([
        'token' => $matchToken,
        'tournament_event_id' => 12840476,
        'tournament_round' => 1,
        'tournament_token' => null,
    ]);

    LinkMatchToTournament::run($match);

    expect($match->fresh()->tournament_token)->toBe($tournamentToken);
});

it('is a no-op when the match is already linked', function () {
    $match = MtgoMatch::factory()->create([
        'tournament_event_id' => 123,
        'tournament_token' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
    ]);

    LinkMatchToTournament::run($match);

    expect($match->fresh()->tournament_token)->toBe('cccccccc-cccc-cccc-cccc-cccccccccccc');
});

it('skips matches with no tournament_event_id (non-tournament matches)', function () {
    $match = MtgoMatch::factory()->create([
        'tournament_event_id' => null,
        'tournament_token' => null,
    ]);

    LogEvent::factory()->create([
        'event_type' => LogEventType::TOURNAMENT_ROUND_INFO->value,
        'raw_text' => roundInfoRaw('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', $match->token),
    ]);

    LinkMatchToTournament::run($match);

    expect($match->fresh()->tournament_token)->toBeNull();
});

it('does nothing when no round_info mentions this match', function () {
    $match = MtgoMatch::factory()->create([
        'tournament_event_id' => 123,
        'tournament_token' => null,
    ]);

    LinkMatchToTournament::run($match);

    expect($match->fresh()->tournament_token)->toBeNull();
});

it('survives malformed round_info JSON (missing outer closing brace)', function () {
    $matchToken = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    $tournamentToken = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    // Same payload shape the real MTGO ships, with the outer `}` missing.
    $raw = '15:43:44 [INF] (Game Management|Processing Registered Handler for FlsTournamentRoundInfoMessage in TournamentNotJoinedFiredState) Processor: TournamentNotJoinedFiredState Message: {"Token":"'.$tournamentToken.'","ID":12840476,"Round":{"Number":1,"Matches":[{"EventToken":"'.$matchToken.'","ParentToken":"'.$tournamentToken.'","MatchCreateInfo":{"MatchToken":"'.$matchToken.'","MatchID":286470836}}],"ByeList":[],"Results":[]} Receiver: WotC.MtGO.Client.Model.Play.TournamentEvent.Tournament';

    LogEvent::factory()->create([
        'event_type' => LogEventType::TOURNAMENT_ROUND_INFO->value,
        'raw_text' => $raw,
    ]);

    $match = MtgoMatch::factory()->create([
        'token' => $matchToken,
        'tournament_event_id' => 12840476,
        'tournament_token' => null,
    ]);

    LinkMatchToTournament::run($match);

    expect($match->fresh()->tournament_token)->toBe($tournamentToken);
});
