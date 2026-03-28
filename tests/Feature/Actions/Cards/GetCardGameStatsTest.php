<?php

use App\Actions\Cards\GetCardGameStats;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Insert a card_game_stats row directly via DB.
 *
 * @param  array<string, mixed>  $attributes
 */
function insertCardGameStat(array $attributes): void
{
    DB::table('card_game_stats')->insert(array_merge([
        'is_postboard' => false,
        'quantity' => 4,
        'kept' => 2,
        'seen' => 3,
        'won' => true,
        'sided_out' => false,
    ], $attributes));
}

it('aggregates card stats across all deck versions when no version specified', function () {
    $deck = Deck::factory()->create();
    $v1 = DeckVersion::factory()->for($deck)->create();
    $v2 = DeckVersion::factory()->for($deck)->create();

    $card = Card::factory()->create(['oracle_id' => 'test-oracle-1', 'name' => 'Lightning Bolt', 'type' => 'Instant', 'color_identity' => 'R', 'image' => null]);

    $match1 = MtgoMatch::factory()->create(['deck_version_id' => $v1->id]);
    $game1 = Game::factory()->for($match1, 'match')->create(['won' => true]);

    $match2 = MtgoMatch::factory()->create(['deck_version_id' => $v2->id]);
    $game2 = Game::factory()->for($match2, 'match')->create(['won' => false]);

    insertCardGameStat(['deck_version_id' => $v1->id, 'game_id' => $game1->id, 'oracle_id' => $card->oracle_id, 'kept' => 3, 'won' => true]);
    insertCardGameStat(['deck_version_id' => $v2->id, 'game_id' => $game2->id, 'oracle_id' => $card->oracle_id, 'kept' => 1, 'won' => false]);

    $results = GetCardGameStats::run($deck);

    expect($results)->toHaveCount(1);
    $row = $results->first();
    expect($row['totalGames'])->toBe(2);
    expect($row['totalKept'])->toBe(4); // 3 + 1
    expect($row['oracleId'])->toBe('test-oracle-1');
});

it('filters card stats to a single version when version specified', function () {
    $deck = Deck::factory()->create();
    $v1 = DeckVersion::factory()->for($deck)->create();
    $v2 = DeckVersion::factory()->for($deck)->create();

    $card = Card::factory()->create(['oracle_id' => 'test-oracle-2', 'name' => 'Counterspell', 'type' => 'Instant', 'color_identity' => 'U', 'image' => null]);

    $match1 = MtgoMatch::factory()->create(['deck_version_id' => $v1->id]);
    $game1 = Game::factory()->for($match1, 'match')->create(['won' => true]);

    $match2 = MtgoMatch::factory()->create(['deck_version_id' => $v2->id]);
    $game2 = Game::factory()->for($match2, 'match')->create(['won' => false]);

    insertCardGameStat(['deck_version_id' => $v1->id, 'game_id' => $game1->id, 'oracle_id' => $card->oracle_id, 'kept' => 2, 'won' => true]);
    insertCardGameStat(['deck_version_id' => $v2->id, 'game_id' => $game2->id, 'oracle_id' => $card->oracle_id, 'kept' => 4, 'won' => false]);

    $results = GetCardGameStats::run($deck, $v1);

    expect($results)->toHaveCount(1);
    $row = $results->first();
    expect($row['totalGames'])->toBe(1);
    expect($row['totalKept'])->toBe(2); // only v1
});

it('filters by on_play', function () {
    $deck = Deck::factory()->create();
    $v1 = DeckVersion::factory()->for($deck)->create();

    $card = Card::factory()->create(['oracle_id' => 'test-oracle-3', 'name' => 'Dark Ritual', 'type' => 'Instant', 'color_identity' => 'B', 'image' => null]);

    $localPlayer = Player::create(['username' => 'localuser']);
    $opponent = Player::create(['username' => 'opponent']);

    $match = MtgoMatch::factory()->create(['deck_version_id' => $v1->id]);

    $gameOnPlay = Game::factory()->for($match, 'match')->create(['won' => true]);
    $gameOnPlay->players()->attach($localPlayer->id, ['instance_id' => 1, 'is_local' => true, 'on_play' => true, 'starting_hand_size' => 7]);
    $gameOnPlay->players()->attach($opponent->id, ['instance_id' => 2, 'is_local' => false, 'on_play' => false, 'starting_hand_size' => 7]);

    $gameOnDraw = Game::factory()->for($match, 'match')->create(['won' => false]);
    $gameOnDraw->players()->attach($localPlayer->id, ['instance_id' => 3, 'is_local' => true, 'on_play' => false, 'starting_hand_size' => 7]);
    $gameOnDraw->players()->attach($opponent->id, ['instance_id' => 4, 'is_local' => false, 'on_play' => true, 'starting_hand_size' => 7]);

    insertCardGameStat(['deck_version_id' => $v1->id, 'game_id' => $gameOnPlay->id, 'oracle_id' => $card->oracle_id, 'kept' => 2, 'won' => true]);
    insertCardGameStat(['deck_version_id' => $v1->id, 'game_id' => $gameOnDraw->id, 'oracle_id' => $card->oracle_id, 'kept' => 1, 'won' => false]);

    $onPlayResults = GetCardGameStats::run($deck, $v1, onPlay: true);
    expect($onPlayResults)->toHaveCount(1);
    expect($onPlayResults->first()['totalGames'])->toBe(1);
    expect($onPlayResults->first()['totalKept'])->toBe(2);

    $onDrawResults = GetCardGameStats::run($deck, $v1, onPlay: false);
    expect($onDrawResults)->toHaveCount(1);
    expect($onDrawResults->first()['totalGames'])->toBe(1);
    expect($onDrawResults->first()['totalKept'])->toBe(1);
});
