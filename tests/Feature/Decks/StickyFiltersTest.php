<?php

use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake());

function seedStickyDecks(): Archetype
{
    $archetype = Archetype::factory()->create(['format' => 'modern']);
    $deck = Deck::factory()->create(['archetype_id' => $archetype->id, 'format' => 'CModern']);
    DeckVersion::factory()->create(['deck_id' => $deck->id]);
    Deck::factory()->create(['format' => 'CPauper']);

    return $archetype;
}

it('remembers the format and archetype filter when the listing is revisited without a query string', function () {
    $archetype = seedStickyDecks();

    $this->get(route('decks.index', ['format' => 'CModern', 'archetype' => $archetype->id]))->assertOk();

    $this->get(route('decks.index'))
        ->assertInertia(fn ($page) => $page
            ->where('filters.format', 'CModern')
            ->where('filters.archetype', (string) $archetype->id)
            ->has('decks.data', 1)
        );
});

it('clears the remembered filters when the user empties them', function () {
    $archetype = seedStickyDecks();

    $this->get(route('decks.index', ['format' => 'CModern', 'archetype' => $archetype->id]))->assertOk();
    $this->get(route('decks.index', ['format' => '', 'archetype' => '']))->assertOk();

    $this->get(route('decks.index'))
        ->assertInertia(fn ($page) => $page
            ->where('filters.format', '')
            ->where('filters.archetype', '')
            ->has('decks.data', 2)
        );
});

it('does not remember the search term', function () {
    seedStickyDecks();

    $this->get(route('decks.index', ['search' => 'zzz']))->assertOk();

    $this->get(route('decks.index'))
        ->assertInertia(fn ($page) => $page->where('filters.search', '')->has('decks.data', 2));
});

it('remembers the archetype opened on a stats tab', function () {
    $archetype = seedStickyDecks();

    $this->get(route('decks.archetypes.matchups', [$archetype, 'format' => 'CModern']))->assertOk();

    $this->get(route('decks.index'))
        ->assertInertia(fn ($page) => $page
            ->where('filters.format', 'CModern')
            ->where('filters.archetype', (string) $archetype->id)
        );
});

it('forgets a remembered archetype that has fallen out of scope', function () {
    $archetype = seedStickyDecks();

    $this->get(route('decks.index', ['archetype' => $archetype->id]))->assertOk();
    AppSettings::setHideArchivedDecks(true);
    Deck::query()->where('archetype_id', $archetype->id)->delete();

    $this->get(route('decks.index'))
        ->assertInertia(fn ($page) => $page->where('filters.archetype', '')->has('decks.data', 1));

    // The stale value is gone for good, not just hidden for this request.
    $this->get(route('decks.index'))
        ->assertInertia(fn ($page) => $page->where('filters.archetype', ''));
});
