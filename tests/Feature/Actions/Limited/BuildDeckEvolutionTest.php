<?php

use App\Actions\Limited\Read\BuildDeckEvolution;
use App\Enums\LeagueKind;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\League;
use App\Models\LimitedDeckSnapshot;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function limitedDeckFixture(): League
{
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()->subHour()]);
    $draft = Draft::factory()->finished()->create(['league_id' => $league->id]);
    Card::factory()->create(['mtgo_id' => '1', 'name' => 'Bard', 'colors' => 'W', 'type' => 'Creature']);
    Card::factory()->create(['mtgo_id' => '2', 'name' => 'Harper', 'colors' => 'U', 'type' => 'Creature']);
    Card::factory()->create(['mtgo_id' => '3', 'name' => 'Grasp', 'colors' => 'U', 'type' => 'Instant']);
    Card::factory()->create(['mtgo_id' => '4', 'name' => 'Flock', 'colors' => 'U', 'type' => 'Creature']);
    Card::factory()->create(['mtgo_id' => '5', 'name' => 'Glamdring', 'colors' => '', 'type' => 'Artifact']);
    Card::factory()->create(['mtgo_id' => '9', 'name' => 'Island', 'colors' => '', 'type' => 'Basic Land']);
    foreach ([1, 1, 2, 3, 4, 5] as $i => $id) {
        DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => $i + 1, 'picked_catalog_id' => $id]);
    }

    $m1 = MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Loss, 'started_at' => now()->subMinutes(50)]);
    $m2 = MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Win, 'started_at' => now()->subMinutes(30)]);

    $v1 = [['catalog_id' => 1, 'quantity' => 1, 'sideboard' => false], ['catalog_id' => 2, 'quantity' => 1, 'sideboard' => false], ['catalog_id' => 9, 'quantity' => 17, 'sideboard' => false], ['catalog_id' => 3, 'quantity' => 1, 'sideboard' => true], ['catalog_id' => 5, 'quantity' => 1, 'sideboard' => true]];
    $v2 = [['catalog_id' => 1, 'quantity' => 2, 'sideboard' => false], ['catalog_id' => 2, 'quantity' => 1, 'sideboard' => false], ['catalog_id' => 9, 'quantity' => 16, 'sideboard' => false], ['catalog_id' => 3, 'quantity' => 1, 'sideboard' => true], ['catalog_id' => 5, 'quantity' => 1, 'sideboard' => true]];
    LimitedDeckSnapshot::create(['league_id' => $league->id, 'match_id' => $m1->id, 'source' => 'registered', 'signature' => 'v1', 'captured_at' => now()->subMinutes(50), 'cards' => $v1]);
    LimitedDeckSnapshot::create(['league_id' => $league->id, 'match_id' => $m2->id, 'source' => 'registered', 'signature' => 'v2', 'captured_at' => now()->subMinutes(30), 'cards' => $v2]);

    $local = Player::firstOrCreate(['username' => 'me']);
    foreach ([1, 2] as $n) {
        $game = Game::factory()->create(['match_id' => $m2->id, 'mtgo_id' => 9000 + $n, 'started_at' => now()->subMinutes(30 - $n)]);
        $deck = $n === 1 ? $v2 : [['mtgo_id' => 1, 'quantity' => 2, 'sideboard' => false], ['mtgo_id' => 2, 'quantity' => 0, 'sideboard' => false], ['mtgo_id' => 3, 'quantity' => 1, 'sideboard' => false], ['mtgo_id' => 9, 'quantity' => 16, 'sideboard' => false]];
        $game->players()->attach($local->id, [
            'is_local' => true,
            'on_play' => true,
            'instance_id' => 1,
            'deck_json' => array_map(fn ($c) => ['mtgo_id' => $c['catalog_id'] ?? $c['mtgo_id'], 'quantity' => $c['quantity'], 'sideboard' => $c['sideboard']], $deck),
        ]);
    }

    return $league;
}

it('builds versions with diffs, pool status and per game sideboarding', function () {
    $league = limitedDeckFixture();

    $evo = BuildDeckEvolution::run($league);

    expect($evo['summary'])->toMatchArray(['drafted' => 6, 'mainSpells' => 3, 'basics' => 16, 'sideboard' => 2, 'versionCount' => 2])
        ->and($evo['versions'])->toHaveCount(2)
        ->and($evo['versions'][1]['isCurrent'])->toBeTrue()
        ->and($evo['versions'][1]['diffMain'])->toBe(['added' => [['catalogId' => 1, 'quantity' => 1]], 'removed' => [['catalogId' => 9, 'quantity' => 1]]])
        ->and($evo['versions'][0]['diffMain'])->toBe(['added' => [], 'removed' => []]);

    $byId = collect($evo['pool']['groups'])->flatMap(fn ($g) => $g['cards'])->keyBy('catalogId');
    expect($byId[1]['status'])->toBe('main')->and($byId[1]['mainQty'])->toBe(2)
        ->and($byId[3]['status'])->toBe('side')
        ->and($byId[4]['status'])->toBe('cut')
        ->and($byId->has(9))->toBeFalse()
        ->and(collect($evo['pool']['groups'])->firstWhere('key', 'U')['count'])->toBe(3);

    $m2 = collect($evo['games'])->firstWhere('result', 'W');
    expect($m2['games'][0]['note'])->toBe('registered deck')
        ->and($m2['games'][1]['added'])->toBe([['catalogId' => 3, 'quantity' => 1]])
        ->and($m2['games'][1]['removed'])->toBe([['catalogId' => 2, 'quantity' => 1]]);
});

it('marks every pool card as pool while nothing is registered', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()->subHour()]);
    $draft = Draft::factory()->create(['league_id' => $league->id]);
    Card::factory()->create(['mtgo_id' => '1', 'name' => 'Bard', 'colors' => 'W', 'type' => 'Creature']);
    Card::factory()->create(['mtgo_id' => '2', 'name' => 'Harper', 'colors' => 'U', 'type' => 'Creature']);
    foreach ([1, 2] as $i => $id) {
        DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => $i + 1, 'picked_catalog_id' => $id]);
    }

    $evo = BuildDeckEvolution::run($league);

    $statuses = collect($evo['pool']['groups'])->flatMap(fn ($group) => $group['cards'])->pluck('status');

    expect($statuses)->toHaveCount(2)
        ->and($statuses->unique()->all())->toBe(['pool'])
        ->and($statuses->contains('cut'))->toBeFalse();
});

it('folds consecutive identical snapshots into one version covering both matches', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()->subHour()]);
    Card::factory()->create(['mtgo_id' => '1', 'name' => 'Bard', 'colors' => 'W', 'type' => 'Creature']);
    Card::factory()->create(['mtgo_id' => '2', 'name' => 'Harper', 'colors' => 'U', 'type' => 'Creature']);
    Card::factory()->create(['mtgo_id' => '3', 'name' => 'Grasp', 'colors' => 'U', 'type' => 'Instant']);

    $matches = collect([50, 40, 30])->map(fn (int $ago) => MtgoMatch::factory()->create([
        'league_id' => $league->id,
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
        'started_at' => now()->subMinutes($ago),
    ]));

    $v1 = [['catalog_id' => 1, 'quantity' => 1, 'sideboard' => false], ['catalog_id' => 3, 'quantity' => 1, 'sideboard' => true]];
    $v2 = [['catalog_id' => 1, 'quantity' => 1, 'sideboard' => false], ['catalog_id' => 2, 'quantity' => 1, 'sideboard' => false]];
    foreach ([[0, 'v1', $v1], [1, 'v2', $v2], [2, 'v2', $v2]] as [$i, $signature, $cards]) {
        LimitedDeckSnapshot::create([
            'league_id' => $league->id,
            'match_id' => $matches[$i]->id,
            'source' => 'registered',
            'signature' => $signature,
            'captured_at' => now()->subMinutes(50 - ($i * 10)),
            'cards' => $cards,
        ]);
    }

    $evo = BuildDeckEvolution::run($league);

    expect($evo['versions'])->toHaveCount(2)
        ->and($evo['summary']['versionCount'])->toBe(2)
        ->and($evo['versions'][0]['matchLabels'])->toBe(['Match 1'])
        ->and($evo['versions'][1]['matchIds'])->toBe([$matches[1]->id, $matches[2]->id])
        ->and($evo['versions'][1]['matchLabels'])->toBe(['Match 2', 'Match 3'])
        ->and($evo['versions'][1]['isCurrent'])->toBeTrue()
        ->and($evo['versions'][1]['diffMain'])->toBe(['added' => [['catalogId' => 2, 'quantity' => 1]], 'removed' => []])
        ->and($evo['versions'][1]['diffSide'])->toBe(['added' => [], 'removed' => [['catalogId' => 3, 'quantity' => 1]]]);
});

it('numbers matches across the whole league, including one still in progress', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'started_at' => now()->subHour()]);
    Card::factory()->create(['mtgo_id' => '1', 'name' => 'Bard', 'colors' => 'W', 'type' => 'Creature']);

    MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Win, 'started_at' => now()->subMinutes(50)]);
    MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Loss, 'started_at' => now()->subMinutes(40)]);
    $live = MtgoMatch::factory()->inProgress()->create(['league_id' => $league->id, 'started_at' => now()->subMinutes(5)]);

    LimitedDeckSnapshot::create([
        'league_id' => $league->id,
        'match_id' => $live->id,
        'source' => 'registered',
        'signature' => 'v1',
        'captured_at' => now()->subMinutes(5),
        'cards' => [['catalog_id' => 1, 'quantity' => 1, 'sideboard' => false]],
    ]);

    $evo = BuildDeckEvolution::run($league);

    expect($evo['versions'][0]['matchLabels'])->toBe(['Match 3'])
        ->and($evo['games'])->toHaveCount(2);
});

it('reports no board data for game 1 of a match with no registered snapshot', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'started_at' => now()->subHour()]);
    $match = MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Win, 'started_at' => now()->subMinutes(30)]);
    Game::factory()->create(['match_id' => $match->id, 'started_at' => now()->subMinutes(29)]);

    $evo = BuildDeckEvolution::run($league);

    expect($evo['games'][0]['games'][0]['note'])->toBe('no board data');
});

it('returns an empty structure when nothing is registered', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'started_at' => now()]);

    $evo = BuildDeckEvolution::run($league);

    expect($evo['versions'])->toBe([])->and($evo['summary']['versionCount'])->toBe(0)->and($evo['games'])->toBe([]);
});

it('derives pool statuses without touching games', function () {
    $league = limitedDeckFixture();

    $expected = collect(BuildDeckEvolution::run($league)['pool']['groups'])
        ->flatMap(fn (array $group) => $group['cards'])
        ->mapWithKeys(fn (array $card) => [$card['catalogId'] => $card['status']])
        ->all();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $statuses = BuildDeckEvolution::poolStatuses(League::findOrFail($league->id));
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($statuses)->toEqual($expected)
        ->and($queries->filter(fn (string $sql) => str_contains($sql, '"games"'))->all())->toBe([]);
});

it('skips snapshot rows that carry no quantity', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()->subHour()]);
    Card::factory()->create(['mtgo_id' => '1', 'name' => 'Bard', 'colors' => 'W', 'type' => 'Creature']);
    Card::factory()->create(['mtgo_id' => '2', 'name' => 'Harper', 'colors' => 'U', 'type' => 'Creature']);
    $match = MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Win, 'started_at' => now()->subMinutes(30)]);

    LimitedDeckSnapshot::create([
        'league_id' => $league->id,
        'match_id' => $match->id,
        'source' => 'registered',
        'signature' => 'v1',
        'captured_at' => now()->subMinutes(30),
        'cards' => [
            ['catalog_id' => 1, 'quantity' => 2, 'sideboard' => false],
            ['catalog_id' => 2, 'sideboard' => false],
        ],
    ]);

    $evo = BuildDeckEvolution::run($league);

    expect($evo['versions'][0]['main'])->toBe(2)
        ->and($evo['summary']['mainSpells'])->toBe(2);
});

it('carries a pool grouping per registered version so older builds can be inspected', function () {
    $league = limitedDeckFixture();

    $evo = BuildDeckEvolution::run($league);

    $v1 = collect($evo['versions'][0]['pool']['groups'])->flatMap(fn ($g) => $g['cards'])->keyBy('catalogId');
    $v2 = collect($evo['versions'][1]['pool']['groups'])->flatMap(fn ($g) => $g['cards'])->keyBy('catalogId');

    expect($v1[1]['mainQty'])->toBe(1)
        ->and($v2[1]['mainQty'])->toBe(2)
        ->and($v1[3]['status'])->toBe('side')
        ->and($v1[4]['status'])->toBe('cut')
        ->and($evo['versions'][1]['pool']['groups'])->toBe($evo['pool']['groups'])
        ->and($evo['versions'][0]['mainCards'])->toBe([['catalogId' => 1, 'quantity' => 1], ['catalogId' => 2, 'quantity' => 1], ['catalogId' => 9, 'quantity' => 17]])
        ->and($evo['versions'][1]['sideCards'])->toBe([['catalogId' => 3, 'quantity' => 1], ['catalogId' => 5, 'quantity' => 1]]);
});
