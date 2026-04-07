<?php

use App\Actions\Decks\GetArchetypeMatchupSpread;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create a match with games and game_player pivots for testing.
 *
 * @param  array<int, array{won: bool, on_play: bool, turn_count: int|null}>  $games
 */
function createMatchWithGamesForSpread(
    DeckVersion $deckVersion,
    Archetype $archetype,
    string $outcome,
    array $games,
): MtgoMatch {
    $localPlayer = Player::firstOrCreate(['username' => 'local_player']);
    $opponentPlayer = Player::firstOrCreate(['username' => 'opponent_player']);

    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $deckVersion->id,
        'outcome' => $outcome,
    ]);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $archetype->id,
        'player_id' => $opponentPlayer->id,
    ]);

    foreach ($games as $gameData) {
        $game = Game::factory()->create([
            'match_id' => $match->id,
            'won' => $gameData['won'],
            'turn_count' => $gameData['turn_count'] ?? null,
        ]);

        $game->players()->attach($localPlayer->id, [
            'is_local' => true,
            'on_play' => $gameData['on_play'],
            'instance_id' => fake()->randomNumber(6),
        ]);

        $game->players()->attach($opponentPlayer->id, [
            'is_local' => false,
            'on_play' => ! $gameData['on_play'],
            'instance_id' => fake()->randomNumber(6),
        ]);
    }

    return $match;
}

it('returns existing fields for a matchup', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    createMatchWithGamesForSpread($deckVersion, $archetype, 'win', [
        ['won' => true, 'on_play' => true, 'turn_count' => 8],
        ['won' => true, 'on_play' => false, 'turn_count' => 10],
    ]);

    $result = GetArchetypeMatchupSpread::run($deck, null, null);

    expect($result)->toHaveCount(1);

    $matchup = $result->first();

    expect($matchup)
        ->toHaveKeys([
            'archetype_id', 'name', 'color_identity',
            'match_winrate', 'game_winrate', 'matches',
            'match_record', 'game_record',
            'match_wins', 'match_losses',
            'games_won', 'games_lost', 'total_games',
        ])
        ->and($matchup['matches'])->toBe(1)
        ->and($matchup['match_wins'])->toBe(1)
        ->and($matchup['games_won'])->toBe(2)
        ->and($matchup['total_games'])->toBe(2);
});

it('computes otp_winrate as on-the-play game win rate', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    // Match 1: 2 games on play, both won
    createMatchWithGamesForSpread($deckVersion, $archetype, 'win', [
        ['won' => true, 'on_play' => true, 'turn_count' => 8],
        ['won' => true, 'on_play' => true, 'turn_count' => 10],
    ]);

    // Match 2: 1 game on play, lost
    createMatchWithGamesForSpread($deckVersion, $archetype, 'loss', [
        ['won' => false, 'on_play' => true, 'turn_count' => 6],
    ]);

    $result = GetArchetypeMatchupSpread::run($deck, null, null);
    $matchup = $result->first();

    // 2 wins out of 3 on-play games = 67%
    expect($matchup['otp_winrate'])->toBe(67);
});

it('computes avg_turns across all games', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    createMatchWithGamesForSpread($deckVersion, $archetype, 'win', [
        ['won' => true, 'on_play' => true, 'turn_count' => 6],
        ['won' => true, 'on_play' => false, 'turn_count' => 10],
    ]);

    $result = GetArchetypeMatchupSpread::run($deck, null, null);
    $matchup = $result->first();

    // (6 + 10) / 2 = 8.0
    expect($matchup['avg_turns'])->toBe(8.0);
});

it('returns null avg_turns when no turn data exists', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    createMatchWithGamesForSpread($deckVersion, $archetype, 'win', [
        ['won' => true, 'on_play' => true, 'turn_count' => null],
        ['won' => true, 'on_play' => false, 'turn_count' => null],
    ]);

    $result = GetArchetypeMatchupSpread::run($deck, null, null);
    $matchup = $result->first();

    expect($matchup['avg_turns'])->toBeNull();
});
