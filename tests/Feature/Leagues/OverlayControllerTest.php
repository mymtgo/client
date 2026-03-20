<?php

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders overlay with no active league', function () {
    $response = $this->get(route('leagues.overlay'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/Overlay')
        ->where('league', null)
    );
});

it('renders overlay with active league data', function () {
    $league = League::create([
        'token' => 'test-league-token',
        'format' => 'Modern',
        'name' => 'Test League',
        'started_at' => now(),
    ]);

    MtgoMatch::create([
        'mtgo_id' => '100001',
        'token' => 'match-token-1',
        'league_id' => $league->id,
        'format' => 'Modern',
        'match_type' => 'League',
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    MtgoMatch::create([
        'mtgo_id' => '100002',
        'token' => 'match-token-2',
        'league_id' => $league->id,
        'format' => 'Modern',
        'match_type' => 'League',
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Loss,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    $response = $this->get(route('leagues.overlay'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/Overlay')
        ->where('league.wins', 1)
        ->where('league.losses', 1)
        ->where('league.totalMatches', 2)
        ->where('league.format', 'Modern')
        ->where('league.hasActiveMatch', false)
    );
});

it('detects an active match in the league', function () {
    $league = League::create([
        'token' => 'test-league-token-2',
        'format' => 'Modern',
        'name' => 'Active Match League',
        'started_at' => now(),
    ]);

    MtgoMatch::create([
        'mtgo_id' => '200001',
        'token' => 'match-token-active',
        'league_id' => $league->id,
        'format' => 'Modern',
        'match_type' => 'League',
        'state' => MatchState::InProgress,
        'outcome' => MatchOutcome::Unknown,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    $response = $this->get(route('leagues.overlay'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/Overlay')
        ->where('league.hasActiveMatch', true)
        ->where('league.totalMatches', 1)
    );
});

it('includes game results for the active match', function () {
    $league = League::create([
        'token' => 'game-results-league-token',
        'format' => 'Modern',
        'name' => 'Game Results League',
        'started_at' => now(),
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => '400001',
        'token' => 'match-token-games',
        'league_id' => $league->id,
        'format' => 'Modern',
        'match_type' => 'League',
        'state' => MatchState::InProgress,
        'outcome' => MatchOutcome::Unknown,
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    \App\Models\Game::create([
        'match_id' => $match->id,
        'mtgo_id' => '500001',
        'started_at' => now()->subMinutes(10),
        'ended_at' => now()->subMinutes(5),
        'won' => true,
    ]);

    \App\Models\Game::create([
        'match_id' => $match->id,
        'mtgo_id' => '500002',
        'started_at' => now()->subMinutes(4),
        'ended_at' => null,
        'won' => null,
    ]);

    $response = $this->get(route('leagues.overlay'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/Overlay')
        ->where('league.hasActiveMatch', true)
        ->has('league.games', 2)
        ->where('league.games.0.won', true)
        ->where('league.games.0.ended', true)
        ->where('league.games.1.won', null)
        ->where('league.games.1.ended', false)
    );
});

it('excludes completed leagues with 5 matches', function () {
    $league = League::create([
        'token' => 'completed-league-token',
        'format' => 'Modern',
        'name' => 'Completed League',
        'state' => 'complete',
        'started_at' => now(),
    ]);

    foreach (range(1, 5) as $i) {
        MtgoMatch::create([
            'mtgo_id' => "300{$i}",
            'token' => "completed-match-{$i}",
            'league_id' => $league->id,
            'format' => 'Modern',
            'match_type' => 'League',
            'state' => MatchState::Complete,
            'outcome' => MatchOutcome::Win,
            'started_at' => now(),
            'ended_at' => now(),
        ]);
    }

    $response = $this->get(route('leagues.overlay'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/Overlay')
        ->where('league', null)
    );
});
