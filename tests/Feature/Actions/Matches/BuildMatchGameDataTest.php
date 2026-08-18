<?php

use App\Actions\Matches\BuildMatchGameData;
use App\Models\Card;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('handles missing cards in the card collection without crashing', function () {
    $match = MtgoMatch::create([
        'token' => 'test-token',
        'mtgo_id' => '12345',
        'format' => 'modern',
        'match_type' => 'league',
        'outcome' => 'win',
        'started_at' => now()->subMinutes(30),
        'ended_at' => now(),
    ]);

    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => 'game-1',
        'won' => true,
        'started_at' => now()->subMinutes(20),
        'ended_at' => now()->subMinutes(10),
    ]);

    $localPlayer = Player::create(['username' => 'local_player']);
    $opponentPlayer = Player::create(['username' => 'opponent_player']);

    $game->players()->attach($localPlayer->id, [
        'instance_id' => 1,
        'is_local' => true,
        'on_play' => true,
        'starting_hand_size' => 7,
        'deck_json' => [
            ['mtgo_id' => 9999, 'quantity' => 1, 'sideboard' => false],
        ],
    ]);

    $game->players()->attach($opponentPlayer->id, [
        'instance_id' => 2,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
        'deck_json' => [
            ['mtgo_id' => 8888, 'quantity' => 1],
        ],
    ]);

    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => now()->subMinutes(15),
        'content' => [
            'Players' => [
                ['Id' => 1, 'HandCount' => 7, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 9999, 'Owner' => 1, 'Zone' => 'Hand'],
            ],
        ],
    ]);

    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => now()->subMinutes(14),
        'content' => [
            'Players' => [
                ['Id' => 1, 'HandCount' => 6, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 9999, 'Owner' => 1, 'Zone' => 'Battlefield'],
            ],
        ],
    ]);

    $game = $game->fresh()->load(['players', 'timeline']);

    // Empty card collections — simulates missing/unknown cards
    $cardsByMtgoId = collect();
    $cardsByOracleId = collect();

    $result = BuildMatchGameData::run($game, 1, $cardsByMtgoId, $cardsByOracleId, []);

    expect($result)->toHaveKeys(['id', 'number', 'won', 'keptHand', 'opponentCardsSeen', 'localCardsPlayed']);

    // Card names should use the fallback "Unknown (id)" pattern
    expect($result['opponentCardsSeen'][0]['name'])->toContain('Unknown');
    expect($result['keptHand'][0]['name'])->toContain('Unknown');
});

it('resolves card names when cards exist in collection', function () {
    $match = MtgoMatch::create([
        'token' => 'test-token-2',
        'mtgo_id' => '12346',
        'format' => 'modern',
        'match_type' => 'league',
        'outcome' => 'win',
        'started_at' => now()->subMinutes(30),
        'ended_at' => now(),
    ]);

    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => 'game-2',
        'won' => true,
        'started_at' => now()->subMinutes(20),
        'ended_at' => now()->subMinutes(10),
    ]);

    $localPlayer = Player::create(['username' => 'local_player']);
    $opponentPlayer = Player::create(['username' => 'opponent_player']);

    $game->players()->attach($localPlayer->id, [
        'instance_id' => 1,
        'is_local' => true,
        'on_play' => true,
        'starting_hand_size' => 7,
    ]);

    $game->players()->attach($opponentPlayer->id, [
        'instance_id' => 2,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
    ]);

    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => now()->subMinutes(15),
        'content' => [
            'Players' => [
                ['Id' => 1, 'HandCount' => 7, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 5001, 'Owner' => 1, 'Zone' => 'Hand'],
            ],
        ],
    ]);

    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => now()->subMinutes(14),
        'content' => [
            'Players' => [
                ['Id' => 1, 'HandCount' => 6, 'LibraryCount' => 53],
                ['Id' => 2, 'HandCount' => 7, 'LibraryCount' => 53],
            ],
            'Cards' => [
                ['Id' => 101, 'CatalogID' => 5001, 'Owner' => 1, 'Zone' => 'Battlefield'],
            ],
        ],
    ]);

    $game = $game->fresh()->load(['players', 'timeline']);

    $card = Card::factory()->create(['mtgo_id' => 5001, 'name' => 'Lightning Bolt', 'type' => 'Instant']);
    $cardsByMtgoId = collect([$card->mtgo_id => $card]);
    $cardsByOracleId = collect();

    $result = BuildMatchGameData::run($game, 1, $cardsByMtgoId, $cardsByOracleId, []);

    expect($result['keptHand'][0]['name'])->toBe('Lightning Bolt');
});

/**
 * Build minimal Game with one local player whose deck_json mirrors $deckJson.
 */
function makeGameWithLocalDeck(array $deckJson, ?array $opponentDeckJson = null): Game
{
    $match = MtgoMatch::create([
        'token' => 'tok-'.uniqid(),
        'mtgo_id' => (string) fake()->unique()->numberBetween(100000, 999999),
        'format' => 'modern',
        'match_type' => 'league',
        'outcome' => 'win',
        'started_at' => now()->subMinutes(30),
        'ended_at' => now(),
    ]);

    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => 'game-'.uniqid(),
        'won' => true,
        'started_at' => now()->subMinutes(20),
        'ended_at' => now()->subMinutes(10),
    ]);

    $localPlayer = Player::create(['username' => 'local-'.uniqid()]);
    $opponentPlayer = Player::create(['username' => 'opp-'.uniqid()]);

    $game->players()->attach($localPlayer->id, [
        'instance_id' => 1,
        'is_local' => true,
        'on_play' => true,
        'starting_hand_size' => 7,
        'deck_json' => $deckJson,
    ]);

    $game->players()->attach($opponentPlayer->id, [
        'instance_id' => 2,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
        'deck_json' => $opponentDeckJson ?? [],
    ]);

    return $game->fresh()->load(['players', 'timeline']);
}

it('reports no sideboard changes when game deck matches registered deck (canonical accessor output)', function () {
    $cardA = Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001']);
    $cardB = Card::factory()->create(['mtgo_id' => 1002, 'oracle_id' => 'oracle-1002']);

    $game = makeGameWithLocalDeck([
        ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => false],
        ['mtgo_id' => 1002, 'quantity' => 3, 'sideboard' => false],
    ]);

    $registeredCards = [
        ['oracle_id' => 'oracle-1001', 'mtgo_id' => 1001, 'quantity' => '4', 'sideboard' => 'false'],
        ['oracle_id' => 'oracle-1002', 'mtgo_id' => 1002, 'quantity' => '3', 'sideboard' => 'false'],
    ];

    $cardsByMtgoId = collect([1001 => $cardA, 1002 => $cardB]);
    $cardsByOracleId = collect(['oracle-1001' => $cardA, 'oracle-1002' => $cardB]);

    $result = BuildMatchGameData::run($game, 1, $cardsByMtgoId, $cardsByOracleId, $registeredCards);

    expect($result['sideboardChanges'])->toBe([]);
});

it('reports no sideboard changes when oracle_id is null but mtgo_id matches', function () {
    // Reproduces the oracle_id resolution race: Card row exists with null
    // oracle_id (e.g. created mid-pipeline). Both registered and game deck
    // anchor on mtgo_id 1001 — should NOT report spurious in/out.
    $card = Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => null]);

    $game = makeGameWithLocalDeck([
        ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => false],
    ]);

    $registeredCards = [
        ['oracle_id' => null, 'mtgo_id' => 1001, 'quantity' => '4', 'sideboard' => 'false'],
    ];

    $cardsByMtgoId = collect([1001 => $card]);
    $cardsByOracleId = collect();

    $result = BuildMatchGameData::run($game, 1, $cardsByMtgoId, $cardsByOracleId, $registeredCards);

    expect($result['sideboardChanges'])->toBe([]);
});

it('detects sideboard swap (one card in, one card out) keyed by mtgo_id', function () {
    $cardMain = Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001', 'name' => 'Main Card']);
    $cardSwap = Card::factory()->create(['mtgo_id' => 1002, 'oracle_id' => 'oracle-1002', 'name' => 'Swap Card']);

    // Registered: 4x cardMain
    $registeredCards = [
        ['oracle_id' => 'oracle-1001', 'mtgo_id' => 1001, 'quantity' => '4', 'sideboard' => 'false'],
    ];

    // Played: 3x cardMain + 1x cardSwap maindeck
    $game = makeGameWithLocalDeck([
        ['mtgo_id' => 1001, 'quantity' => 3, 'sideboard' => false],
        ['mtgo_id' => 1002, 'quantity' => 1, 'sideboard' => false],
    ]);

    $cardsByMtgoId = collect([1001 => $cardMain, 1002 => $cardSwap]);
    $cardsByOracleId = collect(['oracle-1001' => $cardMain, 'oracle-1002' => $cardSwap]);

    $result = BuildMatchGameData::run($game, 1, $cardsByMtgoId, $cardsByOracleId, $registeredCards);

    expect($result['sideboardChanges'])->toHaveCount(2);

    $byType = collect($result['sideboardChanges'])->keyBy('type');
    expect($byType['in']['name'])->toBe('Swap Card');
    expect($byType['in']['quantity'])->toBe(1);
    expect($byType['out']['name'])->toBe('Main Card');
    expect($byType['out']['quantity'])->toBe(1);
});

it('treats different printings of the same card as identical (oracle_id match)', function () {
    // Same oracle card has two printings with different mtgo_ids. Registered
    // deck stores one printing's mtgo_id, game deck_json reports the other.
    // Should NOT report spurious in/out changes.
    $printingA = Card::factory()->create(['mtgo_id' => 2001, 'oracle_id' => 'oracle-urzas-mine', 'name' => "Urza's Mine"]);
    $printingB = Card::factory()->create(['mtgo_id' => 2002, 'oracle_id' => 'oracle-urzas-mine', 'name' => "Urza's Mine"]);

    $game = makeGameWithLocalDeck([
        ['mtgo_id' => 2002, 'quantity' => 4, 'sideboard' => false],
    ]);

    $registeredCards = [
        ['oracle_id' => 'oracle-urzas-mine', 'mtgo_id' => 2001, 'quantity' => '4', 'sideboard' => 'false'],
    ];

    $cardsByMtgoId = collect([2001 => $printingA, 2002 => $printingB]);
    $cardsByOracleId = collect(['oracle-urzas-mine' => $printingA]);

    $result = BuildMatchGameData::run($game, 1, $cardsByMtgoId, $cardsByOracleId, $registeredCards);

    expect($result['sideboardChanges'])->toBe([]);
});

it('includes opponent cards seen only in the game log (absent from final snapshot)', function () {
    // A card the opponent cast that later left visible zones (e.g. Lembas
    // shuffled into its owner's library) is missing from deck_json but
    // present in the game log. It must still appear in opponentCardsSeen.
    $land = Card::factory()->create(['mtgo_id' => 8001, 'name' => 'Swamp', 'type' => 'Basic Land — Swamp']);
    $lembas = Card::factory()->create(['mtgo_id' => 8002, 'name' => 'Lembas', 'type' => 'Artifact']);

    $game = makeGameWithLocalDeck([], [
        ['mtgo_id' => 8001, 'quantity' => 2, 'sideboard' => false],
    ]);

    $cardsByMtgoId = collect([8001 => $land, 8002 => $lembas]);

    $result = BuildMatchGameData::run($game, 1, $cardsByMtgoId, collect(), [], [
        ['mtgo_id' => 8002, 'name' => 'Lembas', 'cast' => 1, 'played' => 0],
    ]);

    $names = collect($result['opponentCardsSeen'])->pluck('name');
    expect($names)->toContain('Swamp')
        ->and($names)->toContain('Lembas');

    $lembasEntry = collect($result['opponentCardsSeen'])->firstWhere('name', 'Lembas');
    expect($lembasEntry['quantity'])->toBe(1)
        ->and($lembasEntry['type'])->toBe('Artifact');
});

it('does not duplicate a log card already visible under its multi-face parent name', function () {
    // Log records the cast under the face name/id (Petty Theft), the snapshot
    // stores the parent printing (Brazen Borrower // Petty Theft). The rail
    // already shows the parent — do not add a second entry.
    $parent = Card::factory()->create(['mtgo_id' => 9001, 'name' => 'Brazen Borrower // Petty Theft', 'type' => 'Creature']);

    $game = makeGameWithLocalDeck([], [
        ['mtgo_id' => 9001, 'quantity' => 1, 'sideboard' => false],
    ]);

    $cardsByMtgoId = collect([9001 => $parent]);

    $result = BuildMatchGameData::run($game, 1, $cardsByMtgoId, collect(), [], [
        ['mtgo_id' => 9002, 'name' => 'Petty Theft', 'cast' => 1, 'played' => 0],
    ]);

    expect($result['opponentCardsSeen'])->toHaveCount(1)
        ->and($result['opponentCardsSeen'][0]['name'])->toBe('Brazen Borrower // Petty Theft');
});

it('falls back to the log-provided name when the log card id is unknown', function () {
    $game = makeGameWithLocalDeck([], []);

    $result = BuildMatchGameData::run($game, 1, collect(), collect(), [], [
        ['mtgo_id' => 7777, 'name' => 'Once Upon a Time', 'cast' => 1, 'played' => 0],
    ]);

    expect($result['opponentCardsSeen'])->toHaveCount(1)
        ->and($result['opponentCardsSeen'][0]['name'])->toBe('Once Upon a Time');
});

it('compares correctly when registered cards are legacy (oracle_id only, no mtgo_id)', function () {
    // Legacy DeckVersion accessor output: oracle_id present, mtgo_id absent.
    // cardsByOracleId provides the mtgo_id resolution path.
    $card = Card::factory()->create(['mtgo_id' => 1001, 'oracle_id' => 'oracle-1001']);

    $game = makeGameWithLocalDeck([
        ['mtgo_id' => 1001, 'quantity' => 4, 'sideboard' => false],
    ]);

    $registeredCards = [
        ['oracle_id' => 'oracle-1001', 'quantity' => '4', 'sideboard' => 'false'],
    ];

    $cardsByMtgoId = collect([1001 => $card]);
    $cardsByOracleId = collect(['oracle-1001' => $card]);

    $result = BuildMatchGameData::run($game, 1, $cardsByMtgoId, $cardsByOracleId, $registeredCards);

    expect($result['sideboardChanges'])->toBe([]);
});
