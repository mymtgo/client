<?php

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
