<?php

use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('lists the match games in play order so the replay can offer the next one', function () {
    $match = MtgoMatch::factory()->create(['state' => 'complete']);

    $third = Game::factory()->for($match, 'match')->create(['won' => true, 'started_at' => now()->subMinutes(10)]);
    $first = Game::factory()->for($match, 'match')->create(['won' => true, 'started_at' => now()->subMinutes(40)]);
    $second = Game::factory()->for($match, 'match')->create(['won' => false, 'started_at' => now()->subMinutes(25)]);

    Game::factory()->for(MtgoMatch::factory(), 'match')->create(['started_at' => now()->subMinutes(30)]);

    $this->get(route('games.show', $second->id))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('games/Show')
            ->where('matchGames', [
                ['id' => $first->id, 'number' => 1, 'won' => true],
                ['id' => $second->id, 'number' => 2, 'won' => false],
                ['id' => $third->id, 'number' => 3, 'won' => true],
            ]));
});
