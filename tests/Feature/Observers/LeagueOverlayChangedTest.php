<?php

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Events\LeagueOverlayChanged;
use App\Models\Game;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

it('dispatches when a league match completes', function () {
    $match = MtgoMatch::factory()->ended()->create(['league_id' => League::factory()->create()->id]);

    Event::fake([LeagueOverlayChanged::class]);

    $match->update(['state' => MatchState::Complete, 'outcome' => MatchOutcome::Loss]);

    Event::assertDispatched(LeagueOverlayChanged::class);
});

it('dispatches when a match is assigned to a league', function () {
    $match = MtgoMatch::factory()->inProgress()->create(['league_id' => null]);

    Event::fake([LeagueOverlayChanged::class]);

    $match->update(['league_id' => League::factory()->create()->id]);

    Event::assertDispatched(LeagueOverlayChanged::class);
});

it('dispatches when a match is unlinked from a league', function () {
    $match = MtgoMatch::factory()->create(['league_id' => League::factory()->create()->id]);

    Event::fake([LeagueOverlayChanged::class]);

    $match->update(['league_id' => null]);

    Event::assertDispatched(LeagueOverlayChanged::class);
});

it('does not dispatch for a non-league match', function () {
    $match = MtgoMatch::factory()->ended()->create(['league_id' => null]);

    Event::fake([LeagueOverlayChanged::class]);

    $match->update(['state' => MatchState::Complete, 'outcome' => MatchOutcome::Win]);

    Event::assertNotDispatched(LeagueOverlayChanged::class);
});

it('does not dispatch when a league match changes a field the overlay does not show', function () {
    $match = MtgoMatch::factory()->create(['league_id' => League::factory()->create()->id]);

    Event::fake([LeagueOverlayChanged::class]);

    $match->update(['notes' => 'tight games']);
    $match->touch();

    Event::assertNotDispatched(LeagueOverlayChanged::class);
});

it('dispatches when a league game gets a result', function () {
    $match = MtgoMatch::factory()->inProgress()->create(['league_id' => League::factory()->create()->id]);
    $game = Game::factory()->create(['match_id' => $match->id, 'won' => null, 'ended_at' => null]);

    Event::fake([LeagueOverlayChanged::class]);

    $game->update(['won' => true, 'ended_at' => now()]);

    Event::assertDispatched(LeagueOverlayChanged::class);
});

it('dispatches when a league game is created', function () {
    $match = MtgoMatch::factory()->inProgress()->create(['league_id' => League::factory()->create()->id]);

    Event::fake([LeagueOverlayChanged::class]);

    Game::factory()->create(['match_id' => $match->id, 'won' => null, 'ended_at' => null]);

    Event::assertDispatched(LeagueOverlayChanged::class);
});

it('does not dispatch for a game in a non-league match', function () {
    $match = MtgoMatch::factory()->inProgress()->create(['league_id' => null]);
    $game = Game::factory()->create(['match_id' => $match->id, 'won' => null]);

    Event::fake([LeagueOverlayChanged::class]);

    $game->update(['won' => false]);

    Event::assertNotDispatched(LeagueOverlayChanged::class);
});

it('does not dispatch when a league game changes a field the overlay does not show', function () {
    $match = MtgoMatch::factory()->inProgress()->create(['league_id' => League::factory()->create()->id]);
    $game = Game::factory()->create(['match_id' => $match->id]);

    Event::fake([LeagueOverlayChanged::class]);

    $game->update(['turn_count' => 7]);

    Event::assertNotDispatched(LeagueOverlayChanged::class);
});

it('waits for the transaction to commit so the overlay never reloads stale data', function () {
    expect(new LeagueOverlayChanged)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and((new LeagueOverlayChanged)->broadcastOn())->toBe(['nativephp']);
});
