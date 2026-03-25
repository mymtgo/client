<?php

use App\Actions\Matches\ResolveMatchFromHistory;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('completes an Ended match from match history', function () {
    $match = MtgoMatch::factory()->ended()->create(['mtgo_id' => '12345']);
    Game::factory()->create(['match_id' => $match->id, 'won' => null]);
    Game::factory()->create(['match_id' => $match->id, 'won' => null]);

    $resolved = ResolveMatchFromHistory::run($match, ['wins' => 2, 'losses' => 0]);

    expect($resolved)->toBeTrue();
    expect($match->fresh()->state)->toBe(MatchState::Complete);
    expect($match->fresh()->outcome)->toBe(MatchOutcome::Win);
});

it('backfills Game.won based on W-L in game order', function () {
    $match = MtgoMatch::factory()->ended()->create(['mtgo_id' => '12345']);
    $g1 = Game::factory()->create(['match_id' => $match->id, 'won' => null, 'started_at' => now()->subMinutes(10)]);
    $g2 = Game::factory()->create(['match_id' => $match->id, 'won' => null, 'started_at' => now()->subMinutes(5)]);
    $g3 = Game::factory()->create(['match_id' => $match->id, 'won' => null, 'started_at' => now()]);

    ResolveMatchFromHistory::run($match, ['wins' => 2, 'losses' => 1]);

    $games = $match->games()->orderBy('started_at')->get();
    expect($games[0]->fresh()->won)->toBeTrue();
    expect($games[1]->fresh()->won)->toBeTrue();
    expect($games[2]->fresh()->won)->toBeFalse();
});

it('does not create missing Game records', function () {
    $match = MtgoMatch::factory()->ended()->create(['mtgo_id' => '12345']);

    ResolveMatchFromHistory::run($match, ['wins' => 2, 'losses' => 1]);

    expect($match->fresh()->state)->toBe(MatchState::Complete);
    expect(Game::where('match_id', $match->id)->count())->toBe(0);
});

it('does not overwrite Game.won that is already set', function () {
    $match = MtgoMatch::factory()->ended()->create(['mtgo_id' => '12345']);
    $g1 = Game::factory()->create(['match_id' => $match->id, 'won' => false, 'started_at' => now()->subMinutes(5)]);
    $g2 = Game::factory()->create(['match_id' => $match->id, 'won' => null, 'started_at' => now()]);

    ResolveMatchFromHistory::run($match, ['wins' => 1, 'losses' => 1]);

    expect($g1->fresh()->won)->toBeFalse();
    expect($g2->fresh()->won)->not->toBeNull();
});

it('handles Started matches with no games', function () {
    $match = MtgoMatch::factory()->started()->create(['mtgo_id' => '12345']);

    ResolveMatchFromHistory::run($match, ['wins' => 2, 'losses' => 0]);

    expect($match->fresh()->state)->toBe(MatchState::Complete);
    expect($match->fresh()->outcome)->toBe(MatchOutcome::Win);
});
