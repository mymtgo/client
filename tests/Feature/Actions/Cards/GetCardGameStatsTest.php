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
        'opponent' => false,
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

it('counts games where an event occurred separately from total copies', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->for($deck)->create();

    $card = Card::factory()->create([
        'oracle_id' => 'test-oracle-games',
        'name' => 'Swamp',
        'type' => 'Basic Land',
        'color_identity' => 'B',
        'image' => null,
    ]);

    $match = MtgoMatch::factory()->create(['deck_version_id' => $version->id]);

    // Game 1: 9 swamps in deck, 4 played, 3 seen, 2 kept, 0 cast
    $game1 = Game::factory()->for($match, 'match')->create(['won' => true]);
    insertCardGameStat([
        'deck_version_id' => $version->id,
        'game_id' => $game1->id,
        'oracle_id' => $card->oracle_id,
        'quantity' => 9,
        'played' => 4,
        'seen' => 3,
        'kept' => 2,
        'cast' => 0,
        'won' => true,
    ]);

    // Game 2: 9 swamps in deck, nothing played/seen/kept/cast (edge case)
    $game2 = Game::factory()->for($match, 'match')->create(['won' => false]);
    insertCardGameStat([
        'deck_version_id' => $version->id,
        'game_id' => $game2->id,
        'oracle_id' => $card->oracle_id,
        'quantity' => 9,
        'played' => 0,
        'seen' => 0,
        'kept' => 0,
        'cast' => 0,
        'won' => false,
    ]);

    // Game 3: 9 swamps, 2 played, 1 seen, 0 kept — partial
    $game3 = Game::factory()->for($match, 'match')->create(['won' => true]);
    insertCardGameStat([
        'deck_version_id' => $version->id,
        'game_id' => $game3->id,
        'oracle_id' => $card->oracle_id,
        'quantity' => 9,
        'played' => 2,
        'seen' => 1,
        'kept' => 0,
        'cast' => 0,
        'won' => true,
    ]);

    $row = GetCardGameStats::run($deck)->first();

    // Raw copy counts across all games (existing behaviour)
    expect($row['totalGames'])->toBe(3);
    expect($row['totalPossible'])->toBe(27); // 9 × 3 games
    expect($row['totalPlayed'])->toBe(6);    // 4 + 0 + 2
    expect($row['totalSeen'])->toBe(4);      // 3 + 0 + 1
    expect($row['totalKept'])->toBe(2);      // 2 + 0 + 0

    // Game counts — games where the event happened at least once
    expect($row['playedGames'])->toBe(2); // game1 + game3
    expect($row['seenGames'])->toBe(2);   // game1 + game3
    expect($row['keptGames'])->toBe(1);   // game1 only
    expect($row['castGames'])->toBe(0);   // never cast
});

it('aggregates pregame revealed and played counts per card', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->for($deck)->create();

    $card = Card::factory()->create([
        'oracle_id' => 'test-oracle-pregame',
        'name' => 'Devourer of Destiny',
        'type' => 'Creature',
        'color_identity' => 'B',
        'image' => null,
    ]);

    $match = MtgoMatch::factory()->create(['deck_version_id' => $version->id]);

    $game1 = Game::factory()->for($match, 'match')->create(['won' => true]);
    insertCardGameStat([
        'deck_version_id' => $version->id,
        'game_id' => $game1->id,
        'oracle_id' => $card->oracle_id,
        'pregame_revealed' => true,
        'pregame_played' => false,
        'won' => true,
    ]);

    $game2 = Game::factory()->for($match, 'match')->create(['won' => false]);
    insertCardGameStat([
        'deck_version_id' => $version->id,
        'game_id' => $game2->id,
        'oracle_id' => $card->oracle_id,
        'pregame_revealed' => true,
        'pregame_played' => true,
        'won' => false,
    ]);

    $game3 = Game::factory()->for($match, 'match')->create(['won' => true]);
    insertCardGameStat([
        'deck_version_id' => $version->id,
        'game_id' => $game3->id,
        'oracle_id' => $card->oracle_id,
        'pregame_revealed' => false,
        'pregame_played' => false,
        'won' => true,
    ]);

    $row = GetCardGameStats::run($deck)->first();

    expect($row['totalGames'])->toBe(3);
    expect($row['pregameRevealedGames'])->toBe(2);
    expect($row['pregamePlayedGames'])->toBe(1);
    // Games where any pregame event fired (game1: revealed, game2: both, game3: neither)
    expect($row['pregameGames'])->toBe(2);
    expect($row['pregameWon'])->toBe(1);  // game1 won
    expect($row['pregameLost'])->toBe(1); // game2 lost
});

it('does not blow up when a sideboard card has no oracle_id mapping', function () {
    $deck = Deck::factory()->create();

    // New-format signature (numeric MTGO IDs): mainboard 1234, sideboard 5678
    $version = DeckVersion::factory()->for($deck)->create([
        'signature' => base64_encode('1234:4:false|5678:1:true'),
    ]);

    // Mainboard card is mapped — sideboard MTGO id 5678 deliberately has no Card row,
    // so DeckVersion::getCardsAttribute() emits oracle_id => null for it.
    Card::factory()->create([
        'mtgo_id' => 1234,
        'oracle_id' => 'mainboard-oracle',
        'name' => 'Mainboard Card',
        'type' => 'Instant',
        'color_identity' => 'R',
        'image' => null,
    ]);

    $match = MtgoMatch::factory()->create(['deck_version_id' => $version->id]);
    $game = Game::factory()->for($match, 'match')->create(['won' => true]);

    insertCardGameStat([
        'deck_version_id' => $version->id,
        'game_id' => $game->id,
        'oracle_id' => 'mainboard-oracle',
    ]);

    $results = GetCardGameStats::run($deck);

    expect($results)->toHaveCount(1);
    expect($results->first()['oracleId'])->toBe('mainboard-oracle');
    expect($results->first()['isSideboard'])->toBeFalse();
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

it('returns only local rows by default and only opponent rows when opponent flag is true', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->for($deck)->create();

    $localCard = Card::factory()->create(['oracle_id' => 'oracle-local', 'name' => 'Forest', 'type' => 'Land', 'color_identity' => 'G', 'image' => null]);
    $oppCard = Card::factory()->create(['oracle_id' => 'oracle-opp', 'name' => 'Bolt', 'type' => 'Instant', 'color_identity' => 'R', 'image' => null]);

    $match = MtgoMatch::factory()->create(['deck_version_id' => $version->id]);
    $game = Game::factory()->for($match, 'match')->create(['won' => false]);

    insertCardGameStat([
        'deck_version_id' => $version->id,
        'game_id' => $game->id,
        'oracle_id' => $localCard->oracle_id,
        'opponent' => false,
        'won' => false,
    ]);

    insertCardGameStat([
        'deck_version_id' => $version->id,
        'game_id' => $game->id,
        'oracle_id' => $oppCard->oracle_id,
        'opponent' => true,
        'quantity' => 0,
        'kept' => 0,
        'seen' => 1,
        'cast' => 1,
        'won' => false,
    ]);

    $mine = GetCardGameStats::run($deck);
    expect($mine)->toHaveCount(1);
    expect($mine->first()['oracleId'])->toBe('oracle-local');

    $theirs = GetCardGameStats::run($deck, opponent: true);
    expect($theirs)->toHaveCount(1);
    expect($theirs->first()['oracleId'])->toBe('oracle-opp');
    expect($theirs->first()['isSideboard'])->toBeFalse();
});
