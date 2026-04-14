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
    $cardStats = [['mtgo_id' => 100, 'cast' => 2]]; // Only Card A was seen

    ComputeImportedCardGameStats::run($game, $version->id, $cardStats, isPostboard: false);

    $stats = CardGameStat::where('game_id', $game->id)->get();

    // Should have stats for oracle-a and oracle-b (mainboard cards only)
    expect($stats)->toHaveCount(2);

    $statA = $stats->firstWhere('oracle_id', 'oracle-a');
    expect($statA->quantity)->toBe(4); // mainboard quantity only
    expect($statA->seen)->toBe(1);     // was seen in game log
    expect($statA->kept)->toBe(0);     // always 0 for imports
    expect($statA->cast)->toBe(2);
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

    ComputeImportedCardGameStats::run($game, $version->id, [['mtgo_id' => 100, 'cast' => 0]], isPostboard: false);

    $stats = CardGameStat::where('game_id', $game->id)->get();

    // Must create stats even with capitalized sideboard values
    expect($stats)->toHaveCount(2);

    $statA = $stats->firstWhere('oracle_id', 'oracle-a');
    expect($statA->quantity)->toBe(4);
    expect($statA->seen)->toBe(1);
});

it('does not cap cast count at deck quantity', function () {
    $card = Card::factory()->create(['mtgo_id' => '100', 'oracle_id' => 'oracle-a', 'name' => 'Card A']);

    $deck = Deck::factory()->create();
    $signature = base64_encode('oracle-a:2:false');
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

    // Card was cast 5 times in one game (bounced/flickered/recast)
    // but only 2 copies in deck — cast count should NOT be capped
    $cardStats = [['mtgo_id' => 100, 'cast' => 5]];

    ComputeImportedCardGameStats::run($game, $version->id, $cardStats, isPostboard: false);

    $stat = CardGameStat::where('game_id', $game->id)->where('oracle_id', 'oracle-a')->first();
    expect($stat->cast)->toBe(5);
});

it('writes played kicked flashback madness evoked activated columns', function () {
    $card = Card::factory()->create(['mtgo_id' => '100', 'oracle_id' => 'oracle-a', 'name' => 'Card A']);

    $deck = Deck::factory()->create();
    $signature = base64_encode('oracle-a:4:false');
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

    $cardStats = [[
        'mtgo_id' => 100,
        'cast' => 2,
        'played' => 0,
        'kicked' => 1,
        'flashback' => 0,
        'madness' => 0,
        'evoked' => 0,
        'activated' => 3,
    ]];

    ComputeImportedCardGameStats::run($game, $version->id, $cardStats, isPostboard: false);

    $stat = CardGameStat::where('game_id', $game->id)->where('oracle_id', 'oracle-a')->first();

    expect($stat->cast)->toBe(2);
    expect($stat->played)->toBe(0);
    expect($stat->kicked)->toBe(1);
    expect($stat->flashback)->toBe(0);
    expect($stat->madness)->toBe(0);
    expect($stat->evoked)->toBe(0);
    expect($stat->activated)->toBe(3);
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
