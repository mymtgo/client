<?php

use App\Facades\Mtgo;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Attach a card to a freshly-created archetype deck (one "deck used in").
 */
function usedInDecks(Card $card, int $count): void
{
    foreach (range(1, $count) as $i) {
        ArchetypeDeck::factory()->create()->cards()->attach($card, ['quantity' => 4, 'sideboard' => false]);
    }
}

/**
 * Attach a card to a deck whose archetype belongs to the given format.
 */
function usedInFormatDeck(Card $card, string $format): void
{
    ArchetypeDeck::factory()
        ->for(Archetype::factory()->state(['format' => $format]))
        ->create()
        ->cards()
        ->attach($card, ['quantity' => 4, 'sideboard' => false]);
}

it('renders the cards index page', function () {
    Card::factory()->create(['name' => 'Lightning Bolt']);

    $this->get(route('cards.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('cards/Index')
            ->has('cards.data', 1)
            ->has('missingCount')
            ->has('totalCount')
            ->has('formats')
        );
});

it('lists the available formats from archetypes', function () {
    $bolt = Card::factory()->create(['name' => 'Bolt', 'oracle_id' => 'o-b']);
    usedInFormatDeck($bolt, 'modern');
    usedInFormatDeck($bolt, 'legacy');

    $this->get(route('cards.index'))
        ->assertInertia(fn ($page) => $page
            ->where('formats', fn ($formats) => collect($formats)->keys()->sort()->values()->all() === ['legacy', 'modern'])
        );
});

it('filters cards by the format of decks they appear in', function () {
    $modern = Card::factory()->create(['name' => 'Modern Card', 'oracle_id' => 'o-m']);
    $legacy = Card::factory()->create(['name' => 'Legacy Card', 'oracle_id' => 'o-l']);

    usedInFormatDeck($modern, 'modern');
    usedInFormatDeck($legacy, 'legacy');

    $this->get(route('cards.index', ['format' => 'modern']))
        ->assertInertia(fn ($page) => $page
            ->has('cards.data', 1)
            ->where('cards.data.0.name', 'Modern Card')
        );
});

it('scopes popularity to the selected format', function () {
    $card = Card::factory()->create(['name' => 'Splashable', 'oracle_id' => 'o-s']);

    // Three modern decks, one legacy deck run the same card.
    usedInFormatDeck($card, 'modern');
    usedInFormatDeck($card, 'modern');
    usedInFormatDeck($card, 'modern');
    usedInFormatDeck($card, 'legacy');

    $this->get(route('cards.index', ['format' => 'modern']))
        ->assertInertia(fn ($page) => $page->where('cards.data.0.popularity', 3));
});

it('orders cards by popularity descending by default', function () {
    $popular = Card::factory()->create(['name' => 'Popular', 'oracle_id' => 'o-popular']);
    $rare = Card::factory()->create(['name' => 'Rare', 'oracle_id' => 'o-rare']);

    usedInDecks($popular, 3);
    usedInDecks($rare, 1);

    $this->get(route('cards.index'))
        ->assertInertia(fn ($page) => $page
            ->where('cards.data.0.name', 'Popular')
            ->where('cards.data.0.popularity', 3)
            ->where('cards.data.1.name', 'Rare')
            ->where('cards.data.1.popularity', 1)
        );
});

it('counts popularity as the number of distinct archetypes the card is used in', function () {
    $card = Card::factory()->create(['name' => 'Counterspell', 'oracle_id' => 'o-cs']);
    usedInDecks($card, 4);

    $this->get(route('cards.index'))
        ->assertInertia(fn ($page) => $page->where('cards.data.0.popularity', 4));
});

it('counts popularity by archetype, not by deck', function () {
    $card = Card::factory()->create(['name' => 'Staple', 'oracle_id' => 'o-stp']);
    $archetype = Archetype::factory()->create();

    // Three decks of the SAME archetype run the card — counts as one archetype.
    foreach (range(1, 3) as $i) {
        ArchetypeDeck::factory()->for($archetype)->create()
            ->cards()->attach($card, ['quantity' => 4, 'sideboard' => false]);
    }
    // One deck of a different archetype.
    usedInDecks($card, 1);

    $this->get(route('cards.index'))
        ->assertInertia(fn ($page) => $page->where('cards.data.0.popularity', 2));
});

it('groups printings by oracle_id and counts distinct archetypes across printings', function () {
    // Two printings of the same card (same oracle_id, different mtgo_id).
    $old = Card::factory()->create(['name' => 'Brainstorm', 'oracle_id' => 'o-bs', 'set_code' => 'ICE', 'created_at' => now()->subYear()]);
    $new = Card::factory()->create(['name' => 'Brainstorm', 'oracle_id' => 'o-bs', 'set_code' => 'MH2', 'created_at' => now()]);

    // One deck runs BOTH printings — must count as a single archetype, not two.
    $deck = ArchetypeDeck::factory()->create();
    $deck->cards()->attach($old, ['quantity' => 2, 'sideboard' => false]);
    $deck->cards()->attach($new, ['quantity' => 2, 'sideboard' => false]);
    // A second archetype runs only the new printing.
    usedInDecks($new, 1);

    $this->get(route('cards.index'))
        ->assertInertia(fn ($page) => $page
            ->has('cards.data', 1)                          // collapsed to one row
            ->where('cards.data.0.set_code', 'MH2')         // representative = latest created_at
            ->where('cards.data.0.popularity', 2)           // distinct archetypes across printings
        );
});

it('expands printings into separate rows when grouping is disabled', function () {
    Card::factory()->create(['name' => 'Brainstorm', 'oracle_id' => 'o-bs', 'set_code' => 'ICE']);
    Card::factory()->create(['name' => 'Brainstorm', 'oracle_id' => 'o-bs', 'set_code' => 'MH2']);

    $this->get(route('cards.index', ['group_printings' => 'false']))
        ->assertInertia(fn ($page) => $page->has('cards.data', 2));
});

it('hides cards whose canonical type is listed in hidden_types', function () {
    Card::factory()->create(['name' => 'Goblin', 'type' => 'Creature — Goblin', 'oracle_id' => 'o-g']);
    Card::factory()->create(['name' => 'Ponder', 'type' => 'Sorcery', 'oracle_id' => 'o-p']);
    Card::factory()->create(['name' => 'Island', 'type' => 'Basic Land — Island', 'oracle_id' => 'o-i']);

    $this->get(route('cards.index', ['hidden_types' => 'Land,Creature']))
        ->assertInertia(fn ($page) => $page
            ->has('cards.data', 1)
            ->where('cards.data.0.name', 'Ponder')
        );
});

it('resolves multi-type cards by canonical precedence', function () {
    Card::factory()->create(['name' => 'Construct', 'type' => 'Artifact Creature — Construct', 'oracle_id' => 'o-c']);

    // Canonical type is Creature, so hiding Artifact keeps it...
    $this->get(route('cards.index', ['hidden_types' => 'Artifact']))
        ->assertInertia(fn ($page) => $page->has('cards.data', 1));

    // ...and hiding Creature removes it.
    $this->get(route('cards.index', ['hidden_types' => 'Creature']))
        ->assertInertia(fn ($page) => $page->has('cards.data', 0));
});

it('hides battle cards when the Battle type is hidden', function () {
    Card::factory()->create(['name' => 'Invasion of Zendikar', 'type' => 'Battle — Siege', 'oracle_id' => 'o-bat']);
    Card::factory()->create(['name' => 'Ponder', 'type' => 'Sorcery', 'oracle_id' => 'o-pon']);

    $this->get(route('cards.index', ['hidden_types' => 'Battle']))
        ->assertInertia(fn ($page) => $page
            ->has('cards.data', 1)
            ->where('cards.data.0.name', 'Ponder')
        );
});

it('keeps cards with no recognised type even when every type is hidden', function () {
    Card::factory()->create(['name' => 'Stub', 'type' => null, 'oracle_id' => 'o-s']);

    $this->get(route('cards.index', ['hidden_types' => 'Creature,Planeswalker,Instant,Sorcery,Enchantment,Artifact,Land']))
        ->assertInertia(fn ($page) => $page->has('cards.data', 1));
});

it('filters cards by name search', function () {
    Card::factory()->create(['name' => 'Lightning Bolt', 'oracle_id' => 'o-bolt']);
    Card::factory()->create(['name' => 'Counterspell', 'oracle_id' => 'o-cs']);

    $this->get(route('cards.index', ['search' => 'bolt']))
        ->assertInertia(fn ($page) => $page
            ->has('cards.data', 1)
            ->where('cards.data.0.name', 'Lightning Bolt')
        );
});

it('reports the number of cards missing data', function () {
    Card::factory()->create(['name' => 'Complete', 'scryfall_id' => 'sf-1', 'image' => 'http://img']);
    Card::factory()->stub()->create();
    Card::factory()->stub()->create();

    $this->get(route('cards.index'))
        ->assertInertia(fn ($page) => $page->where('missingCount', 2));
});

it('populate endpoint triggers missing card data population', function () {
    Mtgo::shouldReceive('populateMissingCardData')->once()->with(true);

    $this->post(route('cards.populate'))->assertRedirect();
});
