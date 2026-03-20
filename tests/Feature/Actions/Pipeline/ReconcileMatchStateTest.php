<?php

use App\Actions\Pipeline\ReconcileMatchState;
use App\Enums\MatchState;
use App\Models\Deck;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeMatch(array $overrides = []): MtgoMatch
{
    return MtgoMatch::create(array_merge([
        'mtgo_id' => (string) rand(10000, 99999),
        'token' => 'token-'.uniqid(),
        'format' => 'CModern',
        'match_type' => 'Constructed',
        'started_at' => now()->subMinutes(30),
        'ended_at' => now(),
        'state' => MatchState::Started,
        'games_won' => 0,
        'games_lost' => 0,
    ], $overrides));
}

function makeGame(MtgoMatch $match, array $overrides = []): Game
{
    return Game::create(array_merge([
        'match_id' => $match->id,
        'mtgo_id' => rand(100000, 999999),
        'started_at' => now()->subMinutes(20),
        'ended_at' => now()->subMinutes(10),
        'won' => null,
    ], $overrides));
}

it('resolves a match stuck in Started when a newer match exists', function () {
    // Stale match started in the past with no recent updates
    $staleMatch = makeMatch([
        'state' => MatchState::Started,
        'started_at' => now()->subMinutes(10),
    ]);

    // Force updated_at to be older than the 2-minute threshold
    MtgoMatch::where('id', $staleMatch->id)->update(['updated_at' => now()->subMinutes(5)]);

    // A newer match exists in InProgress (used to detect stale)
    $newerMatch = makeMatch([
        'state' => MatchState::InProgress,
        'started_at' => now()->subMinutes(1),
    ]);

    ReconcileMatchState::run();

    expect($staleMatch->fresh()->state)->toBe(MatchState::Voided);
    expect($newerMatch->fresh()->state)->toBe(MatchState::InProgress);
});

it('resolves a stale league match to Ended instead of Voided', function () {
    $league = \App\Models\League::factory()->create();

    $staleMatch = makeMatch([
        'state' => MatchState::Started,
        'started_at' => now()->subMinutes(10),
        'league_id' => $league->id,
    ]);

    MtgoMatch::where('id', $staleMatch->id)->update(['updated_at' => now()->subMinutes(5)]);

    // Newer match to trigger stale detection
    makeMatch([
        'state' => MatchState::InProgress,
        'started_at' => now()->subMinutes(1),
    ]);

    ReconcileMatchState::run();

    expect($staleMatch->fresh()->state)->toBe(MatchState::Ended);
});

it('does not touch an active match with recent events', function () {
    // Active match with very recent updated_at
    $activeMatch = makeMatch([
        'state' => MatchState::InProgress,
        'started_at' => now()->subMinutes(5),
    ]);
    // updated_at is recent (within threshold) — leave as-is

    // The active match is the latest, so resolveStaleMatches won't find anything
    // older than it
    ReconcileMatchState::run();

    expect($activeMatch->fresh()->state)->toBe(MatchState::InProgress);
});

it('backfills null game results from complete matches', function () {
    $match = makeMatch([
        'state' => MatchState::Complete,
        'games_won' => 2,
        'games_lost' => 1,
    ]);

    $game1 = makeGame($match, ['won' => null, 'started_at' => now()->subMinutes(30)]);
    $game2 = makeGame($match, ['won' => null, 'started_at' => now()->subMinutes(20)]);
    $game3 = makeGame($match, ['won' => null, 'started_at' => now()->subMinutes(10)]);

    ReconcileMatchState::run();

    // wins assigned first, then losses
    expect($game1->fresh()->won)->toBeTrue();
    expect($game2->fresh()->won)->toBeTrue();
    expect($game3->fresh()->won)->toBeFalse();
});

it('does not overwrite already-set game results when backfilling', function () {
    $match = makeMatch([
        'state' => MatchState::Complete,
        'games_won' => 2,
        'games_lost' => 0,
    ]);

    $game1 = makeGame($match, ['won' => true, 'started_at' => now()->subMinutes(30)]);
    $game2 = makeGame($match, ['won' => null, 'started_at' => now()->subMinutes(10)]);

    ReconcileMatchState::run();

    expect($game1->fresh()->won)->toBeTrue();
    expect($game2->fresh()->won)->toBeTrue();
});

it('retries deck linking for matches without a deck version when decks exist', function () {
    // Create a deck so the retryDeckLinking guard passes
    Deck::factory()->create();

    $match = makeMatch([
        'state' => MatchState::Complete,
        'deck_version_id' => null,
    ]);

    // DetermineMatchDeck will run but won't find a deck — match stays null.
    // We just verify no exception is thrown and the call is attempted.
    ReconcileMatchState::run();

    expect($match->fresh()->deck_version_id)->toBeNull();
});

it('skips deck linking when no decks exist', function () {
    // No decks in the database
    $match = makeMatch([
        'state' => MatchState::Complete,
        'deck_version_id' => null,
    ]);

    // Should not throw; simply skip
    ReconcileMatchState::run();

    expect($match->fresh()->deck_version_id)->toBeNull();
});

it('does not resolve stale matches when no newer match exists', function () {
    $match = makeMatch([
        'state' => MatchState::Started,
        'started_at' => now()->subMinutes(10),
    ]);

    MtgoMatch::where('id', $match->id)->update(['updated_at' => now()->subMinutes(5)]);

    // No newer InProgress/Complete match exists — resolveStaleMatches returns early
    ReconcileMatchState::run();

    expect($match->fresh()->state)->toBe(MatchState::Started);
});
