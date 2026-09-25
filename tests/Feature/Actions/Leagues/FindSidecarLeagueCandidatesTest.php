<?php

use App\Actions\Leagues\FindSidecarLeagueCandidates;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Sidecar\LeagueSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\SidecarSnapshotFactory;

uses(RefreshDatabase::class);

function candidateSnapshot(array $overrides = []): LeagueSnapshot
{
    return LeagueSnapshot::fromArray(SidecarSnapshotFactory::league($overrides));
}

it('finds by event id, then by token for leagues without one', function () {
    $byEvent = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    $byToken = League::factory()->create(['event_id' => null, 'token' => 'league-token-123']);

    $found = FindSidecarLeagueCandidates::run(candidateSnapshot(), [LeagueState::Active]);

    expect($found->pluck('id')->all())->toBe([$byEvent->id, $byToken->id]);
});

it('never returns another league, a manual league, a limited league or a filtered state', function () {
    League::factory()->create(['event_id' => 20000, 'token' => 'pauper-token']);
    League::factory()->manual()->create(['event_id' => 10983]);
    League::factory()->create(['event_id' => 10983, 'kind' => LeagueKind::Draft]);
    League::factory()->create(['event_id' => 10983, 'state' => LeagueState::Dropped]);
    League::factory()->create(['event_id' => null, 'token' => 'league-token-123', 'state' => LeagueState::Complete]);

    expect(FindSidecarLeagueCandidates::run(candidateSnapshot(), [LeagueState::Active, LeagueState::Partial]))->toBeEmpty();
});

it('finds nothing without an event id or token', function () {
    League::factory()->create(['event_id' => null]);

    expect(FindSidecarLeagueCandidates::run(candidateSnapshot(['event_id' => null, 'token' => null]), [LeagueState::Active]))->toBeEmpty();
});

it('loads each candidate league matches', function () {
    $league = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    MtgoMatch::factory()->create(['league_id' => $league->id, 'mtgo_id' => '100']);

    $found = FindSidecarLeagueCandidates::run(candidateSnapshot(), [LeagueState::Active]);

    expect($found->first()->relationLoaded('matches'))->toBeTrue()
        ->and($found->first()->matches->pluck('mtgo_id')->all())->toBe(['100']);
});
