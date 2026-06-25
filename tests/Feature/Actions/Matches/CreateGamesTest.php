<?php

use App\Actions\Matches\CreateGames;
use App\Facades\Mtgo;
use App\Managers\MtgoManager;
use App\Models\Account;
use App\Models\Game;
use App\Models\GameDeck;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

/**
 * Build a minimal game_state_update raw_text JSON blob with local + opponent player.
 */
function makeGameStateJson(int $localId, string $localName, int $oppId, string $oppName, array $cards = []): string
{
    return json_encode([
        'Players' => [
            ['Name' => $localName, 'Id' => $localId],
            ['Name' => $oppName, 'Id' => $oppId],
        ],
        'Cards' => $cards,
    ]);
}

/**
 * Create a LogEvent of type game_state_update for a match/game combo.
 */
function makeStateEvent(int $matchMtgoId, int $gameMtgoId, string $rawText, bool $last = false): LogEvent
{
    return LogEvent::factory()->create([
        'event_type' => 'game_state_update',
        'match_token' => (string) $matchMtgoId,
        'game_id' => (string) $gameMtgoId,
        'raw_text' => $rawText,
        'timestamp' => $last ? '12:01:00' : '12:00:00',
    ]);
}

/**
 * Run CreateGames with no per-game meta (extractPerGameData returns null/username).
 * We mock Mtgo::resolveUsername and skip ExtractMetaMessageEntries/ExtractGameResults.
 */
function runCreateGames(MtgoMatch $match, Collection $events, string $username, array $playerDeck = []): void
{
    // Partial mock so only resolveUsername is intercepted; all other Mtgo methods pass through.
    $mock = Mockery::mock(MtgoManager::class)->makePartial();
    $mock->shouldReceive('resolveUsername')->andReturn($username);
    app()->instance('mtgo', $mock);

    // Stub ExtractMetaMessageEntries to return empty so extractPerGameData short-circuits
    // to [null, resolveUsername()]. SyncGamePivots will be called with null gameData (no-op).

    CreateGames::run($match, (int) $events->first()->game_id, $events, 0, $playerDeck);
}

// ---------------------------------------------------------------------------
// Core happy-path: local + opponent → new schema columns set
// ---------------------------------------------------------------------------

it('sets match.account_id when local account exists by username', function () {
    $account = Account::factory()->create(['username' => 'me', 'active' => true]);
    Account::flushCurrent();

    $match = MtgoMatch::factory()->create();
    $gameId = 999;

    $stateJson = makeGameStateJson(1, 'me', 2, 'opp');
    $events = collect([
        makeStateEvent($match->mtgo_id, $gameId, $stateJson),
    ]);

    runCreateGames($match, $events, 'me');

    expect($match->fresh()->account_id)->toBe($account->id);
});

it('sets match.opponent_id from firstOrCreate on opponent username', function () {
    Account::factory()->create(['username' => 'me', 'active' => true]);
    Account::flushCurrent();

    $match = MtgoMatch::factory()->create();
    $gameId = 999;

    $stateJson = makeGameStateJson(1, 'me', 2, 'opp');
    $events = collect([
        makeStateEvent($match->mtgo_id, $gameId, $stateJson),
    ]);

    runCreateGames($match, $events, 'me');

    $opponent = Opponent::where('username', 'opp')->first();
    expect($opponent)->not->toBeNull();
    expect($match->fresh()->opponent_id)->toBe($opponent->id);
});

it('sets games.local_instance and opp_instance from player Id', function () {
    Account::factory()->create(['username' => 'me', 'active' => true]);
    Account::flushCurrent();

    $match = MtgoMatch::factory()->create();
    $gameId = 999;

    $stateJson = makeGameStateJson(localId: 5, localName: 'me', oppId: 9, oppName: 'opp');
    $events = collect([
        makeStateEvent($match->mtgo_id, $gameId, $stateJson),
    ]);

    runCreateGames($match, $events, 'me');

    $game = Game::where('mtgo_id', $gameId)->first();
    expect($game)->not->toBeNull();
    expect($game->local_instance)->toBe(5);
    expect($game->opp_instance)->toBe(9);
});

it('writes two game_decks rows — one local, one opponent', function () {
    Account::factory()->create(['username' => 'me', 'active' => true]);
    Account::flushCurrent();

    $match = MtgoMatch::factory()->create();
    $gameId = 999;

    // Provide a playerDeck so buildLocalDeck produces something
    $playerDeck = [
        ['CatalogId' => 100, 'Quantity' => 4],
        ['CatalogId' => 200, 'Quantity' => 2],
    ];

    // Last state event for opponent deck
    $stateJson = makeGameStateJson(1, 'me', 2, 'opp', [
        ['Owner' => 2, 'CatalogID' => 300, 'Zone' => 'Battlefield'],
    ]);

    $events = collect([
        makeStateEvent($match->mtgo_id, $gameId, $stateJson, last: true),
    ]);

    runCreateGames($match, $events, 'me', $playerDeck);

    $game = Game::where('mtgo_id', $gameId)->first();
    expect($game)->not->toBeNull();

    $decks = GameDeck::where('game_id', $game->id)->get();
    expect($decks)->toHaveCount(2);

    $local = $decks->where('is_opponent', false)->first();
    $opp = $decks->where('is_opponent', true)->first();

    expect($local)->not->toBeNull();
    expect($opp)->not->toBeNull();

    // Local deck built from playerDeck (4×100, 2×200, all mainboard since no sideboard cards)
    expect($local->deck_json)->not->toBeEmpty();
    expect(collect($local->deck_json)->pluck('mtgo_id')->toArray())->toContain(100);
    expect(collect($local->deck_json)->pluck('mtgo_id')->toArray())->toContain(200);

    // Opponent deck built from snapshot cards owned by opponent (player Id=2)
    expect($opp->deck_json)->not->toBeEmpty();
    expect(collect($opp->deck_json)->pluck('mtgo_id')->toArray())->toContain(300);
});

it('writes no game_player rows', function () {
    Account::factory()->create(['username' => 'me', 'active' => true]);
    Account::flushCurrent();

    $match = MtgoMatch::factory()->create();
    $gameId = 999;

    $stateJson = makeGameStateJson(1, 'me', 2, 'opp');
    $events = collect([
        makeStateEvent($match->mtgo_id, $gameId, $stateJson),
    ]);

    runCreateGames($match, $events, 'me');

    expect(DB::table('game_player')->count())->toBe(0);
});

it('is idempotent — running twice does not duplicate game_decks rows', function () {
    Account::factory()->create(['username' => 'me', 'active' => true]);
    Account::flushCurrent();

    $match = MtgoMatch::factory()->create();
    $gameId = 999;

    $playerDeck = [['CatalogId' => 100, 'Quantity' => 4]];
    $stateJson = makeGameStateJson(1, 'me', 2, 'opp');

    $events = collect([
        makeStateEvent($match->mtgo_id, $gameId, $stateJson),
    ]);

    runCreateGames($match, $events, 'me', $playerDeck);

    // Re-create events (first set was consumed); reload fresh match
    $match2 = $match->fresh();
    $events2 = collect([
        makeStateEvent($match->mtgo_id, $gameId + 1, $stateJson), // different event, same game
    ]);

    // Run again on same game row (it'll be found via where('mtgo_id'))
    Mtgo::shouldReceive('resolveUsername')->andReturn('me');
    CreateGames::run($match2, $gameId, $events2, 0, $playerDeck);

    $game = Game::where('mtgo_id', $gameId)->first();
    $deckCount = GameDeck::where('game_id', $game->id)->count();
    expect($deckCount)->toBe(2); // still exactly one local + one opponent
});

it('does not overwrite match.account_id when already set', function () {
    $account = Account::factory()->create(['username' => 'me', 'active' => true]);
    Account::flushCurrent();

    $match = MtgoMatch::factory()->create(['account_id' => $account->id]);
    $gameId = 999;

    // Create a second account that would be returned by a different lookup
    Account::factory()->create(['username' => 'me2', 'active' => false]);

    $stateJson = makeGameStateJson(1, 'me', 2, 'opp');
    $events = collect([makeStateEvent($match->mtgo_id, $gameId, $stateJson)]);

    runCreateGames($match, $events, 'me');

    expect($match->fresh()->account_id)->toBe($account->id);
});

it('does not overwrite match.opponent_id when already set', function () {
    Account::factory()->create(['username' => 'me', 'active' => true]);
    Account::flushCurrent();

    $existingOpponent = Opponent::factory()->create(['username' => 'other']);
    $match = MtgoMatch::factory()->create(['opponent_id' => $existingOpponent->id]);
    $gameId = 999;

    $stateJson = makeGameStateJson(1, 'me', 2, 'opp');
    $events = collect([makeStateEvent($match->mtgo_id, $gameId, $stateJson)]);

    runCreateGames($match, $events, 'me');

    // opponent_id should stay as existingOpponent (not be replaced by new 'opp')
    expect($match->fresh()->opponent_id)->toBe($existingOpponent->id);
});

it('falls back to Account::currentId when no account matches the username', function () {
    $account = Account::factory()->create(['username' => 'other', 'active' => true]);
    Account::flushCurrent();

    $match = MtgoMatch::factory()->create();
    $gameId = 999;

    $stateJson = makeGameStateJson(1, 'me', 2, 'opp');
    $events = collect([makeStateEvent($match->mtgo_id, $gameId, $stateJson)]);

    runCreateGames($match, $events, 'me');

    // Falls back to Account::currentId() which is the active account
    expect($match->fresh()->account_id)->toBe($account->id);
});
