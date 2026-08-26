<?php

use App\Actions\Limited\Read\BuildLimitedIndex;
use App\Enums\DraftState;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use App\Models\LimitedDeckSnapshot;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function limitedLeague(array $attrs = []): League
{
    return League::factory()->create(array_merge([
        'kind' => LeagueKind::Draft,
        'set_code' => 'HOB',
        'format' => 'DHOBHOBHOB',
        'state' => LeagueState::Complete,
        'started_at' => now()->subDay(),
    ], $attrs));
}

function draftWithPicks(League $league, int $count = 42, array $pickAttrs = []): Draft
{
    $draft = Draft::factory()->finished()->create(['league_id' => $league->id, 'started_at' => $league->started_at]);
    for ($ordinal = 1; $ordinal <= $count; $ordinal++) {
        DraftPick::factory()->create(array_merge([
            'draft_id' => $draft->id,
            'ordinal' => $ordinal,
            'pack_number' => intdiv($ordinal - 1, 14) + 1,
            'pick_number' => (($ordinal - 1) % 14) + 1,
            'picked_catalog_id' => 1000 + $ordinal,
            'shown_at' => now()->subHour()->addSeconds($ordinal * 30),
            'picked_at' => now()->subHour()->addSeconds($ordinal * 30 + 12),
            'reservations' => $ordinal % 5 === 0 ? [['catalog_id' => 1, 'at' => 'x'], ['catalog_id' => 2, 'at' => 'y']] : [],
        ], $pickAttrs));
    }

    return $draft;
}

it('lists limited leagues newest first with record, picks and state', function () {
    League::factory()->create(['kind' => LeagueKind::Constructed]);
    $league = limitedLeague();
    draftWithPicks($league);
    MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Win, 'started_at' => now()]);
    MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Loss, 'started_at' => now()]);
    LimitedDeckSnapshot::create(['league_id' => $league->id, 'source' => 'registered', 'cards' => [], 'signature' => 'a', 'captured_at' => now()]);

    $result = BuildLimitedIndex::run(null, null, now()->subYear(), now()->endOfDay());

    expect($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]->title)->toBe('HOB Draft')
        ->and($result['rows'][0]->wins)->toBe(1)
        ->and($result['rows'][0]->losses)->toBe(1)
        ->and($result['rows'][0]->picksMade)->toBe(42)
        ->and($result['rows'][0]->state)->toBe('Complete')
        ->and($result['rows'][0]->deckRegistered)->toBeTrue()
        ->and($result['rows'][0]->avgPickSeconds)->toBe(12)
        ->and($result['sets'])->toBe(['HOB']);
});

it('includes unlinked drafts as league unknown rows', function () {
    $draft = Draft::factory()->create(['league_id' => null, 'state' => DraftState::Picking]);
    DraftPick::factory()->count(3)->sequence(fn ($s) => ['ordinal' => $s->index + 1])->create(['draft_id' => $draft->id, 'picked_catalog_id' => 5]);

    $result = BuildLimitedIndex::run(null, null, now()->subYear(), now()->endOfDay());

    expect($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]->linked)->toBeFalse()
        ->and($result['rows'][0]->title)->toBe('Draft (league unknown)')
        ->and($result['rows'][0]->picksMade)->toBe(3)
        ->and($result['kpis']->unlinked)->toBe(1);
});

it('computes kpis and honours set and kind filters', function () {
    $a = limitedLeague(['set_code' => 'HOB']);
    draftWithPicks($a);
    MtgoMatch::factory()->create(['league_id' => $a->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Win, 'started_at' => now()]);
    $b = limitedLeague(['set_code' => 'MSH', 'kind' => LeagueKind::Sealed]);
    MtgoMatch::factory()->create(['league_id' => $b->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Loss, 'started_at' => now()]);

    $all = BuildLimitedIndex::run(null, null, now()->subYear(), now()->endOfDay());
    expect($all['kpis']->events)->toBe(2)
        ->and($all['kpis']->matchWinPct)->toBe(50)
        ->and($all['kpis']->mostDraftedSet)->toBe('HOB')
        ->and($all['kpis']->indecisionPct)->toBe(19)
        ->and($all['sets'])->toBe(['HOB', 'MSH']);

    $hob = BuildLimitedIndex::run('HOB', null, now()->subYear(), now()->endOfDay());
    expect($hob['rows'])->toHaveCount(1)->and($hob['rows'][0]->setCode)->toBe('HOB');

    $sealed = BuildLimitedIndex::run(null, 'sealed', now()->subYear(), now()->endOfDay());
    expect($sealed['rows'])->toHaveCount(1)->and($sealed['rows'][0]->kind)->toBe('sealed');
});

it('maps states to badges', function () {
    $dropped = limitedLeague(['state' => LeagueState::Partial]);
    $active = limitedLeague(['state' => LeagueState::Active]);
    $abandoned = limitedLeague(['state' => LeagueState::Active]);
    Draft::factory()->create(['league_id' => $abandoned->id, 'state' => DraftState::Abandoned]);

    $rows = collect(BuildLimitedIndex::run(null, null, now()->subYear(), now()->endOfDay())['rows'])->keyBy('leagueId');

    expect($rows[$dropped->id]->state)->toBe('Dropped')
        ->and($rows[$active->id]->state)->toBe('Active')
        ->and($rows[$abandoned->id]->state)->toBe('Draft abandoned');
});
