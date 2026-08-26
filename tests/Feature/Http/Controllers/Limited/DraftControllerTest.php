<?php

use App\Enums\LeagueKind;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 404 for a constructed league', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Constructed]);
    $this->get(route('limited.draft', ['league' => $league->id]))->assertNotFound();
});

it('renders shared event props', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()]);
    $this->get(route('limited.draft', ['league' => $league->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('limited/Draft')->where('currentPage', 'draft')->where('event.id', $league->id)->where('event.setCode', 'HOB'));
});

it('renders the draft page with picks and deferred cross draft block', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()]);
    $draft = Draft::factory()->finished()->create(['league_id' => $league->id]);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 1, 'cards_available' => [1, 2], 'picked_catalog_id' => 1]);

    $this->get(route('limited.draft', ['league' => $league->id, 'pick' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('limited/Draft')
            ->where('currentPage', 'draft')
            ->where('selectedOrdinal', 1)
            ->has('review.picks', 1)
            ->where('review.picks.0.label', 'P1p1')
            ->has('review.cards.1')
            ->missing('crossDraft')
        );
});

it('renders an empty draft page when the league has no draft', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Sealed, 'started_at' => now()]);

    $this->get(route('limited.draft', ['league' => $league->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('limited/Draft')->where('review', null));
});

it('falls back to the first pick when the pick query param is out of range', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'started_at' => now()]);
    $draft = Draft::factory()->create(['league_id' => $league->id]);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 4, 'cards_available' => [1], 'picked_catalog_id' => 1]);

    $this->get(route('limited.draft', ['league' => $league->id, 'pick' => 99]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('limited/Draft')->where('selectedOrdinal', 4));
});

it('serves cross draft stats as a deferred prop', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()]);
    Draft::factory()->finished()->create(['league_id' => $league->id]);
    $other = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()]);
    $otherDraft = Draft::factory()->finished()->create(['league_id' => $other->id]);
    Card::factory()->create(['mtgo_id' => '2', 'oracle_id' => 'bard', 'set_code' => 'HOB']);
    DraftPick::factory()->create(['draft_id' => $otherDraft->id, 'ordinal' => 1, 'cards_available' => [2], 'picked_catalog_id' => 2]);

    $response = inertiaPartial(route('limited.draft', ['league' => $league->id]), 'limited/Draft', ['crossDraft']);

    $response->assertOk();

    expect($response->json('props.crossDraft.bard.timesTaken'))->toBe(1);
});

it('does not rebuild the review on a partial reload for the deferred cross draft prop', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'started_at' => now()]);
    $draft = Draft::factory()->create(['league_id' => $league->id]);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 1, 'cards_available' => [1], 'picked_catalog_id' => 1]);

    // Inertia's Response::resolvePartialProperties filters props down to the
    // requested `only` set BEFORE resolvePropertyInstances invokes Closure
    // props (vendor/inertiajs/inertia-laravel/src/Response.php), so `review`'s
    // closure is never called on this request. `event` is a closure for the
    // same reason, so the shared event props are not rebuilt either.
    $response = inertiaPartial(route('limited.draft', ['league' => $league->id]), 'limited/Draft', ['crossDraft']);

    $response->assertOk();

    expect($response->json('props'))->toHaveKey('crossDraft')
        ->and($response->json('props'))->not->toHaveKey('review')
        ->and($response->json('props'))->not->toHaveKey('event');
});
