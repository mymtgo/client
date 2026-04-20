<?php

use App\Actions\Decks\GetStandoutCards;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $attributes
 */
function insertStandoutStat(array $attributes): void
{
    DB::table('card_game_stats')->insert(array_merge([
        'is_postboard' => false,
        'quantity' => 4,
        'kept' => 0,
        'seen' => 0,
        'cast' => 0,
        'played' => 0,
        'won' => true,
        'sided_out' => false,
    ], $attributes));
}

function standoutSetup(): array
{
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->for($deck)->create();
    $match = MtgoMatch::factory()->create(['deck_version_id' => $version->id]);

    return [$deck, $version, $match];
}

it('ranks top performer by cast win rate using castGames denominator', function () {
    [$deck, $version, $match] = standoutSetup();

    // "Noisy" card: cast many times, won only half of games cast in.
    // totalCast is high (bouncing or flashback), castGames is lower, actual win rate is mediocre.
    Card::factory()->create(['oracle_id' => 'noisy', 'name' => 'Noisy Card', 'type' => 'Instant', 'image' => null]);
    // 20 games cast, 10 won → 50% cast win rate
    // but totalCast = 60 (cast 3 times per game on average)
    for ($i = 0; $i < 20; $i++) {
        $game = Game::factory()->for($match, 'match')->create(['won' => $i < 10]);
        insertStandoutStat([
            'deck_version_id' => $version->id,
            'game_id' => $game->id,
            'oracle_id' => 'noisy',
            'cast' => 3, // cast multiple times per game
            'won' => $i < 10,
        ]);
    }

    // "Solid" card: cast in 25 games, won 20 of them → 80% cast win rate
    Card::factory()->create(['oracle_id' => 'solid', 'name' => 'Solid Card', 'type' => 'Instant', 'image' => null]);
    for ($i = 0; $i < 25; $i++) {
        $game = Game::factory()->for($match, 'match')->create(['won' => $i < 20]);
        insertStandoutStat([
            'deck_version_id' => $version->id,
            'game_id' => $game->id,
            'oracle_id' => 'solid',
            'cast' => 1,
            'won' => $i < 20,
        ]);
    }

    $result = GetStandoutCards::run($deck);

    expect($result['topPerformer']['name'])->toBe('Solid Card');
    expect($result['topPerformer']['stat'])->toBe('80% cast win rate');
});

it('excludes cards under the 20-game minimum from top performer', function () {
    [$deck, $version, $match] = standoutSetup();

    // Tiny sample, great rate — should be excluded.
    Card::factory()->create(['oracle_id' => 'hot-streak', 'name' => 'Hot Streak', 'type' => 'Instant', 'image' => null]);
    for ($i = 0; $i < 5; $i++) {
        $game = Game::factory()->for($match, 'match')->create(['won' => true]);
        insertStandoutStat([
            'deck_version_id' => $version->id,
            'game_id' => $game->id,
            'oracle_id' => 'hot-streak',
            'cast' => 1,
            'won' => true,
        ]);
    }

    // Big sample, OK rate — should win.
    Card::factory()->create(['oracle_id' => 'reliable', 'name' => 'Reliable', 'type' => 'Instant', 'image' => null]);
    for ($i = 0; $i < 30; $i++) {
        $game = Game::factory()->for($match, 'match')->create(['won' => $i < 18]);
        insertStandoutStat([
            'deck_version_id' => $version->id,
            'game_id' => $game->id,
            'oracle_id' => 'reliable',
            'cast' => 1,
            'won' => $i < 18,
        ]);
    }

    $result = GetStandoutCards::run($deck);

    expect($result['topPerformer']['name'])->toBe('Reliable');
});

it('ranks most cast by castGames and labels it accordingly', function () {
    [$deck, $version, $match] = standoutSetup();

    // Card A: cast in 5 games, each cast 4 times (totalCast=20)
    Card::factory()->create(['oracle_id' => 'rare-but-spammy', 'name' => 'Rare But Spammy', 'type' => 'Instant', 'image' => null]);
    for ($i = 0; $i < 5; $i++) {
        $game = Game::factory()->for($match, 'match')->create(['won' => true]);
        insertStandoutStat([
            'deck_version_id' => $version->id,
            'game_id' => $game->id,
            'oracle_id' => 'rare-but-spammy',
            'cast' => 4,
        ]);
    }

    // Card B: cast in 10 games, once each (totalCast=10) — but in MORE games
    Card::factory()->create(['oracle_id' => 'frequent', 'name' => 'Frequent', 'type' => 'Instant', 'image' => null]);
    for ($i = 0; $i < 10; $i++) {
        $game = Game::factory()->for($match, 'match')->create(['won' => true]);
        insertStandoutStat([
            'deck_version_id' => $version->id,
            'game_id' => $game->id,
            'oracle_id' => 'frequent',
            'cast' => 1,
        ]);
    }

    $result = GetStandoutCards::run($deck);

    expect($result['mostCast']['name'])->toBe('Frequent');
    expect($result['mostCast']['stat'])->toBe('Cast in 10 of 10 games');
});

it('ranks most played land by playedGames not seenGames', function () {
    [$deck, $version, $match] = standoutSetup();

    // Mountain: seen often (cycled) but rarely played
    Card::factory()->create(['oracle_id' => 'mountain', 'name' => 'Mountain', 'type' => 'Basic Land', 'image' => null]);
    for ($i = 0; $i < 10; $i++) {
        $game = Game::factory()->for($match, 'match')->create(['won' => true]);
        insertStandoutStat([
            'deck_version_id' => $version->id,
            'game_id' => $game->id,
            'oracle_id' => 'mountain',
            'seen' => 2,
            'played' => $i < 2 ? 1 : 0, // played in only 2 games
        ]);
    }

    // Forest: actually played most games
    Card::factory()->create(['oracle_id' => 'forest', 'name' => 'Forest', 'type' => 'Basic Land', 'image' => null]);
    for ($i = 0; $i < 8; $i++) {
        $game = Game::factory()->for($match, 'match')->create(['won' => true]);
        insertStandoutStat([
            'deck_version_id' => $version->id,
            'game_id' => $game->id,
            'oracle_id' => 'forest',
            'seen' => 1,
            'played' => 1, // played in every game
        ]);
    }

    $result = GetStandoutCards::run($deck);

    expect($result['mostPlayedLand']['name'])->toBe('Forest');
    expect($result['mostPlayedLand']['stat'])->toBe('Played in 8 of 8 games');
});

it('labels most seen with seenGames over totalGames', function () {
    [$deck, $version, $match] = standoutSetup();

    Card::factory()->create(['oracle_id' => 'visible', 'name' => 'Visible', 'type' => 'Creature', 'image' => null]);
    for ($i = 0; $i < 7; $i++) {
        $game = Game::factory()->for($match, 'match')->create(['won' => true]);
        insertStandoutStat([
            'deck_version_id' => $version->id,
            'game_id' => $game->id,
            'oracle_id' => 'visible',
            'seen' => 2,
        ]);
    }

    $result = GetStandoutCards::run($deck);

    expect($result['mostSeen']['name'])->toBe('Visible');
    expect($result['mostSeen']['stat'])->toBe('Seen in 7 of 7 games');
});
