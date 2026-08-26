<?php

use App\Enums\LeagueKind;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 404 for a constructed league', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Constructed]);
    $this->get(route('limited.matches', ['league' => $league->id]))->assertNotFound();
});

it('renders league matches with kpis', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()->subHour()]);
    MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Win, 'started_at' => now()->subMinutes(50), 'ended_at' => now()->subMinutes(40)]);
    MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Loss, 'started_at' => now()->subMinutes(30), 'ended_at' => now()->subMinutes(18)]);
    MtgoMatch::factory()->create(['state' => MatchState::Complete, 'outcome' => MatchOutcome::Win]);

    $this->get(route('limited.matches', ['league' => $league->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('limited/Matches')
            ->where('currentPage', 'matches')
            ->has('matches', 2)
            ->where('kpis.wins', 1)
            ->where('kpis.losses', 1)
            ->where('kpis.totalMinutes', 32)
        );
});

it('renders an empty matches page when the league has no complete matches', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Sealed, 'started_at' => now()]);

    $this->get(route('limited.matches', ['league' => $league->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('limited/Matches')
            ->has('matches', 0)
            ->where('kpis.wins', 0)
            ->where('kpis.losses', 0)
            ->where('kpis.totalMinutes', null)
        );
});
