<?php

use App\Actions\Upgrade\SynthesizeGamelessImportGames;
use App\Models\Archetype;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// (b) Orphan cleanup
//
// To create orphaned rows in a transactional in-memory SQLite test, we must
// temporarily step outside the transaction that RefreshDatabase wraps each test
// in, because SQLite silently ignores PRAGMA foreign_keys changes made inside an
// active transaction. The approach:
//   1. Commit the outer test transaction (so we are outside any transaction).
//   2. Disable FK constraints, delete the parent match, re-enable FK constraints.
//   3. Start a fresh savepoint/transaction so the test proceeds normally.
//   4. After the test, the RefreshDatabase trait's rollback no longer has the
//      outer transaction to roll back, so we manually truncate affected tables
//      in a teardown instead.
//
// Because this mutates the connection's transaction state, these tests are
// isolated in a separate file from the main synthesis tests.
// ---------------------------------------------------------------------------

/**
 * Commit the outer transaction, disable FK constraints, delete the match, and
 * re-enable FK constraints — leaving child rows (games/match_archetypes) as
 * orphans. Returns the connection so the caller can begin a fresh transaction.
 *
 * After calling this, the caller is responsible for cleaning up state, because
 * the RefreshDatabase rollback no longer has an outer transaction to roll back.
 */
function disableFkAndDeleteMatch(int $matchId): void
{
    // Step out of the outer transaction so PRAGMA takes effect.
    DB::commit();
    Schema::disableForeignKeyConstraints();
    DB::table('matches')->where('id', $matchId)->delete();
    Schema::enableForeignKeyConstraints();
    // Re-wrap in a transaction so subsequent DB calls work normally.
    DB::beginTransaction();
}

afterEach(function () {
    // Since we committed the RefreshDatabase transaction, we must manually
    // clean up any data we inserted. Roll back the transaction we started in
    // disableFkAndDeleteMatch(), then truncate tables to reset state.
    try {
        DB::rollBack();
    } catch (Throwable) {
        // No active transaction — that's fine.
    }

    // Truncate in safe dependency order.
    Schema::disableForeignKeyConstraints();
    DB::table('game_player')->truncate();
    DB::table('game_timelines')->truncate();
    DB::table('match_archetypes')->truncate();
    DB::table('games')->truncate();
    DB::table('matches')->truncate();
    DB::table('players')->truncate();
    DB::table('archetypes')->truncate();
    DB::table('opponents')->truncate();
    Schema::enableForeignKeyConstraints();
});

it('deletes orphan games whose match_id has no matching matches row', function () {
    // Create a match and a game row, then orphan the game by deleting the match
    // with FK constraints temporarily disabled — mimicking the real missing-
    // cascade scenario that pre-dates the cascade constraint being added.
    $match = MtgoMatch::factory()->create(['games_won' => 0, 'games_lost' => 0]);
    $game = Game::factory()->create(['match_id' => $match->id]);

    disableFkAndDeleteMatch($match->id);

    // Confirm the orphan exists before the run.
    expect(Game::where('id', $game->id)->exists())->toBeTrue();

    SynthesizeGamelessImportGames::run();

    expect(Game::where('id', $game->id)->exists())->toBeFalse();
});

it('deletes orphan games that still carry game_player and game_timeline children', function () {
    // Mirrors the real production failure: legacy orphan games left behind by a
    // missing cascade still have game_player + game_timelines rows. Those tables
    // use RESTRICT foreign keys, so the orphan game cannot be deleted until its
    // children are removed first.
    $match = MtgoMatch::factory()->create(['games_won' => 0, 'games_lost' => 0]);
    $game = Game::factory()->create(['match_id' => $match->id]);

    $playerId = DB::table('players')->insertGetId([
        'username' => 'LegacyPlayer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('game_player')->insert([
        'game_id' => $game->id,
        'player_id' => $playerId,
        'instance_id' => 1,
        'is_local' => 1,
        'on_play' => 1,
        'starting_hand_size' => 7,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('game_timelines')->insert([
        'game_id' => $game->id,
        'timestamp' => now(),
        'content' => 'turn 1',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    disableFkAndDeleteMatch($match->id);

    expect(Game::where('id', $game->id)->exists())->toBeTrue();

    SynthesizeGamelessImportGames::run();

    expect(Game::where('id', $game->id)->exists())->toBeFalse();
    expect(DB::table('game_player')->where('game_id', $game->id)->exists())->toBeFalse();
    expect(DB::table('game_timelines')->where('game_id', $game->id)->exists())->toBeFalse();
});

it('deletes orphan match_archetypes whose mtgo_match_id has no matching matches row', function () {
    $match = MtgoMatch::factory()->create(['games_won' => 0, 'games_lost' => 0]);
    $archetype = Archetype::factory()->create();

    DB::table('match_archetypes')->insert([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $archetype->id,
        'is_opponent' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $archetypeRowId = DB::table('match_archetypes')
        ->where('mtgo_match_id', $match->id)
        ->value('id');

    disableFkAndDeleteMatch($match->id);

    // Confirm the orphan exists before the run.
    expect(DB::table('match_archetypes')->where('id', $archetypeRowId)->exists())->toBeTrue();

    SynthesizeGamelessImportGames::run();

    expect(DB::table('match_archetypes')->where('id', $archetypeRowId)->exists())->toBeFalse();
});

it('does not delete valid games and match_archetypes that belong to existing matches', function () {
    $match = MtgoMatch::factory()->create(['games_won' => 0, 'games_lost' => 0]);
    $game = Game::factory()->create(['match_id' => $match->id]);
    $archetype = Archetype::factory()->create();

    DB::table('match_archetypes')->insert([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $archetype->id,
        'is_opponent' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    SynthesizeGamelessImportGames::run();

    expect(Game::where('id', $game->id)->exists())->toBeTrue();
    expect(DB::table('match_archetypes')->where('mtgo_match_id', $match->id)->exists())->toBeTrue();
});
