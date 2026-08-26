<?php

use App\Enums\LeagueKind;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use App\Models\LimitedDeckSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 404 for a constructed league', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Constructed]);

    $this->get(route('limited.cards', ['league' => $league->id]))->assertNotFound();
});

it('renders the cards page and serves the table as a deferred prop', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()]);

    $this->get(route('limited.cards', ['league' => $league->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('limited/Cards')->where('currentPage', 'cards')->where('event.id', $league->id)->missing('table'));

    $response = inertiaPartial(route('limited.cards', ['league' => $league->id]), 'limited/Cards', ['table']);

    $response->assertOk();

    expect($response->json('props.table.summary.distinct'))->toBe(0)
        ->and($response->json('props.table.rows'))->toBe([]);
});

it('serves the built card rows for a league with picks', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()]);
    $draft = Draft::factory()->finished()->create(['league_id' => $league->id, 'seat_count' => 8]);
    Card::factory()->create(['mtgo_id' => '1', 'oracle_id' => 'bard', 'name' => 'Bard', 'type' => 'Creature']);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1, 'cards_available' => [1], 'picked_catalog_id' => 1]);
    LimitedDeckSnapshot::create(['league_id' => $league->id, 'source' => 'registered', 'signature' => 's', 'captured_at' => now(), 'cards' => [['catalog_id' => 1, 'quantity' => 1, 'sideboard' => false]]]);

    $response = inertiaPartial(route('limited.cards', ['league' => $league->id]), 'limited/Cards', ['table']);

    $response->assertOk();

    expect($response->json('props.table.summary.distinct'))->toBe(1)
        ->and($response->json('props.table.rows.0.catalogId'))->toBe(1)
        ->and($response->json('props.table.rows.0.labels'))->toBe(['P1p1'])
        ->and($response->json('props.table.rows.0.status'))->toBe('main')
        ->and($response->json('props.table.cards.1.name'))->toBe('Bard');
});
