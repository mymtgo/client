<?php

use App\Actions\Upgrade\BackfillMatchArchetypeSides;
use App\Actions\Upgrade\BackfillMatchParticipants;
use App\Actions\Upgrade\DropLegacyParticipantSchema;
use App\Actions\Upgrade\RunParticipantBackfill;
use App\Actions\Upgrade\SynthesizeGamelessImportGames;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Game;
use App\Models\GameDeck;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers — mirror the legacy-match builders used in RunParticipantBackfillTest
// ---------------------------------------------------------------------------

/**
 * Insert a legacy `game_player` row.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertDropGamePlayer(int $gameId, int $playerId, array $overrides = []): void
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
 * Build an old-schema match with two game_player rows + a legacy match_archetype.
 *
 * @param  array<string, mixed>  $matchOverrides
 */
function buildDropLegacyMatch(int $localPlayerId, int $opponentPlayerId, array $matchOverrides = []): MtgoMatch
{
    $match = MtgoMatch::factory()->create(array_merge([
        'account_id' => null,
        'opponent_id' => null,
    ], $matchOverrides));

    $game = Game::factory()->create([
        'match_id' => $match->id,
        'local_on_play' => null,
        'local_mulligans' => null,
        'opp_mulligans' => null,
        'local_dice' => null,
        'opp_dice' => null,
        'local_instance' => null,
        'opp_instance' => null,
    ]);

    insertDropGamePlayer($game->id, $localPlayerId, [
        'is_local' => true,
        'on_play' => true,
        'instance_id' => 10,
        'deck_json' => json_encode([['mtgo_id' => 101, 'quantity' => 4, 'sideboard' => false]]),
    ]);

    insertDropGamePlayer($game->id, $opponentPlayerId, [
        'is_local' => false,
        'on_play' => false,
        'instance_id' => 20,
        'deck_json' => json_encode([['mtgo_id' => 202, 'quantity' => 4, 'sideboard' => false]]),
    ]);

    $archetype = Archetype::factory()->create();
    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $archetype->id,
        'player_id' => $opponentPlayerId,
        'confidence' => 0.8,
        'is_opponent' => false,
    ]);

    return $match;
}

// ---------------------------------------------------------------------------
// Happy path — full backfill then drop
// ---------------------------------------------------------------------------

it('drops legacy schema after a complete backfill while preserving new-schema data', function () {
    $account = Account::factory()->create(['username' => 'LocalUser', 'active' => true]);
    $localPlayerId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentPlayerId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);

    $match = buildDropLegacyMatch($localPlayerId, $opponentPlayerId);

    // Backfill populates the new schema from the legacy tables.
    RunParticipantBackfill::run();

    // Sanity: new-schema data is in place before the drop.
    $match->refresh();
    expect($match->account_id)->toBe($account->id);
    expect($match->opponent_id)->not->toBeNull();
    $gameId = $match->games->first()->id;
    expect(GameDeck::where('game_id', $gameId)->count())->toBe(2);

    DropLegacyParticipantSchema::run();

    // Legacy tables gone.
    expect(Schema::hasTable('game_player'))->toBeFalse();
    expect(Schema::hasTable('players'))->toBeFalse();

    // Dropped columns gone.
    expect(Schema::hasColumn('match_archetypes', 'player_id'))->toBeFalse();
    expect(Schema::hasColumn('games', 'starting_hand_size'))->toBeFalse();

    // New-schema data survives the drop.
    $match->refresh();
    expect($match->account_id)->toBe($account->id);
    expect($match->opponent_id)->not->toBeNull();
    expect(Opponent::find($match->opponent_id)?->username)->toBe('OppUser');
    expect(GameDeck::where('game_id', $gameId)->count())->toBe(2);

    $opponentArchetype = MatchArchetype::where('mtgo_match_id', $match->id)->first();
    expect($opponentArchetype->is_opponent)->toBeTrue();
});

it('enforces a UNIQUE(mtgo_match_id, is_opponent) constraint on match_archetypes after the drop', function () {
    Account::factory()->create(['username' => 'LocalUser', 'active' => true]);
    $localPlayerId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentPlayerId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);

    $match = buildDropLegacyMatch($localPlayerId, $opponentPlayerId);

    RunParticipantBackfill::run();
    DropLegacyParticipantSchema::run();

    $archetype = Archetype::factory()->create();

    // An existing opponent-side row was created by the backfill (is_opponent = true).
    // Inserting a second opponent-side row for the same match must violate the unique index.
    expect(fn () => DB::table('match_archetypes')->insert([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $archetype->id,
        'confidence' => 0.5,
        'is_opponent' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// Guard — refuse to drop when backfill is incomplete
// ---------------------------------------------------------------------------

it('refuses to drop and leaves legacy tables intact when a match has game_player data but no account_id', function () {
    $localPlayerId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentPlayerId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);

    // Legacy match left un-backfilled: account_id stays NULL while game_player rows exist.
    buildDropLegacyMatch($localPlayerId, $opponentPlayerId);

    expect(fn () => DropLegacyParticipantSchema::run())
        ->toThrow(RuntimeException::class);

    // Nothing dropped.
    expect(Schema::hasTable('game_player'))->toBeTrue();
    expect(Schema::hasTable('players'))->toBeTrue();
    expect(Schema::hasColumn('match_archetypes', 'player_id'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

it('is a safe no-op when called twice (tables already dropped)', function () {
    Account::factory()->create(['username' => 'LocalUser', 'active' => true]);
    $localPlayerId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentPlayerId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);

    buildDropLegacyMatch($localPlayerId, $opponentPlayerId);

    RunParticipantBackfill::run();

    DropLegacyParticipantSchema::run();
    expect(Schema::hasTable('game_player'))->toBeFalse();

    // Second call must not throw.
    DropLegacyParticipantSchema::run();
    expect(Schema::hasTable('game_player'))->toBeFalse();
});

it('is a safe no-op when no matches and no legacy data exist', function () {
    // game_player table exists (migrations ran) but is empty, no matches.
    expect(fn () => DropLegacyParticipantSchema::run())->not->toThrow(Exception::class);

    expect(Schema::hasTable('game_player'))->toBeFalse();
    expect(Schema::hasTable('players'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Mid-drop retry — the previously untested partial-state path
// ---------------------------------------------------------------------------

it('converges to the final state when retried after a crash that left game_player already dropped', function () {
    // Arrange: run backfill stages 1–3 (participants, archetypes, cleanup) without
    // stage 4 (finalize/drop). This gives us fully backfilled new-schema data while
    // the legacy tables are still present — the state just before the drop begins.
    $account = Account::factory()->create(['username' => 'LocalUser', 'active' => true]);
    $localPlayerId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentPlayerId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);

    $match = buildDropLegacyMatch($localPlayerId, $opponentPlayerId);

    // Stage 1: populate account_id / opponent_id / game scalars / game_decks.
    BackfillMatchParticipants::run($match);
    // Stage 2: set match_archetypes.is_opponent from legacy player_id.
    BackfillMatchArchetypeSides::run($match);
    // Stage 3: cleanup (synthesize gameless import games + delete orphans).
    SynthesizeGamelessImportGames::run();

    // Verify new-schema data is populated and legacy tables still exist.
    $match->refresh();
    expect($match->account_id)->toBe($account->id);
    expect(Schema::hasTable('game_player'))->toBeTrue();
    expect(Schema::hasTable('players'))->toBeTrue();
    expect(Schema::hasColumn('match_archetypes', 'player_id'))->toBeTrue();

    // Simulate a crash mid-drop: game_player was dropped (step 5 partial) but
    // players and the player_id column remain — the previously untestable orphan
    // state that the old blanket early-return could never recover from.
    Schema::dropIfExists('game_player');

    expect(Schema::hasTable('game_player'))->toBeFalse()
        ->and(Schema::hasTable('players'))->toBeTrue()
        ->and(Schema::hasColumn('match_archetypes', 'player_id'))->toBeTrue();

    // Act: retry must not throw and must complete all remaining drops.
    expect(fn () => DropLegacyParticipantSchema::run())->not->toThrow(Throwable::class);

    // Assert: final state is fully clean.
    expect(Schema::hasTable('game_player'))->toBeFalse();
    expect(Schema::hasTable('players'))->toBeFalse();
    expect(Schema::hasColumn('match_archetypes', 'player_id'))->toBeFalse();
    expect(Schema::hasColumn('games', 'starting_hand_size'))->toBeFalse();

    // UNIQUE index must be present.
    $indexNames = collect(Schema::getIndexes('match_archetypes'))->pluck('name')->all();
    expect($indexNames)->toContain('match_archetypes_mtgo_match_id_is_opponent_unique');

    // New-schema data is intact.
    $match->refresh();
    expect($match->account_id)->toBe($account->id);
    expect($match->opponent_id)->not->toBeNull();
});
