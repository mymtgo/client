<?php

use App\Actions\Decks\GetArchetypeMatchupDetail;
use App\Models\Archetype;
use App\Models\CardGameStat;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create a match with games, game_player pivots, and optional card stats for detail testing.
 *
 * @param  array<int, array{won: bool, on_play: bool, turn_count: int|null, mulligan_count: int|null}>  $games
 * @param  array<int, array{oracle_id: string, kept: int, seen: int}>  $cardSeenResults
 */
function createDetailMatch(
    DeckVersion $deckVersion,
    Archetype $archetype,
    string $outcome,
    array $games,
    array $cardSeenResults = [],
    ?string $startedAt = null,
): MtgoMatch {
    $localPlayer = Player::firstOrCreate(['username' => 'local_player']);
    $opponentPlayer = Player::firstOrCreate(['username' => 'opponent_player']);

    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $deckVersion->id,
        'outcome' => $outcome,
        'started_at' => $startedAt ? Carbon::parse($startedAt) : now(),
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
            'started_at' => $startedAt ? Carbon::parse($startedAt)->addMinutes(rand(0, 10)) : now(),
        ]);

        $game->players()->attach($localPlayer->id, [
            'is_local' => true,
            'on_play' => $gameData['on_play'],
            'instance_id' => fake()->randomNumber(6),
            'mulligan_count' => $gameData['mulligan_count'] ?? 0,
        ]);

        $game->players()->attach($opponentPlayer->id, [
            'is_local' => false,
            'on_play' => ! $gameData['on_play'],
            'instance_id' => fake()->randomNumber(6),
            'mulligan_count' => 0,
        ]);

        foreach ($cardSeenResults as $cardData) {
            if (isset($cardData['game_index']) && $cardData['game_index'] !== array_search($gameData, $games)) {
                continue;
            }

            CardGameStat::create([
                'oracle_id' => $cardData['oracle_id'],
                'game_id' => $game->id,
                'deck_version_id' => $deckVersion->id,
                'quantity' => 4,
                'kept' => $cardData['kept'] ?? 0,
                'seen' => $cardData['seen'] ?? 0,
                'cast' => 0,
                'won' => $gameData['won'],
                'is_postboard' => false,
                'sided_out' => false,
                'sided_in' => false,
            ]);
        }
    }

    return $match;
}

it('returns win rates and records', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    // Match 1: Win 2-1
    createDetailMatch($deckVersion, $archetype, 'win', [
        ['won' => true, 'on_play' => true, 'turn_count' => null, 'mulligan_count' => 0],
        ['won' => false, 'on_play' => false, 'turn_count' => null, 'mulligan_count' => 0],
        ['won' => true, 'on_play' => true, 'turn_count' => null, 'mulligan_count' => 0],
    ]);

    // Match 2: Loss 0-2
    createDetailMatch($deckVersion, $archetype, 'loss', [
        ['won' => false, 'on_play' => true, 'turn_count' => null, 'mulligan_count' => 0],
        ['won' => false, 'on_play' => false, 'turn_count' => null, 'mulligan_count' => 0],
    ]);

    $result = GetArchetypeMatchupDetail::run($deck, $archetype, null, null);

    expect($result['matchWinrate'])->toBe(50)
        ->and($result['matchRecord'])->toBe('1 - 1')
        ->and($result['matches'])->toBe(2)
        ->and($result['gameWinrate'])->toBe(40)
        ->and($result['gameRecord'])->toBe('2 - 3');
});

it('returns play/draw breakdown', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    // Match: 2-1, 2 games on play (both won), 1 on draw (lost)
    createDetailMatch($deckVersion, $archetype, 'win', [
        ['won' => true, 'on_play' => true, 'turn_count' => null, 'mulligan_count' => 0],
        ['won' => true, 'on_play' => true, 'turn_count' => null, 'mulligan_count' => 0],
        ['won' => false, 'on_play' => false, 'turn_count' => null, 'mulligan_count' => 0],
    ]);

    $result = GetArchetypeMatchupDetail::run($deck, $archetype, null, null);

    expect($result['otpWinrate'])->toBe(100)
        ->and($result['otpRecord'])->toBe('2 - 0')
        ->and($result['otdWinrate'])->toBe(0)
        ->and($result['otdRecord'])->toBe('0 - 1');
});

it('returns game stats', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    // 1 match, 2 games: turn_count 6 and 10, mulligan_count 1 and 0, one on play one on draw
    createDetailMatch($deckVersion, $archetype, 'win', [
        ['won' => true, 'on_play' => true, 'turn_count' => 6, 'mulligan_count' => 1],
        ['won' => true, 'on_play' => false, 'turn_count' => 10, 'mulligan_count' => 0],
    ]);

    $result = GetArchetypeMatchupDetail::run($deck, $archetype, null, null);

    expect($result['avgTurns'])->toBe(8.0)
        ->and($result['avgMulligans'])->toBe(0.5)
        ->and($result['onPlayRate'])->toBe(50);
});

it('returns match history in descending date order', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    // Older match
    createDetailMatch($deckVersion, $archetype, 'loss', [
        ['won' => false, 'on_play' => true, 'turn_count' => null, 'mulligan_count' => 0],
        ['won' => false, 'on_play' => false, 'turn_count' => null, 'mulligan_count' => 0],
    ], startedAt: '2026-03-01 12:00:00');

    // Newer match
    createDetailMatch($deckVersion, $archetype, 'win', [
        ['won' => true, 'on_play' => true, 'turn_count' => null, 'mulligan_count' => 0],
        ['won' => true, 'on_play' => false, 'turn_count' => null, 'mulligan_count' => 0],
    ], startedAt: '2026-03-15 12:00:00');

    $result = GetArchetypeMatchupDetail::run($deck, $archetype, null, null);

    expect($result['matchHistory'])->toHaveCount(2)
        ->and($result['matchHistory'][0]['outcome'])->toBe('win')
        ->and($result['matchHistory'][0]['score'])->toBe('2-0')
        ->and($result['matchHistory'][1]['outcome'])->toBe('loss')
        ->and($result['matchHistory'][1]['score'])->toBe('0-2')
        ->and($result['matchHistory'][0]['gameResults'])->toBe([true, true])
        ->and($result['matchHistory'][1]['gameResults'])->toBe([false, false]);
});

it('returns empty card arrays', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    createDetailMatch($deckVersion, $archetype, 'win', [
        ['won' => true, 'on_play' => true, 'turn_count' => null, 'mulligan_count' => 0],
    ]);

    $result = GetArchetypeMatchupDetail::run($deck, $archetype, null, null);

    expect($result['bestCards'])->toBe([])
        ->and($result['worstCards'])->toBe([]);
});
