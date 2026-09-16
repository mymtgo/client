<?php

use App\Enums\MatchOutcome;
use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake());

function seedDeck(array $attributes = [], int $won = 0, int $lost = 0, int $drawn = 0): Deck
{
    $deck = Deck::factory()->create($attributes);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    if ($won > 0) {
        MtgoMatch::factory()->won()->count($won)->create([
            'deck_version_id' => $version->id,
        ]);
    }
    if ($lost > 0) {
        MtgoMatch::factory()->lost()->count($lost)->create([
            'deck_version_id' => $version->id,
        ]);
    }
    if ($drawn > 0) {
        MtgoMatch::factory()->count($drawn)->create([
            'deck_version_id' => $version->id,
            'outcome' => MatchOutcome::Draw,
        ]);
    }

    return $deck;
}

it('lists decks as a paginator', function () {
    Deck::factory()->count(3)->create();

    $this->get(route('decks.index'))
        ->assertInertia(fn ($page) => $page
            ->component('decks/Index')
            ->has('decks.data', 3)
            ->missing('mode')
            ->missing('groups')
        );
});

it('mixes trashed decks into the listing when hide-archived is disabled', function () {
    AppSettings::setHideArchivedDecks(false);

    Deck::factory()->count(2)->create();
    $deleted = Deck::factory()->create();
    $deleted->delete();

    $this->get(route('decks.index'))
        ->assertInertia(fn ($page) => $page
            ->component('decks/Index')
            ->has('decks.data', 3)
            ->where('filters.hide_deleted', false)
        );
});

it('hides trashed decks when the hide-archived setting is enabled', function () {
    AppSettings::setHideArchivedDecks(true);

    Deck::factory()->count(2)->create();
    $deleted = Deck::factory()->create();
    $deleted->delete();

    $this->get(route('decks.index'))
        ->assertInertia(fn ($page) => $page
            ->has('decks.data', 2)
            ->where('filters.hide_deleted', true)
        );
});

it('sorts decks by win rate over every match played, draws included', function () {
    // 6 / 10 = 60%
    seedDeck(['name' => 'No Draws'], won: 6, lost: 4);
    // 7 / 14 = 50%. Ignoring draws it would be 7 / 11 = 64% and wrongly sort first.
    seedDeck(['name' => 'With Draws'], won: 7, lost: 4, drawn: 3);

    $this->get(route('decks.index', ['sort' => 'winRate']))
        ->assertInertia(fn ($page) => $page
            ->where('decks.data.0.name', 'No Draws')
            ->where('decks.data.1.name', 'With Draws')
        );
});

it('exposes sidebar option props and drops the legacy formats prop', function () {
    $tron = Archetype::factory()->create(['name' => 'Tron']);
    Deck::factory()->create(['archetype_id' => $tron->id, 'format' => 'CModern']);
    Deck::factory()->create(['archetype_id' => null, 'format' => 'CModern']);

    $this->get(route('decks.index'))
        ->assertInertia(fn ($page) => $page
            ->has('formatOptions', 1)
            ->where('formatOptions.0.value', 'CModern')
            ->where('formatOptions.0.count', 2)
            ->has('archetypeOptions', 1)
            ->where('archetypeOptions.0.name', 'Tron')
            ->where('archetypeOptions.0.deckCount', 1)
            ->where('unclassifiedCount', 1)
            ->where('filters.archetype', '')
            ->missing('formats')
        );
});

it('filters decks by archetype id', function () {
    $tron = Archetype::factory()->create();
    $burn = Archetype::factory()->create();
    Deck::factory()->create(['name' => 'T', 'archetype_id' => $tron->id]);
    Deck::factory()->create(['name' => 'B', 'archetype_id' => $burn->id]);

    $this->get(route('decks.index', ['archetype' => $tron->id]))
        ->assertInertia(fn ($page) => $page
            ->has('decks.data', 1)
            ->where('decks.data.0.name', 'T')
            ->where('filters.archetype', (string) $tron->id)
        );
});

it('filters to unclassified decks with archetype=none', function () {
    Deck::factory()->create(['name' => 'Classified', 'archetype_id' => Archetype::factory()->create()->id]);
    Deck::factory()->create(['name' => 'Loose', 'archetype_id' => null]);

    $this->get(route('decks.index', ['archetype' => 'none']))
        ->assertInertia(fn ($page) => $page
            ->has('decks.data', 1)
            ->where('decks.data.0.name', 'Loose')
            ->where('filters.archetype', 'none')
        );
});

it('treats a garbage archetype value as unset', function () {
    Deck::factory()->count(2)->create();

    $this->get(route('decks.index', ['archetype' => 'abc']))
        ->assertInertia(fn ($page) => $page
            ->has('decks.data', 2)
            ->where('filters.archetype', '')
        );
});

it('does not narrow sidebar counts by search or archetype filter', function () {
    $tron = Archetype::factory()->create(['name' => 'Tron']);
    $burn = Archetype::factory()->create(['name' => 'Burn']);
    Deck::factory()->create(['name' => 'Alpha', 'archetype_id' => $tron->id]);
    Deck::factory()->create(['name' => 'Beta', 'archetype_id' => $burn->id]);
    Deck::factory()->create(['name' => 'Gamma', 'archetype_id' => null]);

    $this->get(route('decks.index', ['search' => 'Alpha', 'archetype' => $tron->id]))
        ->assertInertia(fn ($page) => $page
            ->has('decks.data', 1)
            ->has('archetypeOptions', 2)
            ->where('unclassifiedCount', 1)
        );
});

it('narrows archetype options and unclassified count by format', function () {
    $modern = Archetype::factory()->create(['name' => 'Modern Tron']);
    $legacy = Archetype::factory()->create(['name' => 'Legacy Tron']);
    Deck::factory()->create(['archetype_id' => $modern->id, 'format' => 'CModern']);
    Deck::factory()->create(['archetype_id' => $legacy->id, 'format' => 'CLegacy']);
    Deck::factory()->create(['archetype_id' => null, 'format' => 'CLegacy']);

    $this->get(route('decks.index', ['format' => 'CModern']))
        ->assertInertia(fn ($page) => $page
            ->has('archetypeOptions', 1)
            ->where('archetypeOptions.0.name', 'Modern Tron')
            ->where('unclassifiedCount', 0)
        );
});

it('reports archetype records with draws in the total', function () {
    $tron = Archetype::factory()->create(['name' => 'Tron']);
    seedDeck(['archetype_id' => $tron->id], won: 6, lost: 4);
    seedDeck(['archetype_id' => $tron->id], won: 1, lost: 1, drawn: 2);

    $this->get(route('decks.index'))
        ->assertInertia(fn ($page) => $page
            ->where('archetypeOptions.0.record.total', 14)
            ->where('archetypeOptions.0.record.wins', 7)
            ->where('archetypeOptions.0.record.winrate', 50)
        );
});

it('defers the archetype list for the picker without system fallbacks', function () {
    Archetype::factory()->count(3)->create();

    $this->get(route('decks.index'))
        ->assertInertia(fn ($page) => $page->missing('archetypes'));

    // assertInertia() reads the embedded page payload of a full page load,
    // which a partial (X-Inertia) reload does not have; it returns raw JSON
    // instead, so check the JSON body directly (same pattern as
    // StickyFiltersTest). Migrations seed two fallback archetypes (Homebrew,
    // Rogue); the picker never offers those, so exactly the three created
    // here come back.
    inertiaPartial(route('decks.index'), 'decks/Index', ['archetypes'])
        ->assertOk()
        ->assertJsonCount(3, 'props.archetypes');
});

it('omits the archetype header without a filter and builds it with one', function () {
    $tron = Archetype::factory()->create(['name' => 'Tron']);
    Deck::factory()->create(['archetype_id' => $tron->id]);
    Deck::factory()->create(['archetype_id' => null]);

    $this->get(route('decks.index'))
        ->assertInertia(fn ($page) => $page->where('archetypeHeader', null));

    $this->get(route('decks.index', ['archetype' => $tron->id]))
        ->assertInertia(fn ($page) => $page
            ->where('archetypeHeader.archetype.name', 'Tron')
            ->where('archetypeHeader.deckCount', 1)
        );

    $this->get(route('decks.index', ['archetype' => 'none']))
        ->assertInertia(fn ($page) => $page
            ->where('archetypeHeader.archetype', null)
            ->where('archetypeHeader.deckCount', 1)
        );
});

it('treats an archetype filter with no decks in the current format as unset', function () {
    $legacy = Archetype::factory()->create(['name' => 'Legacy Tron']);
    Deck::factory()->create(['archetype_id' => $legacy->id, 'format' => 'CLegacy']);
    Deck::factory()->count(2)->create(['archetype_id' => null, 'format' => 'CModern']);

    $this->get(route('decks.index', ['format' => 'CModern', 'archetype' => $legacy->id]))
        ->assertInertia(fn ($page) => $page
            ->has('decks.data', 2)
            ->where('filters.archetype', '')
            ->where('archetypeHeader', null)
        );
});
