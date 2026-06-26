<?php

use App\Actions\Upgrade\BackfillMatchArchetypeSides;
use App\Models\Archetype;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Insert a legacy `game_player` row for an archetype sides test.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertArchetypeGamePlayer(int $gameId, int $playerId, array $overrides = []): void
{
    DB::table('game_player')->insert(array_merge([
        'game_id' => $gameId,
        'player_id' => $playerId,
        'instance_id' => 100,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
        'dice_roll' => null,
        'mulligan_count' => null,
        'deck_json' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * Build a legacy fixture with two match_archetype rows — one linked to the
 * local player and one to the opponent player — both currently with
 * is_opponent=false (legacy default).
 *
 * Returns [$match, $localArchetype, $opponentArchetype, $localPlayerId, $opponentPlayerId].
 *
 * @return array{0: MtgoMatch, 1: MatchArchetype, 2: MatchArchetype, 3: int, 4: int}
 */
function buildArchetypeFixture(): array
{
    $match = MtgoMatch::factory()->create();

    $game = Game::factory()->create(['match_id' => $match->id]);

    $localPlayerId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentPlayerId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);

    insertArchetypeGamePlayer($game->id, $localPlayerId, ['is_local' => true]);
    insertArchetypeGamePlayer($game->id, $opponentPlayerId, ['is_local' => false]);

    $archetype1 = Archetype::factory()->create();
    $archetype2 = Archetype::factory()->create();

    // Legacy rows: player_id set, is_opponent defaults to false.
    $localArchetype = MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $archetype1->id,
        'player_id' => $localPlayerId,
        'confidence' => 0.9,
        'is_opponent' => false,
    ]);

    $opponentArchetype = MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $archetype2->id,
        'player_id' => $opponentPlayerId,
        'confidence' => 0.8,
        'is_opponent' => false,
    ]);

    return [$match, $localArchetype, $opponentArchetype, $localPlayerId, $opponentPlayerId];
}

// ---------------------------------------------------------------------------
// Core correctness
// ---------------------------------------------------------------------------

it('sets is_opponent=true on the opponent archetype row', function () {
    [$match, $localArchetype, $opponentArchetype] = buildArchetypeFixture();

    BackfillMatchArchetypeSides::run($match);

    expect($opponentArchetype->fresh()->is_opponent)->toBeTrue();
});

it('leaves is_opponent=false on the local archetype row', function () {
    [$match, $localArchetype] = buildArchetypeFixture();

    BackfillMatchArchetypeSides::run($match);

    expect($localArchetype->fresh()->is_opponent)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

it('is idempotent — running twice produces the same flags', function () {
    [$match, $localArchetype, $opponentArchetype] = buildArchetypeFixture();

    BackfillMatchArchetypeSides::run($match);
    BackfillMatchArchetypeSides::run($match);

    expect($opponentArchetype->fresh()->is_opponent)->toBeTrue();
    expect($localArchetype->fresh()->is_opponent)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Post-Phase-2 rows (player_id null, is_opponent already correct) are untouched
// ---------------------------------------------------------------------------

it('leaves a post-Phase-2 row with null player_id and is_opponent=true untouched', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id]);

    $localPlayerId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentPlayerId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);

    insertArchetypeGamePlayer($game->id, $localPlayerId, ['is_local' => true]);
    insertArchetypeGamePlayer($game->id, $opponentPlayerId, ['is_local' => false]);

    $archetype = Archetype::factory()->create();

    // Post-Phase-2 row: no player_id, already correctly flagged as opponent.
    $newRow = MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $archetype->id,
        'player_id' => null,
        'confidence' => 0.95,
        'is_opponent' => true,
    ]);

    BackfillMatchArchetypeSides::run($match);

    // Still true — not flipped to false.
    expect($newRow->fresh()->is_opponent)->toBeTrue();
});

it('skips rows with null player_id even if is_opponent is false (already correct default)', function () {
    $match = MtgoMatch::factory()->create();
    $game = Game::factory()->create(['match_id' => $match->id]);

    $localPlayerId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentPlayerId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);

    insertArchetypeGamePlayer($game->id, $localPlayerId, ['is_local' => true]);
    insertArchetypeGamePlayer($game->id, $opponentPlayerId, ['is_local' => false]);

    $archetype = Archetype::factory()->create();

    // Post-Phase-2 local row: no player_id, is_opponent=false.
    $newRow = MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $archetype->id,
        'player_id' => null,
        'confidence' => 0.95,
        'is_opponent' => false,
    ]);

    BackfillMatchArchetypeSides::run($match);

    // Stays false — untouched.
    expect($newRow->fresh()->is_opponent)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Edge: no game_player rows → nothing crashes, legacy rows unchanged
// ---------------------------------------------------------------------------

it('is a no-op when the match has no legacy game_player rows', function () {
    $match = MtgoMatch::factory()->create();
    $archetype = Archetype::factory()->create();
    $playerId = DB::table('players')->insertGetId(['username' => 'SomePlayer', 'created_at' => now(), 'updated_at' => now()]);

    $row = MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $archetype->id,
        'player_id' => $playerId,
        'confidence' => 0.7,
        'is_opponent' => false,
    ]);

    BackfillMatchArchetypeSides::run($match);

    // No game_player data → can't determine side → left unchanged.
    expect($row->fresh()->is_opponent)->toBeFalse();
});
