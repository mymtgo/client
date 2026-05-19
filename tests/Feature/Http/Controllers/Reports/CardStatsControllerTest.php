<?php

use App\Models\Account;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->account = Account::create(['username' => 'main', 'active' => true]);
    Account::flushCurrent();
});

it('renders card stats page with empty state when no selection', function () {
    $response = $this->get(route('reports.card-stats'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/CardStats')
            ->where('selectedArchetype', null)
            ->where('selectedFormat', null)
            ->where('matchCount', 0));
});

it('renders card stats page with selection and exposes deferred cardStats', function () {
    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['account_id' => $this->account->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'format' => 'CModern',
        'state' => 'complete',
    ]);

    $response = $this->get(route('reports.card-stats', [
        'archetype' => $archetype->id,
        'format' => 'CModern',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/CardStats')
            ->where('selectedArchetype', $archetype->id)
            ->where('selectedFormat', 'CModern')
            ->where('matchCount', 1));
});

it('exposes opponent perspective when card_stats_perspective=theirs', function () {
    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['account_id' => $this->account->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'format' => 'CModern',
        'state' => 'complete',
    ]);

    $response = $this->get(route('reports.card-stats', [
        'archetype' => $archetype->id,
        'format' => 'CModern',
        'card_stats_perspective' => 'theirs',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->component('reports/CardStats'));
});

it('redirects to add format when archetype has only one format', function () {
    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['account_id' => $this->account->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'format' => 'CModern',
        'state' => 'complete',
    ]);

    $response = $this->get(route('reports.card-stats', ['archetype' => $archetype->id]));

    $response->assertRedirect(route('reports.card-stats', [
        'archetype' => $archetype->id,
        'format' => 'CModern',
    ]));
});

it('does not redirect when archetype has multiple formats', function () {
    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['account_id' => $this->account->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'format' => 'CModern', 'state' => 'complete']);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'format' => 'CPioneer', 'state' => 'complete']);

    $response = $this->get(route('reports.card-stats', ['archetype' => $archetype->id]));

    $response->assertOk();
});

it('does not redirect when format already set', function () {
    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['account_id' => $this->account->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'format' => 'CModern', 'state' => 'complete']);

    $response = $this->get(route('reports.card-stats', ['archetype' => $archetype->id, 'format' => 'CModern']));

    $response->assertOk();
});
