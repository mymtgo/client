<?php

use App\Actions\Upgrade\BackfillMatchParticipants;
use App\Models\Account;
use App\Models\Game;
use App\Models\GameDeck;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Insert a legacy `game_player` row for a given game + player.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertGamePlayer(int $gameId, int $playerId, array $overrides = []): void
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
 * Build an old-schema fixture:
 *  - a match with account_id / opponent_id null
 *  - two games, each with a local + opponent game_player row
 *
 * Returns [$match, $game1, $game2, $localPlayerId, $opponentPlayerId].
 *
 * @return array{0: MtgoMatch, 1: Game, 2: Game, 3: int, 4: int}
 */
function buildLegacyFixture(array $matchOverrides = []): array
{
    $match = MtgoMatch::factory()->create(array_merge([
        'account_id' => null,
        'opponent_id' => null,
    ], $matchOverrides));

    $game1 = Game::factory()->create([
        'match_id' => $match->id,
        'local_on_play' => null,
        'local_mulligans' => null,
        'opp_mulligans' => null,
        'local_dice' => null,
        'opp_dice' => null,
        'local_instance' => null,
        'opp_instance' => null,
    ]);

    $game2 = Game::factory()->create([
        'match_id' => $match->id,
        'local_on_play' => null,
        'local_mulligans' => null,
        'opp_mulligans' => null,
        'local_dice' => null,
        'opp_dice' => null,
        'local_instance' => null,
        'opp_instance' => null,
    ]);

    $localPlayerId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    $opponentPlayerId = DB::table('players')->insertGetId(['username' => 'OppUser', 'created_at' => now(), 'updated_at' => now()]);

    // Game 1: local on play, dice rolled, mulligans kept
    insertGamePlayer($game1->id, $localPlayerId, [
        'is_local' => true,
        'on_play' => true,
        'instance_id' => 11,
        'dice_roll' => 15,
        'mulligan_count' => 1,
        'deck_json' => json_encode([['mtgo_id' => 101, 'quantity' => 4, 'sideboard' => false]]),
    ]);
    insertGamePlayer($game1->id, $opponentPlayerId, [
        'is_local' => false,
        'on_play' => false,
        'instance_id' => 12,
        'dice_roll' => 8,
        'mulligan_count' => 0,
        'deck_json' => json_encode([['mtgo_id' => 202, 'quantity' => 4, 'sideboard' => false]]),
    ]);

    // Game 2: opponent on play, no dice (0 → not a real roll), local took 0 mulligans
    insertGamePlayer($game2->id, $localPlayerId, [
        'is_local' => true,
        'on_play' => false,
        'instance_id' => 21,
        'dice_roll' => 0,
        'mulligan_count' => 0,
        'deck_json' => json_encode([['mtgo_id' => 101, 'quantity' => 4, 'sideboard' => false]]),
    ]);
    insertGamePlayer($game2->id, $opponentPlayerId, [
        'is_local' => false,
        'on_play' => true,
        'instance_id' => 22,
        'dice_roll' => 0,
        'mulligan_count' => 2,
        'deck_json' => json_encode([['mtgo_id' => 202, 'quantity' => 4, 'sideboard' => false]]),
    ]);

    return [$match, $game1, $game2, $localPlayerId, $opponentPlayerId];
}

// ---------------------------------------------------------------------------
// account_id + opponent_id on match
// ---------------------------------------------------------------------------

it('sets account_id from the local player username', function () {
    $account = Account::factory()->create(['username' => 'LocalUser', 'active' => true]);
    [$match] = buildLegacyFixture();

    BackfillMatchParticipants::run($match);

    expect($match->fresh()->account_id)->toBe($account->id);
});

it('falls back to Account::currentId() when no matching account username', function () {
    $account = Account::factory()->create(['username' => 'SomeOtherUser', 'active' => true]);
    [$match] = buildLegacyFixture();

    BackfillMatchParticipants::run($match);

    expect($match->fresh()->account_id)->toBe($account->id);
});

it('sets opponent_id and creates an opponents row', function () {
    [$match] = buildLegacyFixture();

    BackfillMatchParticipants::run($match);

    $match->refresh();
    expect($match->opponent_id)->not->toBeNull();

    $opponent = Opponent::find($match->opponent_id);
    expect($opponent)->not->toBeNull();
    expect($opponent->username)->toBe('OppUser');
});

// ---------------------------------------------------------------------------
// game scalars
// ---------------------------------------------------------------------------

it('populates game scalars from legacy game_player rows', function () {
    [$match, $game1, $game2] = buildLegacyFixture();

    BackfillMatchParticipants::run($match);

    $g1 = $game1->fresh();
    expect($g1->local_on_play)->toBeTrue();
    expect($g1->local_mulligans)->toBe(1);
    expect($g1->opp_mulligans)->toBe(0);
    expect($g1->local_dice)->toBe(15);
    expect($g1->opp_dice)->toBe(8);
    expect($g1->local_instance)->toBe(11);
    expect($g1->opp_instance)->toBe(12);

    $g2 = $game2->fresh();
    expect($g2->local_on_play)->toBeFalse();
    expect($g2->local_mulligans)->toBe(0);
    expect($g2->opp_mulligans)->toBe(2);
    expect($g2->local_instance)->toBe(21);
    expect($g2->opp_instance)->toBe(22);
});

it('does not write dice when dice_roll is 0', function () {
    [$match, , $game2] = buildLegacyFixture();

    BackfillMatchParticipants::run($match);

    $g2 = $game2->fresh();
    expect($g2->local_dice)->toBeNull();
    expect($g2->opp_dice)->toBeNull();
});

it('writes mulligan_count 0 as a valid value', function () {
    [$match, $game1] = buildLegacyFixture();

    BackfillMatchParticipants::run($match);

    // Opponent in game 1 took 0 mulligans — that must be written, not skipped.
    $g1 = $game1->fresh();
    expect($g1->opp_mulligans)->toBe(0);
});

// ---------------------------------------------------------------------------
// game_decks
// ---------------------------------------------------------------------------

it('creates two game_decks rows per game with correct deck_json', function () {
    [$match, $game1, $game2] = buildLegacyFixture();

    BackfillMatchParticipants::run($match);

    $localDeck1 = GameDeck::where('game_id', $game1->id)->where('is_opponent', false)->first();
    $oppDeck1 = GameDeck::where('game_id', $game1->id)->where('is_opponent', true)->first();

    expect($localDeck1)->not->toBeNull();
    expect($localDeck1->deck_json)->toBe([['mtgo_id' => 101, 'quantity' => 4, 'sideboard' => false]]);

    expect($oppDeck1)->not->toBeNull();
    expect($oppDeck1->deck_json)->toBe([['mtgo_id' => 202, 'quantity' => 4, 'sideboard' => false]]);

    expect(GameDeck::where('game_id', $game2->id)->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// idempotency
// ---------------------------------------------------------------------------

it('is idempotent — running twice produces identical results', function () {
    Account::factory()->create(['username' => 'LocalUser', 'active' => true]);
    [$match] = buildLegacyFixture();

    BackfillMatchParticipants::run($match);
    BackfillMatchParticipants::run($match->fresh());

    // Exactly one opponents row for OppUser.
    expect(Opponent::where('username', 'OppUser')->count())->toBe(1);

    // Exactly two game_decks per game (no duplicates).
    $match->refresh();
    foreach ($match->games as $game) {
        expect(GameDeck::where('game_id', $game->id)->count())->toBe(2);
    }

    // Match account / opponent unchanged.
    $match->refresh();
    expect($match->account_id)->not->toBeNull();
    expect($match->opponent_id)->not->toBeNull();
});

it('does not overwrite already-set account_id or opponent_id', function () {
    $account = Account::factory()->create(['username' => 'LocalUser', 'active' => true]);
    $opponent = Opponent::factory()->create(['username' => 'OppUser']);

    [$match] = buildLegacyFixture([
        'account_id' => $account->id,
        'opponent_id' => $opponent->id,
    ]);

    BackfillMatchParticipants::run($match);

    $match->refresh();
    expect($match->account_id)->toBe($account->id);
    expect($match->opponent_id)->toBe($opponent->id);
    // No duplicate opponent row.
    expect(Opponent::where('username', 'OppUser')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// edge: match with no game_player rows at all (already-new match)
// ---------------------------------------------------------------------------

it('is a no-op for a match that has no legacy game_player rows', function () {
    $match = MtgoMatch::factory()->create([
        'account_id' => null,
        'opponent_id' => null,
    ]);
    Game::factory()->create(['match_id' => $match->id]);

    BackfillMatchParticipants::run($match);

    $match->refresh();
    // Nothing to project, so columns stay null.
    expect($match->account_id)->toBeNull();
    expect($match->opponent_id)->toBeNull();
    expect(GameDeck::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// edge: game missing local or opponent row
// ---------------------------------------------------------------------------

it('sets only the available side when a game is missing an opponent row', function () {
    $account = Account::factory()->create(['username' => 'LocalUser', 'active' => true]);

    $match = MtgoMatch::factory()->create(['account_id' => null, 'opponent_id' => null]);
    $game = Game::factory()->create([
        'match_id' => $match->id,
        'local_on_play' => null, 'local_mulligans' => null, 'opp_mulligans' => null,
        'local_dice' => null, 'opp_dice' => null, 'local_instance' => null, 'opp_instance' => null,
    ]);

    $localPlayerId = DB::table('players')->insertGetId(['username' => 'LocalUser', 'created_at' => now(), 'updated_at' => now()]);
    insertGamePlayer($game->id, $localPlayerId, [
        'is_local' => true, 'on_play' => true, 'instance_id' => 55,
        'dice_roll' => 12, 'mulligan_count' => 0,
        'deck_json' => json_encode([]),
    ]);
    // No opponent row inserted.

    BackfillMatchParticipants::run($match);

    $match->refresh();
    expect($match->account_id)->toBe($account->id);
    expect($match->opponent_id)->toBeNull(); // no opponent row found

    $g = $game->fresh();
    expect($g->local_instance)->toBe(55);
    expect($g->opp_instance)->toBeNull();
});
