<?php

use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Desktop\Facades\Settings;

uses(RefreshDatabase::class);

function seedDeck(array $attributes = [], int $won = 0, int $lost = 0): Deck
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

    return $deck;
}

it('returns flat mode when grouping setting is off', function () {
    Settings::set('decks_grouped_by_archetype', 0);
    Deck::factory()->count(3)->create();

    $response = $this->get(route('decks.index'));

    $response->assertInertia(fn ($page) => $page
        ->component('decks/Index')
        ->where('mode', 'flat')
        ->has('decks.data', 3)
        ->missing('groups')
    );
});

it('returns grouped mode with archetype groups when setting is on', function () {
    Settings::set('decks_grouped_by_archetype', 1);

    $tron = Archetype::factory()->create(['name' => 'Eldrazi Tron', 'format' => 'CMODERN']);
    $burn = Archetype::factory()->create(['name' => 'Burn', 'format' => 'CMODERN']);

    Deck::factory()->count(2)->create(['archetype_id' => $tron->id]);
    Deck::factory()->create(['archetype_id' => $burn->id]);

    $response = $this->get(route('decks.index'));

    $response->assertInertia(fn ($page) => $page
        ->component('decks/Index')
        ->where('mode', 'grouped')
        ->has('groups', 2)
        ->missing('decks')
    );
});

it('places decks with no archetype into an Unassigned group at the end', function () {
    Settings::set('decks_grouped_by_archetype', 1);

    $tron = Archetype::factory()->create(['name' => 'Eldrazi Tron']);
    Deck::factory()->create(['archetype_id' => $tron->id]);
    Deck::factory()->create(['archetype_id' => null]);

    $response = $this->get(route('decks.index'));

    $response->assertInertia(fn ($page) => $page
        ->has('groups', 2)
        ->where('groups.0.archetype.name', 'Eldrazi Tron')
        ->where('groups.1.archetype', null)
    );
});

it('omits archetype groups whose decks do not match the format filter', function () {
    Settings::set('decks_grouped_by_archetype', 1);

    $modern = Archetype::factory()->create(['name' => 'Modern Tron']);
    $legacy = Archetype::factory()->create(['name' => 'Legacy Tron']);

    Deck::factory()->create(['archetype_id' => $modern->id, 'format' => 'CMODERN']);
    Deck::factory()->create(['archetype_id' => $legacy->id, 'format' => 'CLEGACY']);

    $response = $this->get(route('decks.index', ['format' => 'CMODERN']));

    $response->assertInertia(fn ($page) => $page
        ->has('groups', 1)
        ->where('groups.0.archetype.name', 'Modern Tron')
    );
});

it('computes weighted winrate stats per archetype group', function () {
    Settings::set('decks_grouped_by_archetype', 1);

    $tron = Archetype::factory()->create(['name' => 'Eldrazi Tron']);
    seedDeck(['archetype_id' => $tron->id], won: 6, lost: 4);
    seedDeck(['archetype_id' => $tron->id], won: 18, lost: 2);

    $response = $this->get(route('decks.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('groups.0.stats.totalMatches', 30)
        ->where('groups.0.stats.totalWins', 24)
        ->where('groups.0.stats.winrate', 80)
    );
});

it('orders groups by winrate descending when sort is winRate, unassigned last', function () {
    Settings::set('decks_grouped_by_archetype', 1);

    $weak = Archetype::factory()->create(['name' => 'Weak']);
    $strong = Archetype::factory()->create(['name' => 'Strong']);

    seedDeck(['archetype_id' => $weak->id], won: 2, lost: 8);
    seedDeck(['archetype_id' => $strong->id], won: 8, lost: 2);
    seedDeck(['archetype_id' => null], won: 5, lost: 5);

    $response = $this->get(route('decks.index', ['sort' => 'winRate']));

    $response->assertInertia(fn ($page) => $page
        ->where('groups.0.archetype.name', 'Strong')
        ->where('groups.1.archetype.name', 'Weak')
        ->where('groups.2.archetype', null)
    );
});

it('orders groups by name alphabetically when sort is name, unassigned last', function () {
    Settings::set('decks_grouped_by_archetype', 1);

    $z = Archetype::factory()->create(['name' => 'Zoo']);
    $a = Archetype::factory()->create(['name' => 'Affinity']);

    Deck::factory()->create(['archetype_id' => $z->id]);
    Deck::factory()->create(['archetype_id' => $a->id]);
    Deck::factory()->create(['archetype_id' => null]);

    $response = $this->get(route('decks.index', ['sort' => 'name']));

    $response->assertInertia(fn ($page) => $page
        ->where('groups.0.archetype.name', 'Affinity')
        ->where('groups.1.archetype.name', 'Zoo')
        ->where('groups.2.archetype', null)
    );
});
