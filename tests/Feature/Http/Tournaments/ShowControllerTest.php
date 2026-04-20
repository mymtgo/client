<?php

use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Models\Tournament;
use App\Models\TournamentStanding;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the tournament detail page', function () {
    $tournament = Tournament::factory()->inProgress()->create();

    $response = $this->get("/tournaments/{$tournament->id}");

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->component('tournaments/Show'));
});

it('includes standings for the latest round', function () {
    $tournament = Tournament::factory()->inProgress()->create();

    TournamentStanding::create([
        'tournament_id' => $tournament->id,
        'round' => 1,
        'login_id' => 12345,
        'username' => 'TestPlayer',
        'rank' => 1,
        'points' => 3,
        'wins' => 2,
        'losses' => 0,
        'draws' => 0,
    ]);

    $response = $this->get("/tournaments/{$tournament->id}");

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('standingsByRound')
            ->has('rounds', 1)
        );
});

it('includes your_rounds for a participated tournament', function () {
    $tournament = Tournament::factory()->create([
        'participated' => true,
    ]);

    $localPlayer = Player::create(['username' => 'LocalUser', 'login_id' => 964394]);
    $opponent = Player::create(['username' => 'Opponent', 'login_id' => 2714690]);

    TournamentStanding::create([
        'tournament_id' => $tournament->id,
        'round' => 1,
        'login_id' => 2714690,
        'username' => 'Opponent',
        'rank' => 42,
        'points' => 0,
        'wins' => 0,
        'losses' => 1,
        'draws' => 0,
    ]);

    $match = MtgoMatch::factory()->create([
        'tournament_id' => $tournament->id,
        'tournament_round' => 1,
        'games_won' => 2,
        'games_lost' => 1,
    ]);

    $game = Game::factory()->for($match, 'match')->create();
    $game->players()->attach($localPlayer->id, ['is_local' => true, 'instance_id' => 1, 'on_play' => true]);
    $game->players()->attach($opponent->id, ['is_local' => false, 'instance_id' => 2, 'on_play' => false]);

    $response = $this->get("/tournaments/{$tournament->id}");

    $response->assertInertia(fn ($page) => $page
        ->component('tournaments/Show')
        ->has('yourRounds', 1)
        ->where('yourRounds.0.round', 1)
        ->where('yourRounds.0.opponent_username', 'Opponent')
        ->where('yourRounds.0.opponent_rank', 42)
        ->where('yourRounds.0.result', '2-1-0')
    );
});

it('omits your_rounds for a spectated tournament', function () {
    $tournament = Tournament::factory()->create(['participated' => false]);

    $response = $this->get("/tournaments/{$tournament->id}");

    $response->assertInertia(fn ($page) => $page->where('yourRounds', []));
});
