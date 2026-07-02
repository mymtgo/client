<?php

use App\Actions\Compile\ProjectMatch;
use App\Actions\Compile\ResolveMatchOutcome;
use App\Actions\Logs\IngestLogInstance;
use App\Data\ProjectedMatch\GameData;
use App\Data\ProjectedMatch\MatchData;
use App\Data\ProjectedMatch\OpponentData;
use App\Enums\MatchOutcome;
use App\Enums\OutcomeSource;
use App\Models\LogEvent;
use App\Models\LogInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function matchDataWith(array $wonFlags, string $state = 'Complete', string $token = 'tok-1'): MatchData
{
    return new MatchData(
        token: $token,
        mtgo_id: 1,
        format: 'CModern',
        match_type: 'League',
        outcome: MatchOutcome::Unknown,
        outcome_source: OutcomeSource::Unknown,
        state: $state,
        started_at: null,
        ended_at: null,
        notes: null,
        opponent: new OpponentData(mtgo_player_id: null, username: 'opp'),
        deck: null,
        league: null,
        tournament: null,
        games: array_map(fn (?bool $won) => new GameData(
            mtgo_id: null, won: $won, started_at: null, ended_at: null,
            turn_count: null, local_on_play: null, local_mulligans: null,
            opp_mulligans: null, local_dice: null, opp_dice: null,
            local_instance: null, opp_instance: null, local_deck: null,
            opponent_deck: null, card_stats: [], timeline: [],
        ), $wonFlags),
        opponent_archetype: null,
    );
}

function concedeStateChange(string $token): void
{
    $instance = LogInstance::factory()->create();
    LogEvent::factory()->create([
        'log_instance_id' => $instance->id,
        'event_type' => 'match_state_changed',
        'match_token' => $token,
        'context' => 'Match State Changed from MatchConcedeReqState to MatchNotJoinedEventUnderwayState',
        'raw_text' => 'Match State Changed from MatchConcedeReqState to MatchNotJoinedEventUnderwayState',
    ]);
}

it('resolves a 2-0 tally to a Win', function () {
    $res = app(ResolveMatchOutcome::class)->run(matchDataWith([true, true]), 'anticloser');

    expect($res['outcome'])->toBe(MatchOutcome::Win);
    expect($res['outcome_source'])->toBe(OutcomeSource::Resolved);
});

it('resolves an 0-2 tally to a Loss', function () {
    $res = app(ResolveMatchOutcome::class)->run(matchDataWith([false, false]), 'anticloser');

    expect($res['outcome'])->toBe(MatchOutcome::Loss);
});

it('resolves a local concession to a Loss even at 1-1', function () {
    concedeStateChange('tok-1');

    $res = app(ResolveMatchOutcome::class)->run(matchDataWith([true, false]), 'anticloser');

    expect($res['outcome'])->toBe(MatchOutcome::Loss);
    expect($res['outcome_source'])->toBe(OutcomeSource::Resolved);
});

it('leans on the partial tally when the server closed the match below threshold', function () {
    $res = app(ResolveMatchOutcome::class)->run(matchDataWith([true], state: 'Complete'), 'anticloser');

    expect($res['outcome'])->toBe(MatchOutcome::Win);
});

it('returns Unknown when no strategy is confident', function () {
    $res = app(ResolveMatchOutcome::class)->run(matchDataWith([null], state: 'InProgress'), 'anticloser');

    expect($res['outcome'])->toBe(MatchOutcome::Unknown);
    expect($res['outcome_source'])->toBe(OutcomeSource::Unknown);
});

it('resolves the real fixture match consistently with its game tally', function () {
    $path = sys_get_temp_dir().'/resolve-outcome-fixture.log';
    copy(base_path('tests/fixtures/mtgo_league_join_drop.log'), $path);
    IngestLogInstance::run($path);

    $token = 'f5e33a1f-c2e7-4678-b30d-309b63f17a40';
    $match = app(ProjectMatch::class)->run($token, 'anticloser');
    $res = app(ResolveMatchOutcome::class)->run($match, 'anticloser');

    $wins = collect($match->games)->filter(fn (GameData $g) => $g->won === true)->count();
    $losses = collect($match->games)->filter(fn (GameData $g) => $g->won === false)->count();

    expect($res['outcome_source'])->toBe(OutcomeSource::Resolved);
    expect($res['outcome'])->toBe($wins > $losses ? MatchOutcome::Win : MatchOutcome::Loss);
});
