<?php

use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('has a nullable timeline_source column that defaults to null', function () {
    expect(Schema::hasColumn('games', 'timeline_source'))->toBeTrue();

    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id]);

    expect($game->fresh()->timeline_source)->toBeNull();
});

it('accepts timeline_source through mass assignment', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id, 'timeline_source' => 'sidecar']);

    expect($game->fresh()->timeline_source)->toBe('sidecar');
});
