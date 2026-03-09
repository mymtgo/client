<?php

use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the popout page with deck data', function () {
    $card = Card::factory()->create([
        'oracle_id' => 'oracle-123',
        'name' => 'Lightning Bolt',
        'type' => 'Instant',
        'color_identity' => 'R',
        'image' => 'https://example.com/bolt.jpg',
    ]);

    $deck = Deck::factory()->create([
        'name' => 'Burn Deck',
        'format' => 'Modern',
    ]);

    $signature = base64_encode('oracle-123:4:false');

    DeckVersion::create([
        'deck_id' => $deck->id,
        'signature' => $signature,
        'modified_at' => now(),
    ]);

    $response = $this->get(route('decks.popout', $deck));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('decks/Popout')
        ->where('deckName', 'Burn Deck')
        ->where('format', 'Modern')
    );
});
