<?php

use App\Models\Card;
use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns cards matching the name with art crop', function () {
    $deck = Deck::factory()->create();
    Card::factory()->create(['name' => 'Lightning Bolt', 'art_crop' => 'https://example.com/bolt1.jpg']);
    Card::factory()->create(['name' => 'Lightning Bolt', 'art_crop' => 'https://example.com/bolt2.jpg']);
    Card::factory()->create(['name' => 'Lightning Bolt', 'art_crop' => null]);

    $this->getJson(route('decks.cover-art-options', ['deck' => $deck->id, 'card_name' => 'Lightning Bolt']))
        ->assertOk()
        ->assertJsonCount(2);
});

it('returns empty array for card with no art crops', function () {
    $deck = Deck::factory()->create();
    Card::factory()->create(['name' => 'Mountain', 'art_crop' => null]);

    $this->getJson(route('decks.cover-art-options', ['deck' => $deck->id, 'card_name' => 'Mountain']))
        ->assertOk()
        ->assertJsonCount(0);
});

it('requires card_name parameter', function () {
    $deck = Deck::factory()->create();

    $this->getJson(route('decks.cover-art-options', $deck))
        ->assertJsonValidationErrors('card_name');
});
