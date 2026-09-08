<?php

use App\Actions\Leagues\FormatLeagueRuns;
use App\Actions\Matches\BuildMatchShowProps;
use App\Data\Front\MatchData;
use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes manual on MatchData', function () {
    $match = MtgoMatch::factory()->create(['manual' => true]);

    expect(MatchData::from($match)->manual)->toBeTrue();
    expect(MatchData::from(MtgoMatch::factory()->create())->manual)->toBeFalse();
});

it('exposes manual from BuildMatchShowProps', function () {
    $version = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create(['manual' => true, 'deck_version_id' => $version->id]);

    $props = BuildMatchShowProps::run($match);

    expect($props['manual'])->toBeTrue()
        ->and($props['imported'])->toBeFalse();
});

it('flags manual matches in formatted league runs', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $league = League::factory()->create(['deck_version_id' => $version->id]);
    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'league_id' => $league->id,
        'state' => MatchState::Complete,
        'manual' => true,
    ]);

    $runs = FormatLeagueRuns::run(collect([$league]), null);

    expect($runs[0]['matches'][0]['manual'])->toBeTrue();
});

it('builds manualEditing deck options for a manual match', function () {
    $fx = createManualMatchFixture();

    $props = BuildMatchShowProps::run($fx['match']);

    expect($props['manualEditing'])->not->toBeNull()
        ->and(collect($props['manualEditing']['deck']['mains'])->pluck('quantity', 'mtgoId')->all())
        ->toBe([4001 => 4, 4002 => 4, 4004 => 20])
        ->and($props['manualEditing']['deck']['mains'][0]['name'])->toBe('Lightning Bolt')
        ->and(collect($props['manualEditing']['deck']['sideboard'])->pluck('quantity', 'mtgoId')->all())
        ->toBe([4003 => 3])
        ->and($props['manualEditing']['archetypeDecklist'])->toBeNull();
});

it('returns null manualEditing for tracked matches', function () {
    $fx = createManualMatchFixture(manual: false);

    expect(BuildMatchShowProps::run($fx['match'])['manualEditing'])->toBeNull();
});

it('includes the opponent archetype decklist in manualEditing', function () {
    $fx = createManualMatchFixture();
    $archetype = Archetype::factory()->create(['format' => 'modern']);
    $deck = ArchetypeDeck::factory()->create(['archetype_id' => $archetype->id]);
    $deck->cards()->attach($fx['cards']['goyf']->id, ['quantity' => 4, 'sideboard' => false]);
    $deck->cards()->attach($fx['cards']['sb']->id, ['quantity' => 2, 'sideboard' => true]);
    MatchArchetype::create([
        'mtgo_match_id' => $fx['match']->id,
        'archetype_id' => $archetype->id,
        'player_id' => $fx['opponent']->id,
        'confidence' => 1.0,
        'manual' => true,
    ]);

    $list = BuildMatchShowProps::run($fx['match']->fresh())['manualEditing']['archetypeDecklist'];

    expect($list)->toHaveCount(2)
        ->and($list[0])->toMatchArray(['mtgoId' => 4002, 'name' => 'Tarmogoyf', 'quantity' => 4, 'sideboard' => false])
        ->and($list[1])->toMatchArray(['mtgoId' => 4003, 'quantity' => 2, 'sideboard' => true]);
});

it('exposes mtgoId on kept hand, sideboard changes and reveals', function () {
    $fx = createManualMatchFixture();
    $game2 = $fx['games'][1];
    $game2->players()->updateExistingPivot($fx['local']->id, [
        'opening_hand_json' => ['kept' => [4001, 4004], 'bottomed' => [], 'mulligans' => []],
        'mulligan_count' => 0,
        'deck_json' => [
            ['mtgo_id' => 4001, 'quantity' => 3, 'sideboard' => false],
            ['mtgo_id' => 4002, 'quantity' => 4, 'sideboard' => false],
            ['mtgo_id' => 4004, 'quantity' => 20, 'sideboard' => false],
            ['mtgo_id' => 4003, 'quantity' => 1, 'sideboard' => false],
            ['mtgo_id' => 4003, 'quantity' => 2, 'sideboard' => true],
        ],
    ]);
    $game2->players()->updateExistingPivot($fx['opponent']->id, [
        'deck_json' => [['mtgo_id' => 4002, 'quantity' => 2]],
    ]);

    $game = BuildMatchShowProps::run($fx['match']->fresh())['games'][1];

    expect($game['keptHand'][0]['mtgoId'])->toBe(4001)
        ->and(collect($game['sideboardChanges'])->pluck('type', 'mtgoId')->all())->toBe([4003 => 'in', 4001 => 'out'])
        ->and($game['opponentCardsSeen'][0])->toMatchArray(['mtgoId' => 4002, 'quantity' => 2]);
});

it('renders bottomed and mulliganed cards from the stored opening hand', function () {
    $fx = createManualMatchFixture();
    $game1 = $fx['games'][0];
    $game1->players()->updateExistingPivot($fx['local']->id, [
        'opening_hand_json' => [
            'kept' => [4001, 4004, 4004, 4004, 4004, 4004],
            'bottomed' => [4002],
            'mulligans' => [[4002, 4002, 4004, 4004, 4004, 4004, 4004]],
        ],
        'mulligan_count' => 1,
    ]);

    $game = BuildMatchShowProps::run($fx['match']->fresh())['games'][0];

    expect($game['localMulligans'])->toBe(1)
        ->and($game['keptHand'])->toHaveCount(7)
        ->and(collect($game['keptHand'])->where('bottomed', true)->pluck('mtgoId')->all())->toBe([4002])
        ->and($game['keptHand'][6]['name'])->toBe('Tarmogoyf')
        ->and($game['mulliganedHands'])->toHaveCount(1)
        ->and(collect($game['mulliganedHands'][0])->pluck('mtgoId')->all())->toBe([4002, 4002, 4004, 4004, 4004, 4004, 4004]);
});

it('shows a recorded turn count when there is no timeline', function () {
    $fx = createManualMatchFixture();
    $fx['games'][0]->update(['turn_count' => 11]);

    $games = BuildMatchShowProps::run($fx['match']->fresh())['games'];

    expect($games[0]['turns'])->toBe(11)->and($games[1]['turns'])->toBeNull();
});
