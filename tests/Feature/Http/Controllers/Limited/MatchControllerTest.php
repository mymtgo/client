<?php

use App\Enums\LeagueKind;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders a league match inside the limited event layout', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()->subHour()]);
    $match = MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Win]);

    $this->get(route('limited.match', ['league' => $league->id, 'match' => $match->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('limited/Match')
            ->where('currentPage', 'matches')
            ->where('event.id', $league->id)
            ->where('match.id', $match->id)
            ->has('games')
            ->has('archetypes')
            ->where('imported', false)
        );
});

it('returns 404 for a match that belongs to another league', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'started_at' => now()]);
    $other = League::factory()->create(['kind' => LeagueKind::Draft, 'started_at' => now()]);
    $match = MtgoMatch::factory()->create(['league_id' => $other->id, 'state' => MatchState::Complete]);

    $this->get(route('limited.match', ['league' => $league->id, 'match' => $match->id]))->assertNotFound();
});

it('returns 404 for a constructed league', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Constructed]);
    $match = MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete]);

    $this->get(route('limited.match', ['league' => $league->id, 'match' => $match->id]))->assertNotFound();
});
