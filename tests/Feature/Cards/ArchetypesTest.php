<?php

use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function attachCard(Archetype $archetype, Card $card, bool $sideboard): void
{
    ArchetypeDeck::factory()->for($archetype)->create()
        ->cards()->attach($card, ['quantity' => 4, 'sideboard' => $sideboard]);
}

it('lists the archetypes a card appears in with maindeck/sideboard placement', function () {
    $card = Card::factory()->create(['oracle_id' => 'o-bolt']);
    $burn = Archetype::factory()->create(['name' => 'Burn', 'format' => 'modern']);
    $control = Archetype::factory()->create(['name' => 'Control', 'format' => 'modern']);

    attachCard($burn, $card, sideboard: false);     // maindeck
    attachCard($control, $card, sideboard: true);    // sideboard

    $this->getJson(route('cards.archetypes', ['group' => 'o-bolt']))
        ->assertOk()
        ->assertJsonCount(2, 'archetypes')
        ->assertJsonPath('archetypes.0.name', 'Burn')
        ->assertJsonPath('archetypes.0.maindeck', true)
        ->assertJsonPath('archetypes.0.sideboard', false)
        ->assertJsonPath('archetypes.1.name', 'Control')
        ->assertJsonPath('archetypes.1.maindeck', false)
        ->assertJsonPath('archetypes.1.sideboard', true);
});

it('flags an archetype that runs the card both main and side', function () {
    $card = Card::factory()->create(['oracle_id' => 'o-mix']);
    $archetype = Archetype::factory()->create(['name' => 'Midrange']);

    attachCard($archetype, $card, sideboard: false);
    attachCard($archetype, $card, sideboard: true);

    $this->getJson(route('cards.archetypes', ['group' => 'o-mix']))
        ->assertOk()
        ->assertJsonCount(1, 'archetypes')
        ->assertJsonPath('archetypes.0.maindeck', true)
        ->assertJsonPath('archetypes.0.sideboard', true)
        ->assertJsonPath('archetypes.0.deckCount', 2);
});

it('aggregates archetypes across every printing of an oracle_id', function () {
    $old = Card::factory()->create(['oracle_id' => 'o-bs', 'set_code' => 'ICE']);
    $new = Card::factory()->create(['oracle_id' => 'o-bs', 'set_code' => 'MH2']);
    $archetype = Archetype::factory()->create(['name' => 'Tempo']);

    attachCard($archetype, $old, sideboard: false);
    attachCard($archetype, $new, sideboard: false);

    $this->getJson(route('cards.archetypes', ['group' => 'o-bs']))
        ->assertOk()
        ->assertJsonCount(1, 'archetypes')
        ->assertJsonPath('archetypes.0.deckCount', 2);
});

it('scopes the archetype list to a given format', function () {
    $card = Card::factory()->create(['oracle_id' => 'o-getlost']);
    $modern = Archetype::factory()->create(['name' => 'Modern Deck', 'format' => 'modern']);
    $legacy = Archetype::factory()->create(['name' => 'Legacy Deck', 'format' => 'legacy']);

    attachCard($modern, $card, sideboard: false);
    attachCard($legacy, $card, sideboard: false);

    $this->getJson(route('cards.archetypes', ['group' => 'o-getlost', 'format' => 'modern']))
        ->assertOk()
        ->assertJsonCount(1, 'archetypes')
        ->assertJsonPath('archetypes.0.name', 'Modern Deck');
});

it('resolves a printing with no oracle_id by its id', function () {
    $card = Card::factory()->create(['oracle_id' => null]);
    $archetype = Archetype::factory()->create(['name' => 'Rogue']);

    attachCard($archetype, $card, sideboard: false);

    $this->getJson(route('cards.archetypes', ['group' => 'id:'.$card->id]))
        ->assertOk()
        ->assertJsonCount(1, 'archetypes')
        ->assertJsonPath('archetypes.0.name', 'Rogue');
});
