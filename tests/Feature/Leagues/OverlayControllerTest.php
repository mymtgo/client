<?php

use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\Game;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Support\MtgoFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => AppSettings::setSidecarDirectory(sys_get_temp_dir().'/no-sidecar-'.uniqid()));

it('renders overlay with no active league', function () {
    $response = $this->get(route('leagues.overlay'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/Overlay')
        ->where('state.status', 'idle')->where('state.event', null)
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
        ->where('state.record.wins', 1)
        ->where('state.record.losses', 1)
        ->where('state.progress.played', 2)
        ->where('state.event.format', MtgoFormat::display('Modern'))
        ->where('state.status', 'waiting')
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
        ->where('state.status', 'in_game')
        ->where('state.progress.played', 0)
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
        ->where('state.status', 'in_game')
        ->has('state.match.games', 2)
        ->where('state.match.games.0.won', true)
        ->where('state.match.games.1.won', null)
    );
});

it('falls back to most recent completed league when no active league exists', function () {
    $league = League::create([
        'token' => 'completed-league-token',
        'format' => 'Modern',
        'name' => 'Completed League',
        'state' => 'complete',
        'started_at' => now(),
        'completed_at' => now(),
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
        ->where('state.event.name', 'Completed League')
        ->where('state.record.wins', 5)
        ->where('state.record.losses', 0)
        ->where('state.status', 'trophied')
    );
});

it('hides completed league when completed_at is older than 5 minutes', function () {
    $league = League::create([
        'token' => 'stale-completed-league',
        'format' => 'Modern',
        'name' => 'Stale League',
        'state' => 'complete',
        'started_at' => now()->subHours(2),
        'completed_at' => now()->subMinutes(10),
    ]);

    MtgoMatch::create([
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

    $response = $this->get(route('leagues.overlay'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/Overlay')
        ->where('state.status', 'idle')->where('state.event', null)
    );
});

it('shows completed league when completed_at is within 5 minutes', function () {
    $league = League::create([
        'token' => 'fresh-completed-league',
        'format' => 'Modern',
        'name' => 'Fresh League',
        'state' => 'complete',
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(2),
    ]);

    MtgoMatch::create([
        'mtgo_id' => '700002',
        'token' => 'fresh-match',
        'league_id' => $league->id,
        'format' => 'Modern',
        'match_type' => 'League',
        'state' => MatchState::Complete,
        'outcome' => 'win',
        'started_at' => now()->subMinutes(5),
        'ended_at' => now()->subMinutes(2),
    ]);

    $response = $this->get(route('leagues.overlay'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/Overlay')
        ->where('state.event.name', 'Fresh League')
        ->where('state.status', 'complete')
    );
});

it('shows dropped league when dropped_at is within 5 minutes', function () {
    $league = League::create([
        'token' => 'fresh-dropped-league',
        'format' => 'Modern',
        'name' => 'Fresh Dropped League',
        'state' => 'dropped',
        'started_at' => now()->subHour(),
        'dropped_at' => now()->subMinutes(2),
    ]);

    MtgoMatch::create([
        'mtgo_id' => '700003',
        'token' => 'dropped-match',
        'league_id' => $league->id,
        'format' => 'Modern',
        'match_type' => 'League',
        'state' => MatchState::Complete,
        'outcome' => 'loss',
        'started_at' => now()->subMinutes(10),
        'ended_at' => now()->subMinutes(5),
    ]);

    $response = $this->get(route('leagues.overlay'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/Overlay')
        ->where('state.event.name', 'Fresh Dropped League')
        ->where('state.status', 'dropped')
    );
});

it('hides dropped league when dropped_at is older than 5 minutes', function () {
    $league = League::create([
        'token' => 'stale-dropped-league',
        'format' => 'Modern',
        'name' => 'Stale Dropped League',
        'state' => 'dropped',
        'started_at' => now()->subHours(2),
        'dropped_at' => now()->subMinutes(10),
    ]);

    MtgoMatch::create([
        'mtgo_id' => '700004',
        'token' => 'stale-dropped-match',
        'league_id' => $league->id,
        'format' => 'Modern',
        'match_type' => 'League',
        'state' => MatchState::Complete,
        'outcome' => 'loss',
        'started_at' => now()->subHours(2),
        'ended_at' => now()->subHours(2),
    ]);

    $response = $this->get(route('leagues.overlay'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('leagues/Overlay')
        ->where('state.status', 'idle')->where('state.event', null)
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
        ->where('state.event.name', 'New League')
        ->where('state.status', 'waiting')
    );
});

it('renders the overlay on a transparent page so the window can show the glow', function () {
    $this->get(route('leagues.overlay'))
        ->assertOk()
        ->assertSee('<body class="font-sans antialiased bg-transparent">', false)
        ->assertSee('html { background-color: transparent; }', false);
});

it('keeps the normal page background everywhere else', function () {
    $this->get(route('settings.overlays'))
        ->assertOk()
        ->assertSee('<body class="font-sans antialiased texture-bg">', false);
});
