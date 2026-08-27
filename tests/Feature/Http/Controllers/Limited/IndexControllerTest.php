<?php

use App\Enums\LeagueKind;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the limited index with rows, kpis and filters', function () {
    League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()]);
    League::factory()->create(['kind' => LeagueKind::Constructed]);

    $this->get(route('limited.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('limited/Index')
            ->has('rows', 1)
            ->where('rows.0.setCode', 'HOB')
            ->where('kpis.events', 1)
            ->where('sets', ['HOB'])
            ->where('filters.timeframe', 'alltime')
        );
});

it('applies set and kind query filters', function () {
    League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()]);
    League::factory()->create(['kind' => LeagueKind::Sealed, 'set_code' => 'MSH', 'started_at' => now()]);

    $this->get(route('limited.index', ['set' => 'MSH', 'kind' => 'sealed', 'timeframe' => 'week']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.kind', 'sealed')
            ->where('filters.set', 'MSH')
            ->where('filters.timeframe', 'week')
        );
});

it('answers an inertia partial reload for named props only', function () {
    League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()]);

    $response = inertiaPartial(route('limited.index'), 'limited/Index', ['kpis']);

    $response->assertOk();

    expect($response->json('props'))->toHaveKey('kpis')
        ->and($response->json('props'))->not->toHaveKey('rows');
});
