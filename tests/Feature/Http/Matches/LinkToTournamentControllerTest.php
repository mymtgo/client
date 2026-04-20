<?php

use App\Enums\MatchState;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links a match to a tournament', function () {
    $tournament = Tournament::factory()->create(['participated' => true, 'max_rounds' => 8]);
    $match = MtgoMatch::factory()->create(['state' => MatchState::Complete]);

    $response = $this->post(route('matches.link-tournament', ['match' => $match->id]), [
        'tournament_id' => $tournament->id,
        'round' => 3,
    ]);

    $response->assertRedirect();

    $match->refresh();
    expect($match->tournament_id)->toBe($tournament->id);
    expect($match->tournament_round)->toBe(3);
});

it('rejects an unparticipated tournament', function () {
    $tournament = Tournament::factory()->create(['participated' => false]);
    $match = MtgoMatch::factory()->create(['state' => MatchState::Complete]);

    $response = $this->post(route('matches.link-tournament', ['match' => $match->id]), [
        'tournament_id' => $tournament->id,
        'round' => 1,
    ]);

    $response->assertSessionHasErrors('tournament_id');
});

it('rejects a round greater than max_rounds', function () {
    $tournament = Tournament::factory()->create(['participated' => true, 'max_rounds' => 5]);
    $match = MtgoMatch::factory()->create(['state' => MatchState::Complete]);

    $response = $this->post(route('matches.link-tournament', ['match' => $match->id]), [
        'tournament_id' => $tournament->id,
        'round' => 6,
    ]);

    $response->assertSessionHasErrors('round');
});

it('allows any positive round when tournament has no max_rounds', function () {
    $tournament = Tournament::factory()->create(['participated' => true, 'max_rounds' => null]);
    $match = MtgoMatch::factory()->create(['state' => MatchState::Complete]);

    $response = $this->post(route('matches.link-tournament', ['match' => $match->id]), [
        'tournament_id' => $tournament->id,
        'round' => 42,
    ]);

    $response->assertRedirect();
    expect($match->fresh()->tournament_round)->toBe(42);
});

it('rejects round of zero or negative', function () {
    $tournament = Tournament::factory()->create(['participated' => true]);
    $match = MtgoMatch::factory()->create(['state' => MatchState::Complete]);

    $response = $this->post(route('matches.link-tournament', ['match' => $match->id]), [
        'tournament_id' => $tournament->id,
        'round' => 0,
    ]);

    $response->assertSessionHasErrors('round');
});

it('unlinks a match', function () {
    $tournament = Tournament::factory()->create(['participated' => true]);
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'tournament_id' => $tournament->id,
        'tournament_round' => 4,
        'participant_login_ids' => [1, 2],
    ]);

    $response = $this->delete(route('matches.unlink-tournament', ['match' => $match->id]));

    $response->assertRedirect();
    $match->refresh();
    expect($match->tournament_id)->toBeNull();
    expect($match->tournament_round)->toBeNull();
    expect($match->participant_login_ids)->toBeNull();
});

it('returns 404 for unknown match on link', function () {
    $tournament = Tournament::factory()->create(['participated' => true]);

    $response = $this->post(route('matches.link-tournament', ['match' => 99999]), [
        'tournament_id' => $tournament->id,
        'round' => 1,
    ]);

    $response->assertNotFound();
});
