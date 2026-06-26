<?php

use App\Actions\Upgrade\RunParticipantBackfill;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Game;
use App\Models\GameDeck;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use App\Models\SchemaUpgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Insert a legacy `game_player` row (mirrors the helper in BackfillMatchParticipantsTest).
 *
 * @param  array<string, mixed>  $overrides
 */
function insertCoordGamePlayer(int $gameId, int $playerId, array $overrides = []): void
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
 * Build an old-schema match with two game_player rows per game.
 *
 * Returns the match.
 */
function buildCoordLegacyMatch(int $localPlayerId, int $opponentPlayerId, array $matchOverrides = []): MtgoMatch
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

    insertCoordGamePlayer($game->id, $localPlayerId, [
        'is_local' => true,
        'on_play' => true,
        'instance_id' => 10,
        'dice_roll' => 12,
        'mulligan_count' => 0,
        'deck_json' => json_encode([['mtgo_id' => 101, 'quantity' => 4, 'sideboard' => false]]),
    ]);

    insertCoordGamePlayer($game->id, $opponentPlayerId, [
        'is_local' => false,
        'on_play' => false,
        'instance_id' => 20,
        'dice_roll' => 5,
        'mulligan_count' => 1,
        'deck_json' => json_encode([['mtgo_id' => 202, 'quantity' => 4, 'sideboard' => false]]),
    ]);

    // Add a legacy match_archetype row for the opponent player so
    // BackfillMatchArchetypeSides has something to flip.
    $archetype = Archetype::factory()->create();
    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $archetype->id,
        'player_id' => $opponentPlayerId,
        'confidence' => 0.8,
        'is_opponent' => false, // legacy default — should become true
    ]);

    return $match;
}

// ---------------------------------------------------------------------------
// Happy path
// ---------------------------------------------------------------------------

it('populates participants, archetypes, and synthesizes gameless games, and marks tracker complete', function () {
    $account = Account::factory()->create(['username' => 'LocalUser', 'active' => true]);
    $localPlayerId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentPlayerId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);

    // Three legacy matches
    $match1 = buildCoordLegacyMatch($localPlayerId, $opponentPlayerId);
    $match2 = buildCoordLegacyMatch($localPlayerId, $opponentPlayerId);
    $match3 = buildCoordLegacyMatch($localPlayerId, $opponentPlayerId);

    // One gameless imported match: no games but games_won/games_lost set
    $gamelessMatch = MtgoMatch::factory()->create([
        'games_won' => 2,
        'games_lost' => 1,
        'started_at' => now(),
    ]);

    $tracker = SchemaUpgrade::create(['status' => 'pending']);

    RunParticipantBackfill::run($tracker);

    // --- Tracker ---
    $tracker->refresh();
    expect($tracker->status)->toBe('complete');
    expect($tracker->progress)->toBeGreaterThan(0);
    expect($tracker->total)->toBeGreaterThan(0);
    expect($tracker->completed_at)->not->toBeNull();
    expect($tracker->started_at)->not->toBeNull();

    // --- account_id / opponent_id populated on every legacy match ---
    foreach ([$match1, $match2, $match3] as $match) {
        $match->refresh();
        expect($match->account_id)->toBe($account->id);
        expect($match->opponent_id)->not->toBeNull();

        $opponent = Opponent::find($match->opponent_id);
        expect($opponent?->username)->toBe('OppUser');
    }

    // --- game_decks created ---
    foreach ([$match1, $match2, $match3] as $match) {
        foreach ($match->games as $game) {
            expect(GameDeck::where('game_id', $game->id)->count())->toBe(2);
        }
    }

    // --- match_archetypes.is_opponent flipped for opponent rows ---
    // The legacy player_id column has been dropped by the finalize stage, so we
    // read the new-schema is_opponent flag directly.
    $opponentArchetypes = MatchArchetype::whereIn(
        'mtgo_match_id',
        [$match1->id, $match2->id, $match3->id]
    )->get();

    expect($opponentArchetypes)->not->toBeEmpty();
    foreach ($opponentArchetypes as $ma) {
        expect($ma->is_opponent)->toBeTrue();
    }

    // --- gameless match got synthetic game rows ---
    expect(Game::where('match_id', $gamelessMatch->id)->count())->toBe(3);
    expect(Game::where('match_id', $gamelessMatch->id)->where('won', true)->count())->toBe(2);
    expect(Game::where('match_id', $gamelessMatch->id)->where('won', false)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

it('is idempotent — running twice produces identical results and no duplicates', function () {
    Account::factory()->create(['username' => 'LocalUser', 'active' => true]);
    $localPlayerId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentPlayerId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);

    $match = buildCoordLegacyMatch($localPlayerId, $opponentPlayerId);

    $gamelessMatch = MtgoMatch::factory()->create([
        'games_won' => 1,
        'games_lost' => 1,
        'started_at' => now(),
    ]);

    // First run
    $tracker1 = SchemaUpgrade::create(['status' => 'pending']);
    RunParticipantBackfill::run($tracker1);
    expect($tracker1->fresh()->status)->toBe('complete');

    // Snapshot results after first run
    $opponentCountAfterFirst = Opponent::where('username', 'OppUser')->count();
    $gamelessGamesAfterFirst = Game::where('match_id', $gamelessMatch->id)->count();
    $gameDecksAfterFirst = GameDeck::whereIn('game_id', $match->games->pluck('id'))->count();

    // Second run with a fresh tracker
    $tracker2 = SchemaUpgrade::create(['status' => 'pending']);
    RunParticipantBackfill::run($tracker2);
    expect($tracker2->fresh()->status)->toBe('complete');

    // Counts must be identical — no duplicates
    expect(Opponent::where('username', 'OppUser')->count())->toBe($opponentCountAfterFirst);
    expect(Game::where('match_id', $gamelessMatch->id)->count())->toBe($gamelessGamesAfterFirst);
    expect(GameDeck::whereIn('game_id', $match->games->pluck('id'))->count())->toBe($gameDecksAfterFirst);

    // Exactly one opponent row
    expect(Opponent::where('username', 'OppUser')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Tracker helpers
// ---------------------------------------------------------------------------

it('works without a tracker (null tracker does not crash)', function () {
    Account::factory()->create(['username' => 'LocalUser', 'active' => true]);
    $localPlayerId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentPlayerId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);

    buildCoordLegacyMatch($localPlayerId, $opponentPlayerId);

    // Should not throw
    RunParticipantBackfill::run(null);

    expect(Opponent::where('username', 'OppUser')->exists())->toBeTrue();
});

it('marks the tracker failed and rethrows when an exception occurs', function () {
    $tracker = SchemaUpgrade::create(['status' => 'pending']);

    // Create a match with games + a legacy game_player row so the participants
    // stage actually runs and reaches the players join.
    Account::factory()->create(['username' => 'LocalUser', 'active' => true]);
    $localPlayerId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentPlayerId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);
    $match = MtgoMatch::factory()->create(['account_id' => null, 'opponent_id' => null]);
    $game = Game::factory()->create(['match_id' => $match->id]);
    insertCoordGamePlayer($game->id, $opponentPlayerId);

    // Rename players (not game_player) so the orchestrator still runs stage 1
    // — game_player is present — but BackfillMatchParticipants fails on the
    // join to the now-missing players table during chunk processing.
    DB::statement('ALTER TABLE players RENAME TO players_hidden');

    $threw = false;

    try {
        RunParticipantBackfill::run($tracker);
    } catch (Throwable $e) {
        $threw = true;
    } finally {
        // Always restore before assertions so RefreshDatabase can clean up.
        DB::statement('ALTER TABLE players_hidden RENAME TO players');
    }

    expect($threw)->toBeTrue();
    expect($tracker->fresh()->status)->toBe('failed');
    expect($tracker->fresh()->error)->not->toBeNull();
});

it('tracker stage advances through participants → archetypes → cleanup → finalize', function () {
    Account::factory()->create(['username' => 'LocalUser', 'active' => true]);
    $localPlayerId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentPlayerId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);

    // Create 2 matches so participants and archetypes stages have work to do.
    buildCoordLegacyMatch($localPlayerId, $opponentPlayerId);
    buildCoordLegacyMatch($localPlayerId, $opponentPlayerId);

    $tracker = SchemaUpgrade::create(['status' => 'pending']);

    RunParticipantBackfill::run($tracker);

    $tracker->refresh();
    // The tracker ends on the finalize stage (last stage processed).
    expect($tracker->stage)->toBe('finalize');
    // Finalize has total=1 and progress=1 (one call to DropLegacyParticipantSchema).
    expect($tracker->total)->toBe(1);
    expect($tracker->progress)->toBe(1);
    expect($tracker->status)->toBe('complete');
    expect($tracker->started_at)->not->toBeNull();
    expect($tracker->completed_at)->not->toBeNull();

    // The destructive finalize stage dropped the legacy tables.
    expect(Schema::hasTable('game_player'))->toBeFalse();
    expect(Schema::hasTable('players'))->toBeFalse();
});
