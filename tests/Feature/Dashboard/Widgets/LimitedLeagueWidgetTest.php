<?php

use App\Dashboard\DashboardScope;
use App\Dashboard\Widgets\LimitedLeagueWidget;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\Card;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the latest finished limited league with its set, ignoring constructed and live runs', function () {
    League::factory()->complete()->create(['started_at' => now()]);
    League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'LIVE', 'started_at' => now()]);
    League::factory()->complete()->create(['kind' => LeagueKind::Sealed, 'set_code' => 'OLD', 'started_at' => now()->subDays(5)]);
    $latest = League::factory()->create(['kind' => LeagueKind::Draft, 'state' => LeagueState::Dropped, 'set_code' => 'NEW', 'started_at' => now()->subDay()]);
    MtgoMatch::factory()->won()->create(['league_id' => $latest->id, 'format' => 'DNEW']);
    MtgoMatch::factory()->lost()->create(['league_id' => $latest->id, 'format' => 'DNEW']);
    Card::factory()->create(['set_code' => 'NEW', 'set_name' => 'New Set']);

    $data = (new LimitedLeagueWidget)->resolve([], DashboardScope::fromTimeframe('week'));

    expect($data['run']['id'])->toBe($latest->id)
        ->and($data['setCode'])->toBe('NEW')
        ->and($data['setName'])->toBe('New Set')
        ->and($data['kind'])->toBe('draft');
});

it('returns null with no limited leagues', function () {
    League::factory()->complete()->create();

    expect((new LimitedLeagueWidget)->resolve([], DashboardScope::fromTimeframe('alltime')))->toBeNull();
});
