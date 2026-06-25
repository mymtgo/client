<?php

use App\Enums\MatchOutcome;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Opponent;
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

it('opponentArchetypes returns only is_opponent=true rows', function () {
    $match = MtgoMatch::factory()->create();
    $archetype = Archetype::factory()->create();

    MatchArchetype::create(['mtgo_match_id' => $match->id, 'archetype_id' => $archetype->id, 'is_opponent' => false]);
    MatchArchetype::create(['mtgo_match_id' => $match->id, 'archetype_id' => $archetype->id, 'is_opponent' => true]);
    MatchArchetype::create(['mtgo_match_id' => $match->id, 'archetype_id' => $archetype->id, 'is_opponent' => true]);

    $opponentArchetypes = $match->opponentArchetypes;

    expect($opponentArchetypes)->toHaveCount(2)
        ->and($opponentArchetypes->every(fn ($a) => $a->is_opponent === true))->toBeTrue();
});

it('scopeWithOpponentName resolves opponent_name from opponent_id', function () {
    $opponent = Opponent::factory()->create(['username' => 'TestOpponent']);
    MtgoMatch::factory()->create(['opponent_id' => $opponent->id]);
    MtgoMatch::factory()->create(['opponent_id' => null]);

    $matches = MtgoMatch::withOpponentName()->get();

    $withOpponent = $matches->firstWhere('opponent_id', $opponent->id);
    $withoutOpponent = $matches->firstWhere('opponent_id', null);

    expect($withOpponent->opponent_name)->toBe('TestOpponent')
        ->and($withoutOpponent->opponent_name)->toBeNull();
});

it('scopeForAccount filters by account_id', function () {
    $account1 = Account::factory()->create();
    $account2 = Account::factory()->create();

    $match1 = MtgoMatch::factory()->create(['account_id' => $account1->id]);
    MtgoMatch::factory()->create(['account_id' => $account2->id]);
    MtgoMatch::factory()->create(['account_id' => null]);

    $results = MtgoMatch::forAccount($account1->id)->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($match1->id);
});
