<?php

use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('returns screenshot data with base64 card images', function () {
    Storage::fake('cards');

    $card = Card::factory()->create([
        'oracle_id' => 'test-oracle-id',
        'name' => 'Lightning Bolt',
        'type' => 'Instant',
        'color_identity' => 'R',
        'cmc' => 1,
        'local_image' => 'bolt.jpg',
        'image' => 'https://example.com/bolt.jpg',
    ]);

    // Put a fake image on disk
    Storage::disk('cards')->put('bolt.jpg', 'fake-image-content');

    $deck = Deck::factory()->create([
        'name' => 'Burn',
        'format' => 'Modern',
        'cover_id' => $card->id,
    ]);

    $signature = base64_encode("{$card->oracle_id}:4:false");

    DeckVersion::factory()->create([
        'deck_id' => $deck->id,
        'signature' => $signature,
    ]);

    $response = $this->get("/decks/{$deck->id}/screenshot-data");

    $response->assertOk();
    $response->assertJsonStructure([
        'name',
        'format',
        'colorIdentity',
        'winRate',
        'matchesWon',
        'matchesLost',
        'coverArtBase64',
        'nonLandCards' => [['name', 'type', 'quantity', 'imageBase64']],
        'landCards',
        'sideboardCards',
        'cmcDistribution',
        'typeDistribution',
    ]);

    // Check base64 encoding worked
    $nonLand = $response->json('nonLandCards');
    expect($nonLand)->toHaveCount(1);
    expect($nonLand[0]['name'])->toBe('Lightning Bolt');
    expect($nonLand[0]['quantity'])->toBe(4);
    expect($nonLand[0]['imageBase64'])->toStartWith('data:image/jpeg;base64,');
});

it('returns empty arrays when deck has no version', function () {
    $deck = Deck::factory()->create();

    $response = $this->get("/decks/{$deck->id}/screenshot-data");

    $response->assertOk();
    $response->assertJson([
        'nonLandCards' => [],
        'landCards' => [],
        'sideboardCards' => [],
    ]);
});
