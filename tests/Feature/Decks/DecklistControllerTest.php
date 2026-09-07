<?php

use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function makeVersionedDeck(): array
{
    $deck = Deck::factory()->create();

    $oldCard = Card::factory()->create(['name' => 'Old Card', 'oracle_id' => fake()->uuid(), 'type' => 'Creature']);
    $newCard = Card::factory()->create(['name' => 'New Card', 'oracle_id' => fake()->uuid(), 'type' => 'Creature']);

    $oldVersion = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => base64_encode($oldCard->oracle_id.':4:false'),
        'modified_at' => now()->subDay(),
    ]);

    $newVersion = DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => base64_encode($newCard->oracle_id.':4:false'),
        'modified_at' => now(),
    ]);

    return [$deck, $oldVersion, $newVersion];
}

it('renders the latest version decklist by default', function () {
    [$deck] = makeVersionedDeck();

    $this->get(route('decks.decklist', $deck))
        ->assertInertia(fn (Assert $page) => $page
            ->where('maindeck.Creature.0.name', 'New Card')
        );
});

it('renders the requested version decklist when version is given', function () {
    [$deck, $oldVersion] = makeVersionedDeck();

    $this->get(route('decks.decklist', $deck).'?version='.$oldVersion->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('maindeck.Creature.0.name', 'Old Card')
        );
});
