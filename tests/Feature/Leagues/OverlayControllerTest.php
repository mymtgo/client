<?php

use App\Enums\MatchState;
use App\Models\Game;
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
        'outcome' => 'win',
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
        'outcome' => 'loss',
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
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    Game::create([
        'match_id' => $match->id,
        'mtgo_id' => '500001',
        'started_at' => now()->subMinutes(10),
        'ended_at' => now()->subMinutes(5),
        'won' => true,
    ]);

    Game::create([
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

it('falls back to most recent completed league when no active league exists', function () {
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
            'outcome' => 'win',
            'started_at' => now(),
            'ended_at' => now(),
        ]);
    }

    $response = $this->get(route('leagues.overlay'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/Overlay')
        ->where('league.id', $league->id)
        ->where('league.wins', 5)
        ->where('league.losses', 0)
        ->where('league.hasActiveMatch', false)
    );
});

it('hides completed league fallback when its last match is older than 5 minutes', function () {
    $league = League::create([
        'token' => 'stale-completed-league',
        'format' => 'Modern',
        'name' => 'Stale League',
        'state' => 'complete',
        'started_at' => now()->subHours(2),
    ]);

    $match = MtgoMatch::create([
        'mtgo_id' => '700001',
        'token' => 'stale-match',
        'league_id' => $league->id,
        'format' => 'Modern',
        'match_type' => 'League',
        'state' => MatchState::Complete,
        'outcome' => 'win',
        'started_at' => now()->subHours(2),
        'ended_at' => now()->subHours(2),
    ]);

    $match->forceFill(['created_at' => now()->subMinutes(10)])->save();

    $response = $this->get(route('leagues.overlay'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/Overlay')
        ->where('league', null)
    );
});

it('shows completed league fallback when its last match is within 5 minutes', function () {
    $league = League::create([
        'token' => 'fresh-completed-league',
        'format' => 'Modern',
        'name' => 'Fresh League',
        'state' => 'complete',
        'started_at' => now()->subHour(),
    ]);

    MtgoMatch::create([
        'mtgo_id' => '700002',
        'token' => 'fresh-match',
        'league_id' => $league->id,
        'format' => 'Modern',
        'match_type' => 'League',
        'state' => MatchState::Complete,
        'outcome' => 'win',
        'started_at' => now()->subMinutes(2),
        'ended_at' => now()->subMinutes(1),
    ]);

    $response = $this->get(route('leagues.overlay'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/Overlay')
        ->where('league.id', $league->id)
    );
});

it('prefers active league over completed when both exist', function () {
    $completed = League::create([
        'token' => 'old-completed-league',
        'format' => 'Modern',
        'name' => 'Old League',
        'state' => 'complete',
        'started_at' => now()->subDay(),
    ]);

    MtgoMatch::create([
        'mtgo_id' => '600001',
        'token' => 'old-match',
        'league_id' => $completed->id,
        'format' => 'Modern',
        'match_type' => 'League',
        'state' => MatchState::Complete,
        'outcome' => 'win',
        'started_at' => now()->subDay(),
        'ended_at' => now()->subDay(),
    ]);

    $active = League::create([
        'token' => 'new-active-league',
        'format' => 'Modern',
        'name' => 'New League',
        'started_at' => now(),
    ]);

    MtgoMatch::create([
        'mtgo_id' => '600002',
        'token' => 'new-match',
        'league_id' => $active->id,
        'format' => 'Modern',
        'match_type' => 'League',
        'state' => MatchState::Complete,
        'outcome' => 'loss',
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    $response = $this->get(route('leagues.overlay'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/Overlay')
        ->where('league.id', $active->id)
    );
});
