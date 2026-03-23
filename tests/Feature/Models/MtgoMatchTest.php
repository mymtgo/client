<?php

use App\Enums\MatchOutcome;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('scopes to won matches', function () {
    MtgoMatch::factory()->create(['outcome' => MatchOutcome::Win]);
    MtgoMatch::factory()->create(['outcome' => MatchOutcome::Loss]);
    MtgoMatch::factory()->create(['outcome' => MatchOutcome::Draw]);

    $won = MtgoMatch::won()->get();

    expect($won)->toHaveCount(1)
        ->and($won->first()->outcome)->toBe(MatchOutcome::Win);
});

it('scopes to lost matches', function () {
    MtgoMatch::factory()->create(['outcome' => MatchOutcome::Win]);
    MtgoMatch::factory()->create(['outcome' => MatchOutcome::Loss]);
    MtgoMatch::factory()->create(['outcome' => MatchOutcome::Draw]);

    $lost = MtgoMatch::lost()->get();

    expect($lost)->toHaveCount(1)
        ->and($lost->first()->outcome)->toBe(MatchOutcome::Loss);
});

it('eager loads game counts with withGameCounts scope', function () {
    $match = MtgoMatch::factory()->create();
    Game::create(['match_id' => $match->id, 'mtgo_id' => 'g1', 'won' => true, 'started_at' => now()]);
    Game::create(['match_id' => $match->id, 'mtgo_id' => 'g2', 'won' => true, 'started_at' => now()]);
    Game::create(['match_id' => $match->id, 'mtgo_id' => 'g3', 'won' => false, 'started_at' => now()]);

    $loaded = MtgoMatch::withGameCounts()->find($match->id);

    expect($loaded->games_won_count)->toBe(2)
        ->and($loaded->games_lost_count)->toBe(1);
});

it('returns true for isWin when outcome is win', function () {
    $match = MtgoMatch::factory()->create(['outcome' => MatchOutcome::Win]);

    expect($match->isWin())->toBeTrue()
        ->and($match->isLoss())->toBeFalse();
});

it('returns true for isLoss when outcome is loss', function () {
    $match = MtgoMatch::factory()->create(['outcome' => MatchOutcome::Loss]);

    expect($match->isLoss())->toBeTrue()
        ->and($match->isWin())->toBeFalse();
});

it('counts games won from relationship', function () {
    $match = MtgoMatch::factory()->create();
    Game::create(['match_id' => $match->id, 'mtgo_id' => 'g1', 'won' => true, 'started_at' => now()]);
    Game::create(['match_id' => $match->id, 'mtgo_id' => 'g2', 'won' => true, 'started_at' => now()]);
    Game::create(['match_id' => $match->id, 'mtgo_id' => 'g3', 'won' => false, 'started_at' => now()]);

    expect($match->gamesWon())->toBe(2);
});

it('counts games lost from relationship', function () {
    $match = MtgoMatch::factory()->create();
    Game::create(['match_id' => $match->id, 'mtgo_id' => 'g1', 'won' => true, 'started_at' => now()]);
    Game::create(['match_id' => $match->id, 'mtgo_id' => 'g2', 'won' => false, 'started_at' => now()]);
    Game::create(['match_id' => $match->id, 'mtgo_id' => 'g3', 'won' => false, 'started_at' => now()]);

    expect($match->gamesLost())->toBe(2);
});

it('uses eager-loaded counts when available for gamesWon and gamesLost', function () {
    $match = MtgoMatch::factory()->create();
    Game::create(['match_id' => $match->id, 'mtgo_id' => 'g1', 'won' => true, 'started_at' => now()]);
    Game::create(['match_id' => $match->id, 'mtgo_id' => 'g2', 'won' => false, 'started_at' => now()]);

    $loaded = MtgoMatch::withGameCounts()->find($match->id);

    // Should use eager-loaded counts (no additional queries)
    expect($loaded->games_won_count)->toBe(1)
        ->and($loaded->games_lost_count)->toBe(1)
        ->and($loaded->gamesWon())->toBe(1)
        ->and($loaded->gamesLost())->toBe(1);
});

it('returns game record string', function () {
    $match = MtgoMatch::factory()->create();
    Game::create(['match_id' => $match->id, 'mtgo_id' => 'g1', 'won' => true, 'started_at' => now()]);
    Game::create(['match_id' => $match->id, 'mtgo_id' => 'g2', 'won' => true, 'started_at' => now()]);
    Game::create(['match_id' => $match->id, 'mtgo_id' => 'g3', 'won' => false, 'started_at' => now()]);

    expect($match->gameRecord())->toBe('2-1');
});
