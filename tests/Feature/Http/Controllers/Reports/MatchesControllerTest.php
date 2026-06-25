<?php

use App\Models\Account;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->account = Account::create(['username' => 'main', 'active' => true]);
    Account::flushCurrent();
});

it('renders matches page with empty state when no selection', function () {
    $response = $this->get(route('reports.matches'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/Matches')
            ->where('selectedArchetype', null)
            ->where('selectedFormat', null)
            ->where('matchCount', 0));
});

it('renders matches page with selection and reports match count', function () {
    $archetype = Archetype::factory()->create();
    $opponentArchetype = Archetype::factory()->create();

    $deckA = Deck::factory()->create(['account_id' => $this->account->id, 'archetype_id' => $archetype->id]);
    $versionA = DeckVersion::factory()->create(['deck_id' => $deckA->id]);

    $deckB = Deck::factory()->create(['account_id' => $this->account->id, 'archetype_id' => $archetype->id]);
    $versionB = DeckVersion::factory()->create(['deck_id' => $deckB->id]);

    foreach ([$versionA, $versionB] as $version) {
        $match = MtgoMatch::factory()->create([
            'deck_version_id' => $version->id,
            'format' => 'CModern',
            'state' => 'complete',
            'outcome' => 'win',
        ]);
        MatchArchetype::create([
            'mtgo_match_id' => $match->id,
            'archetype_id' => $opponentArchetype->id,
            'is_opponent' => true,
            'confidence' => 0.9,
        ]);
    }

    $response = $this->get(route('reports.matches', [
        'archetype' => $archetype->id,
        'format' => 'CModern',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/Matches')
            ->where('selectedArchetype', $archetype->id)
            ->where('selectedFormat', 'CModern')
            ->where('matchCount', 2));
});

it('excludes matches from other accounts', function () {
    $otherAccount = Account::create(['username' => 'other', 'active' => false]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['account_id' => $otherAccount->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'format' => 'CModern',
        'state' => 'complete',
    ]);

    // Reset back to main account being active
    $this->account->refresh();
    Account::flushCurrent();

    $response = $this->get(route('reports.matches', [
        'archetype' => $archetype->id,
        'format' => 'CModern',
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->where('matchCount', 0));
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

    $response = $this->get(route('reports.matches', ['archetype' => $archetype->id]));

    $response->assertRedirect(route('reports.matches', [
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

    $response = $this->get(route('reports.matches', ['archetype' => $archetype->id]));

    $response->assertOk();
});

it('does not redirect when format already set', function () {
    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['account_id' => $this->account->id, 'archetype_id' => $archetype->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'format' => 'CModern', 'state' => 'complete']);

    $response = $this->get(route('reports.matches', ['archetype' => $archetype->id, 'format' => 'CModern']));

    $response->assertOk();
});
