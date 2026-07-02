<?php

use App\Actions\Outbox\EnqueueMatch;
use App\Data\ProjectedMatch\MatchData;
use App\Data\ProjectedMatch\OpponentData;
use App\Data\ProjectedMatch\ProjectedMatchData;
use App\Enums\MatchOutcome;
use App\Enums\OutcomeSource;
use App\Models\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function projectedDto(MatchOutcome $outcome = MatchOutcome::Unknown, string $compiledAt = '2026-07-02T10:00:00+00:00'): ProjectedMatchData
{
    return new ProjectedMatchData(
        schema_version: 1,
        client_version: '1.0.0',
        source: 'mtgo',
        match_key: 'tok-1',
        compiled_at: $compiledAt,
        file_version: 1,
        imported: false,
        mtgo_username: 'anticloser',
        mtgo_player_id: 147160,
        match: new MatchData(
            token: 'tok-1',
            mtgo_id: 1,
            format: 'CModern',
            match_type: 'League',
            outcome: $outcome,
            outcome_source: $outcome === MatchOutcome::Unknown ? OutcomeSource::Unknown : OutcomeSource::Resolved,
            state: 'Complete',
            started_at: null,
            ended_at: null,
            notes: null,
            opponent: new OpponentData(mtgo_player_id: null, username: 'opp'),
            deck: null,
            league: null,
            tournament: null,
            games: [],
            opponent_archetype: null,
        ),
    );
}

it('creates a pending row at version 1 on first enqueue', function () {
    $row = app(EnqueueMatch::class)->run(projectedDto());

    expect($row->file_version)->toBe(1);
    expect($row->status)->toBe('pending');
    expect($row->payload['match_key'])->toBe('tok-1');
    expect($row->payload['file_version'])->toBe(1);
});

it('bumps the version and re-pends when the payload changes', function () {
    app(EnqueueMatch::class)->run(projectedDto());
    Outbox::where('match_key', 'tok-1')->update(['status' => 'synced', 'synced_version' => 1]);

    $row = app(EnqueueMatch::class)->run(projectedDto(outcome: MatchOutcome::Win));

    expect(Outbox::where('match_key', 'tok-1')->count())->toBe(1);
    expect($row->file_version)->toBe(2);
    expect($row->status)->toBe('pending');
    expect($row->payload['file_version'])->toBe(2);
    expect($row->payload['match']['outcome'])->toBe('Win');
});

it('is a no-op when only volatile envelope fields differ', function () {
    app(EnqueueMatch::class)->run(projectedDto());
    Outbox::where('match_key', 'tok-1')->update(['status' => 'synced', 'synced_version' => 1]);

    $row = app(EnqueueMatch::class)->run(projectedDto(compiledAt: '2026-07-02T11:30:00+00:00'));

    expect($row->file_version)->toBe(1);
    expect($row->status)->toBe('synced');
});
