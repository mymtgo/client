<?php

use App\Enums\LeagueKind;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use App\Models\LimitedDeckSnapshot;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 404 for a constructed league', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Constructed]);

    $this->get(route('limited.deck', ['league' => $league->id]))->assertNotFound();
});

it('renders the deck page and serves evolution as a deferred prop', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()]);

    $this->get(route('limited.deck', ['league' => $league->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('limited/Deck')->where('currentPage', 'deck')->where('event.id', $league->id)->missing('evolution'));

    $response = inertiaPartial(route('limited.deck', ['league' => $league->id]), 'limited/Deck', ['evolution']);

    $response->assertOk();

    expect($response->json('props.evolution.summary.versionCount'))->toBe(0)
        ->and($response->json('props.evolution.versions'))->toBe([])
        ->and($response->json('props.evolution.games'))->toBe([]);
});

it('serves the built evolution payload for a league with a registered deck', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()->subHour()]);
    $draft = Draft::factory()->finished()->create(['league_id' => $league->id]);
    Card::factory()->create(['mtgo_id' => '1', 'name' => 'Bard', 'colors' => 'W', 'type' => 'Creature']);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 1, 'picked_catalog_id' => 1]);
    $match = MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Win, 'started_at' => now()->subMinutes(30)]);
    LimitedDeckSnapshot::create([
        'league_id' => $league->id,
        'match_id' => $match->id,
        'source' => 'registered',
        'signature' => 'v1',
        'captured_at' => now()->subMinutes(30),
        'cards' => [['catalog_id' => 1, 'quantity' => 2, 'sideboard' => false]],
    ]);

    $response = inertiaPartial(route('limited.deck', ['league' => $league->id]), 'limited/Deck', ['evolution']);

    $response->assertOk();

    expect($response->json('props.evolution.summary.versionCount'))->toBe(1)
        ->and($response->json('props.evolution.summary.mainSpells'))->toBe(2)
        ->and($response->json('props.evolution.versions.0.isCurrent'))->toBeTrue()
        ->and($response->json('props.evolution.cards.1.name'))->toBe('Bard')
        ->and($response->json('props.evolution.games.0.result'))->toBe('W');
});
