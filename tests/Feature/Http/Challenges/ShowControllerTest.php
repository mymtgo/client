<?php

use App\Models\Challenge;
use App\Models\ChallengeStanding;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the challenge detail page', function () {
    $challenge = Challenge::factory()->inProgress()->create();

    $response = $this->get("/challenges/{$challenge->id}");

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->component('challenges/Show'));
});

it('includes standings for the latest round', function () {
    $challenge = Challenge::factory()->inProgress()->create();

    ChallengeStanding::create([
        'challenge_id' => $challenge->id,
        'round' => 1,
        'login_id' => 12345,
        'username' => 'TestPlayer',
        'rank' => 1,
        'points' => 3,
        'wins' => 2,
        'losses' => 0,
        'draws' => 0,
    ]);

    $response = $this->get("/challenges/{$challenge->id}");

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('standings', 1)
        );
});
