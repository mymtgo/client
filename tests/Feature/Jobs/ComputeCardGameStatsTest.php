<?php

use App\Jobs\ComputeCardGameStats;
use App\Models\Card;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function createMatchWithGames(array $overrides = []): array
{
    $deckVersion = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create(array_merge([
        'deck_version_id' => $deckVersion->id,
        'state' => 'complete',
    ], $overrides));

    $localPlayer = Player::create(['username' => 'testplayer']);
    $opponent = Player::create(['username' => 'opponent']);

    return [$match, $deckVersion, $localPlayer, $opponent];
}

function attachPlayers(Game $game, Player $local, Player $opponent, int $localInstanceId = 0, int $opponentInstanceId = 1, array $deckJson = []): void
{
    $game->players()->attach($local->id, [
        'instance_id' => $localInstanceId,
        'is_local' => true,
        'on_play' => true,
        'deck_json' => $deckJson,
    ]);
    $game->players()->attach($opponent->id, [
        'instance_id' => $opponentInstanceId,
        'is_local' => false,
        'on_play' => false,
    ]);
}

function createTimeline(Game $game, array $cards): void
{
    GameTimeline::create([
        'game_id' => $game->id,
        'content' => [
            'Players' => [
                ['Id' => 0, 'Name' => 'testplayer', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
                ['Id' => 1, 'Name' => 'opponent', 'LibraryCount' => 60, 'HandCount' => 0, 'Life' => 20],
            ],
            'Cards' => $cards,
        ],
        'timestamp' => '09:00:00',
    ]);
}

it('detects sided out cards by comparing maindeck quantities between games', function () {
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    // Card A: 4 in maindeck game 1, 2 in maindeck game 2 (sided 2 out)
    // Card B: 0 in maindeck game 1 (sideboard), 2 in maindeck game 2 (sided in)
    $cardA = Card::factory()->create(['oracle_id' => 'oracle-a', 'mtgo_id' => 1001, 'name' => 'Card A']);
    $cardB = Card::factory()->create(['oracle_id' => 'oracle-b', 'mtgo_id' => 1002, 'name' => 'Card B']);

    // Game 1: Card A x4 maindeck, Card B x2 sideboard
    $game1 = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game1, $local, $opponent, deckJson: [
        ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => false],
        ['mtgo_id' => 1002, 'quantity' => 2, 'sideboard' => true],
    ]);
    createTimeline($game1, [
        ['Id' => 10, 'CatalogID' => 1002, 'Zone' => 'Sideboard', 'ActualZone' => 'Sideboard', 'Owner' => 0, 'Controller' => 0],
        ['Id' => 11, 'CatalogID' => 1002, 'Zone' => 'Sideboard', 'ActualZone' => 'Sideboard', 'Owner' => 0, 'Controller' => 0],
    ]);

    // Game 2: Card A x2 maindeck + x2 sideboard, Card B x2 maindeck (sided in)
    $game2 = Game::factory()->for($match, 'match')->create([
        'won' => false,
        'started_at' => now()->addMinutes(10),
    ]);
    attachPlayers($game2, $local, $opponent, deckJson: [
        ['mtgo_id' => 1001, 'quantity' => 2, 'sideboard' => false],
        ['mtgo_id' => 1001, 'quantity' => 2, 'sideboard' => true],
        ['mtgo_id' => 1002, 'quantity' => 2, 'sideboard' => false],
    ]);
    createTimeline($game2, [
        ['Id' => 20, 'CatalogID' => 1001, 'Zone' => 'Sideboard', 'ActualZone' => 'Sideboard', 'Owner' => 0, 'Controller' => 0],
        ['Id' => 21, 'CatalogID' => 1001, 'Zone' => 'Sideboard', 'ActualZone' => 'Sideboard', 'Owner' => 0, 'Controller' => 0],
    ]);

    (new ComputeCardGameStats($match->id))->handle();

    $stats = DB::table('card_game_stats')
        ->orderBy('oracle_id')
        ->orderBy('game_id')
        ->get();

    // Game 1: Card A in maindeck (not sided out), Card B not in maindeck (no row)
    $g1CardA = $stats->where('oracle_id', 'oracle-a')->where('game_id', $game1->id)->first();
    expect($g1CardA)->not->toBeNull();
    expect($g1CardA->quantity)->toBe(4);
    expect((bool) $g1CardA->is_postboard)->toBeFalse();
    expect((bool) $g1CardA->sided_out)->toBeFalse();
    expect((bool) $g1CardA->sided_in)->toBeFalse();

    $g1CardB = $stats->where('oracle_id', 'oracle-b')->where('game_id', $game1->id)->first();
    expect($g1CardB)->toBeNull(); // sideboard-only in game 1, no stats row

    // Game 2: Card A sided out (4 -> 2), Card B sided in (0 -> 2)
    $g2CardA = $stats->where('oracle_id', 'oracle-a')->where('game_id', $game2->id)->first();
    expect($g2CardA)->not->toBeNull();
    expect($g2CardA->quantity)->toBe(2);
    expect((bool) $g2CardA->is_postboard)->toBeTrue();
    expect((bool) $g2CardA->sided_out)->toBeTrue();
    expect((bool) $g2CardA->sided_in)->toBeFalse();

    $g2CardB = $stats->where('oracle_id', 'oracle-b')->where('game_id', $game2->id)->first();
    expect($g2CardB)->not->toBeNull();
    expect($g2CardB->quantity)->toBe(2);
    expect((bool) $g2CardB->is_postboard)->toBeTrue();
    expect((bool) $g2CardB->sided_out)->toBeFalse();
    expect((bool) $g2CardB->sided_in)->toBeTrue(); // sided IN from sideboard
});

it('does not mark cards as sided out when deck is unchanged between games', function () {
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    $card = Card::factory()->create(['oracle_id' => 'oracle-c', 'mtgo_id' => 2001, 'name' => 'Card C']);

    // Same deck in both games (no sideboarding)
    $deckJson = [
        ['mtgo_id' => 2001, 'quantity' => 4, 'sideboard' => false],
    ];

    $game1 = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game1, $local, $opponent, deckJson: $deckJson);
    createTimeline($game1, []);

    $game2 = Game::factory()->for($match, 'match')->create([
        'won' => false,
        'started_at' => now()->addMinutes(10),
    ]);
    attachPlayers($game2, $local, $opponent, deckJson: $deckJson);
    createTimeline($game2, []);

    (new ComputeCardGameStats($match->id))->handle();

    $g2Stat = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-c')
        ->where('game_id', $game2->id)
        ->first();

    expect($g2Stat)->not->toBeNull();
    expect((bool) $g2Stat->sided_out)->toBeFalse();
    expect((bool) $g2Stat->sided_in)->toBeFalse();
    expect($g2Stat->quantity)->toBe(4);
});

it('creates a sided_out row for cards completely moved to sideboard in postboard games', function () {
    [$match, $deckVersion, $local, $opponent] = createMatchWithGames();

    // Card D: 3 in maindeck game 1, entirely sideboard in game 2
    $card = Card::factory()->create(['oracle_id' => 'oracle-d', 'mtgo_id' => 3001, 'name' => 'Card D']);

    $game1 = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now(),
    ]);
    attachPlayers($game1, $local, $opponent, deckJson: [
        ['mtgo_id' => 3001, 'quantity' => 3, 'sideboard' => false],
    ]);
    createTimeline($game1, []);

    $game2 = Game::factory()->for($match, 'match')->create([
        'won' => false,
        'started_at' => now()->addMinutes(10),
    ]);
    attachPlayers($game2, $local, $opponent, deckJson: [
        ['mtgo_id' => 3001, 'quantity' => 3, 'sideboard' => true],
    ]);
    createTimeline($game2, []);

    (new ComputeCardGameStats($match->id))->handle();

    // Game 1: normal maindeck row
    $g1Stat = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-d')
        ->where('game_id', $game1->id)
        ->first();

    expect($g1Stat)->not->toBeNull();
    expect($g1Stat->quantity)->toBe(3);
    expect((bool) $g1Stat->sided_out)->toBeFalse();

    // Game 2: completely sided out — row created with quantity 0
    $g2Stat = DB::table('card_game_stats')
        ->where('oracle_id', 'oracle-d')
        ->where('game_id', $game2->id)
        ->first();

    expect($g2Stat)->not->toBeNull();
    expect($g2Stat->quantity)->toBe(0);
    expect((bool) $g2Stat->is_postboard)->toBeTrue();
    expect((bool) $g2Stat->sided_out)->toBeTrue();
    expect((bool) $g2Stat->sided_in)->toBeFalse();
});
