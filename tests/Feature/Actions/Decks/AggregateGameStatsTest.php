<?php

use App\Actions\Decks\AggregateGameStats;
use App\Enums\MatchOutcome;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

/**
 * @param  array<int, array{
 *   won: bool,
 *   on_play: bool,
 *   local_mulligans?: int,
 *   opponent_mulligans?: int,
 *   turn_count?: int|null,
 *   started_at?: CarbonInterface|string,
 * }>  $games
 */
function createMatchForGameStats(
    DeckVersion $deckVersion,
    ?Archetype $opponentArchetype,
    MatchOutcome $outcome,
    array $games,
    ?CarbonInterface $startedAt = null,
    ?int $leagueId = null,
): MtgoMatch {
    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $deckVersion->id,
        'outcome' => $outcome,
        'started_at' => $startedAt ?? now(),
        'league_id' => $leagueId,
    ]);

    if ($opponentArchetype) {
        MatchArchetype::create([
            'mtgo_match_id' => $match->id,
            'archetype_id' => $opponentArchetype->id,
            'is_opponent' => true,
            'confidence' => 0.8,
        ]);
    }

    foreach ($games as $i => $gameData) {
        Game::factory()->create([
            'match_id' => $match->id,
            'won' => $gameData['won'],
            'turn_count' => $gameData['turn_count'] ?? null,
            'started_at' => $gameData['started_at'] ?? ($startedAt ?? now())->copy()->addMinutes($i),
            'local_on_play' => $gameData['on_play'],
            'local_mulligans' => $gameData['local_mulligans'] ?? 0,
            'opp_mulligans' => $gameData['opponent_mulligans'] ?? 0,
            'local_instance' => 0,
            'opp_instance' => 1,
        ]);
    }

    return $match;
}

function findRow(Collection $rows, string $group, string $split): ?array
{
    return $rows->first(fn (array $r) => $r['group'] === $group && $r['split'] === $split);
}

it('produces a 12-row breakdown grouped by game number and on-play split', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    createMatchForGameStats($deckVersion, $archetype, MatchOutcome::Win, [
        ['won' => true, 'on_play' => true],
        ['won' => true, 'on_play' => false],
    ]);

    $rows = AggregateGameStats::run($deck, 'alltime', null);

    expect($rows)->toHaveCount(12);

    $groups = ['all_games', 'game_1', 'game_2', 'game_3'];
    $splits = ['overall', 'play', 'draw'];
    foreach ($groups as $g) {
        foreach ($splits as $s) {
            expect(findRow($rows, $g, $s))->not->toBeNull("{$g}/{$s} missing");
        }
    }

    expect(findRow($rows, 'all_games', 'overall'))
        ->toMatchArray(['wins' => 2, 'losses' => 0]);

    expect(findRow($rows, 'game_1', 'overall'))
        ->toMatchArray(['wins' => 1, 'losses' => 0]);

    expect(findRow($rows, 'game_2', 'overall'))
        ->toMatchArray(['wins' => 1, 'losses' => 0]);

    expect(findRow($rows, 'game_3', 'overall'))
        ->toMatchArray(['wins' => 0, 'losses' => 0]);
});

it('partitions wins and losses by on-play vs on-draw', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    // Match 1: play-win, draw-loss
    createMatchForGameStats($deckVersion, $archetype, MatchOutcome::Win, [
        ['won' => true, 'on_play' => true],
        ['won' => false, 'on_play' => false],
    ]);
    // Match 2: draw-win, play-loss
    createMatchForGameStats($deckVersion, $archetype, MatchOutcome::Loss, [
        ['won' => true, 'on_play' => false],
        ['won' => false, 'on_play' => true],
    ]);

    $rows = AggregateGameStats::run($deck, 'alltime', null);

    expect(findRow($rows, 'all_games', 'play'))
        ->toMatchArray(['wins' => 1, 'losses' => 1]);

    expect(findRow($rows, 'all_games', 'draw'))
        ->toMatchArray(['wins' => 1, 'losses' => 1]);

    expect(findRow($rows, 'all_games', 'overall'))
        ->toMatchArray(['wins' => 2, 'losses' => 2]);
});

it('averages local and opponent mulligans per game', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    // 4 games: local mulls 0,1,2,1 → avg 1.00
    //          opp mulls   1,0,2,1 → avg 1.00
    createMatchForGameStats($deckVersion, $archetype, MatchOutcome::Win, [
        ['won' => true, 'on_play' => true, 'local_mulligans' => 0, 'opponent_mulligans' => 1],
        ['won' => true, 'on_play' => false, 'local_mulligans' => 1, 'opponent_mulligans' => 0],
    ]);
    createMatchForGameStats($deckVersion, $archetype, MatchOutcome::Loss, [
        ['won' => false, 'on_play' => true, 'local_mulligans' => 2, 'opponent_mulligans' => 2],
        ['won' => false, 'on_play' => false, 'local_mulligans' => 1, 'opponent_mulligans' => 1],
    ]);

    $rows = AggregateGameStats::run($deck, 'alltime', null);

    expect(findRow($rows, 'all_games', 'overall'))
        ->toMatchArray(['mulligans' => 1.0, 'opponent_mulligans' => 1.0]);
});

it('averages turns excluding null turn counts but still counts wins and losses', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    // 3 games: turn_counts 7, null, 11 → avg over non-null = 9
    createMatchForGameStats($deckVersion, $archetype, MatchOutcome::Win, [
        ['won' => true, 'on_play' => true, 'turn_count' => 7],
        ['won' => true, 'on_play' => false, 'turn_count' => null],
    ]);
    createMatchForGameStats($deckVersion, $archetype, MatchOutcome::Win, [
        ['won' => true, 'on_play' => true, 'turn_count' => 11],
    ]);

    $rows = AggregateGameStats::run($deck, 'alltime', null);

    expect(findRow($rows, 'all_games', 'overall'))
        ->toMatchArray(['wins' => 3, 'losses' => 0, 'turns' => 9.0]);
});

it('returns null averages and win rate when no games match a bucket', function () {
    $deck = Deck::factory()->create();
    DeckVersion::factory()->create(['deck_id' => $deck->id]);

    $rows = AggregateGameStats::run($deck, 'alltime', null);

    $row = findRow($rows, 'all_games', 'overall');
    expect($row)->toMatchArray([
        'wins' => 0,
        'losses' => 0,
        'win_rate' => null,
        'mulligans' => null,
        'opponent_mulligans' => null,
        'turns' => null,
    ]);
});

it('computes win_rate as a percentage with one decimal', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    // 2 wins, 1 loss → 66.7%
    createMatchForGameStats($deckVersion, $archetype, MatchOutcome::Win, [
        ['won' => true, 'on_play' => true],
        ['won' => true, 'on_play' => false],
        ['won' => false, 'on_play' => true],
    ]);

    $rows = AggregateGameStats::run($deck, 'alltime', null);

    expect(findRow($rows, 'all_games', 'overall'))
        ->toMatchArray(['win_rate' => 66.7]);
});

it('excludes matches outside the requested timeframe', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    createMatchForGameStats(
        $deckVersion,
        $archetype,
        MatchOutcome::Win,
        [['won' => true, 'on_play' => true]],
        startedAt: now()->subDays(2),
    );
    createMatchForGameStats(
        $deckVersion,
        $archetype,
        MatchOutcome::Loss,
        [['won' => false, 'on_play' => false]],
        startedAt: now()->subMonths(6),
    );

    $rows = AggregateGameStats::run($deck, 'week', null);

    expect(findRow($rows, 'all_games', 'overall'))
        ->toMatchArray(['wins' => 1, 'losses' => 0]);
});

it('filters by opponent archetype uuid when provided', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetypeA = Archetype::factory()->create();
    $archetypeB = Archetype::factory()->create();

    createMatchForGameStats(
        $deckVersion,
        $archetypeA,
        MatchOutcome::Win,
        [['won' => true, 'on_play' => true]],
    );
    createMatchForGameStats(
        $deckVersion,
        $archetypeB,
        MatchOutcome::Loss,
        [['won' => false, 'on_play' => false]],
    );

    $rows = AggregateGameStats::run($deck, 'alltime', $archetypeA->uuid);

    expect(findRow($rows, 'all_games', 'overall'))
        ->toMatchArray(['wins' => 1, 'losses' => 0]);
});

it('includes games beyond game 3 in all_games but not in the game_1/2/3 rows', function () {
    $deck = Deck::factory()->create();
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $archetype = Archetype::factory()->create();

    // 4-game match (rare but possible for weird formats) — the 4th game should
    // still count in "All Games" but not appear in game_3.
    createMatchForGameStats($deckVersion, $archetype, MatchOutcome::Win, [
        ['won' => true, 'on_play' => true],   // game 1
        ['won' => false, 'on_play' => false], // game 2
        ['won' => true, 'on_play' => true],   // game 3
        ['won' => true, 'on_play' => false],  // game 4
    ]);

    $rows = AggregateGameStats::run($deck, 'alltime', null);

    expect(findRow($rows, 'all_games', 'overall'))
        ->toMatchArray(['wins' => 3, 'losses' => 1]);

    expect(findRow($rows, 'game_3', 'overall'))
        ->toMatchArray(['wins' => 1, 'losses' => 0]);
});
