<?php

use App\Dashboard\DashboardScope;
use App\Dashboard\Widgets\LimitedPicksWidget;
use App\Enums\LeagueKind;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param array<int, int> $catalogIds */
function seedDraft(string $set, string $startedAt, array $catalogIds): void
{
    $league = League::factory()->complete()->create(['kind' => LeagueKind::Draft, 'set_code' => $set, 'started_at' => $startedAt]);
    $draft = Draft::factory()->finished()->create(['league_id' => $league->id]);
    foreach ($catalogIds as $id) {
        DraftPick::factory()->create(['draft_id' => $draft->id, 'picked_catalog_id' => $id, 'picked_at' => now()]);
    }
}

beforeEach(function () {
    Card::factory()->create(['mtgo_id' => 101, 'name' => 'Bolt', 'set_code' => 'NEW', 'set_name' => 'New Set']);
    Card::factory()->create(['mtgo_id' => 102, 'name' => 'Counter', 'set_code' => 'NEW']);
    Card::factory()->create(['mtgo_id' => 201, 'name' => 'Old Bolt', 'set_code' => 'OLD', 'set_name' => 'Old Set']);
});

it('normalises the set code config', function () {
    $widget = new LimitedPicksWidget;

    expect($widget->validateConfig([]))->toBe(['set_code' => null])
        ->and($widget->validateConfig(['set_code' => '']))->toBe(['set_code' => null])
        ->and($widget->validateConfig(['set_code' => 'new']))->toBe(['set_code' => 'NEW']);
});

it('counts picks for the latest set by default, ordered by count', function () {
    seedDraft('OLD', now()->subDays(10)->toDateTimeString(), [201, 201, 201]);
    seedDraft('NEW', now()->subDay()->toDateTimeString(), [101, 102, 101]);
    seedDraft('NEW', now()->subHours(2)->toDateTimeString(), [101]);

    $data = (new LimitedPicksWidget)->resolve(['set_code' => null], DashboardScope::fromTimeframe('alltime'));

    expect($data['setCode'])->toBe('NEW')
        ->and($data['setName'])->toBe('New Set')
        ->and($data['picks'][0])->toMatchArray(['catalogId' => '101', 'name' => 'Bolt', 'count' => 3])
        ->and($data['picks'][1])->toMatchArray(['catalogId' => '102', 'count' => 1])
        ->and($data['picks'])->toHaveCount(2);
});

it('filters by the configured set and falls back to latest when that set has no league', function () {
    seedDraft('OLD', now()->subDays(10)->toDateTimeString(), [201]);
    seedDraft('NEW', now()->subDay()->toDateTimeString(), [101]);

    $old = (new LimitedPicksWidget)->resolve(['set_code' => 'OLD'], DashboardScope::fromTimeframe('alltime'));
    $missing = (new LimitedPicksWidget)->resolve(['set_code' => 'ZZZ'], DashboardScope::fromTimeframe('alltime'));

    expect($old['setCode'])->toBe('OLD')
        ->and($old['picks'][0]['name'])->toBe('Old Bolt')
        ->and($missing['setCode'])->toBe('NEW');
});

it('caps at ten cards and ignores the timeframe', function () {
    $ids = range(300, 311);
    foreach ($ids as $id) {
        Card::factory()->create(['mtgo_id' => $id, 'set_code' => 'NEW']);
    }
    seedDraft('NEW', now()->subYears(3)->toDateTimeString(), $ids);

    $data = (new LimitedPicksWidget)->resolve(['set_code' => null], DashboardScope::fromTimeframe('week'));

    expect($data['picks'])->toHaveCount(10);
});

it('returns an empty list with no drafts', function () {
    $data = (new LimitedPicksWidget)->resolve(['set_code' => null], DashboardScope::fromTimeframe('alltime'));

    expect($data)->toBe(['setCode' => null, 'setName' => null, 'picks' => []]);
});
