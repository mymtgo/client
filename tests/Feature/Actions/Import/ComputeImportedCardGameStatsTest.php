<?php

use App\Actions\Import\ComputeImportedCardGameStats;
use App\Models\Card;
use App\Models\CardGameStat;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates card game stats for an imported game with deck version', function () {
    $cardA = Card::factory()->create(['mtgo_id' => '100', 'oracle_id' => 'oracle-a', 'name' => 'Card A']);
    $cardB = Card::factory()->create(['mtgo_id' => '200', 'oracle_id' => 'oracle-b', 'name' => 'Card B']);

    $deck = Deck::factory()->create();
    $signature = base64_encode('oracle-a:4:false|oracle-b:2:false|oracle-a:1:true');
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $signature,
    ]);

    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'imported' => true,
    ]);

    $game = Game::factory()->create([
        'match_id' => $match->id,
        'won' => true,
    ]);

    // Cards seen in game log for local player
    $seenMtgoIds = [100]; // Only Card A was seen

    ComputeImportedCardGameStats::run($game, $version->id, $seenMtgoIds, isPostboard: false);

    $stats = CardGameStat::where('game_id', $game->id)->get();

    // Should have stats for oracle-a and oracle-b (mainboard cards only)
    expect($stats)->toHaveCount(2);

    $statA = $stats->firstWhere('oracle_id', 'oracle-a');
    expect($statA->quantity)->toBe(4); // mainboard quantity only
    expect($statA->seen)->toBe(1);     // was seen in game log
    expect($statA->kept)->toBe(0);     // always 0 for imports
    expect($statA->won)->toBeTrue();
    expect($statA->is_postboard)->toBeFalse();
    expect($statA->sided_out)->toBeFalse();

    $statB = $stats->firstWhere('oracle_id', 'oracle-b');
    expect($statB->seen)->toBe(0); // was NOT seen in game log
});

it('handles capitalized sideboard values from SyncDecks XML', function () {
    $cardA = Card::factory()->create(['mtgo_id' => '100', 'oracle_id' => 'oracle-a', 'name' => 'Card A']);
    $cardB = Card::factory()->create(['mtgo_id' => '200', 'oracle_id' => 'oracle-b', 'name' => 'Card B']);

    $deck = Deck::factory()->create();
    // SyncDecks stores capitalized "True"/"False" from XML IsSideboard attribute
    $signature = base64_encode('oracle-a:4:False|oracle-b:2:False|oracle-a:1:True');
    $version = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $signature,
    ]);

    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'imported' => true,
    ]);

    $game = Game::factory()->create([
        'match_id' => $match->id,
        'won' => true,
    ]);

    ComputeImportedCardGameStats::run($game, $version->id, [100], isPostboard: false);

    $stats = CardGameStat::where('game_id', $game->id)->get();

    // Must create stats even with capitalized sideboard values
    expect($stats)->toHaveCount(2);

    $statA = $stats->firstWhere('oracle_id', 'oracle-a');
    expect($statA->quantity)->toBe(4);
    expect($statA->seen)->toBe(1);
});

it('skips stats when game result is null', function () {
    $match = MtgoMatch::factory()->create(['imported' => true]);
    $game = Game::factory()->create([
        'match_id' => $match->id,
        'won' => null,
    ]);

    ComputeImportedCardGameStats::run($game, 1, [], isPostboard: false);

    expect(CardGameStat::where('game_id', $game->id)->count())->toBe(0);
});
