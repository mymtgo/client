<?php

use App\Enums\MatchOutcome;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
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
        'matchRecord' => ['wins', 'losses', 'draws', 'total', 'winrate', 'label'],
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

it('counts draws as matches played in the screenshot winrate and exposes the draw count', function () {
    Storage::fake('cards');

    $deck = Deck::factory()->create(['name' => 'Burn', 'format' => 'Modern']);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id, 'signature' => '']);

    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id]);
    MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'outcome' => MatchOutcome::Draw]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'outcome' => MatchOutcome::Draw]);

    $response = $this->get("/decks/{$deck->id}/screenshot-data");

    $response->assertOk();
    // 1 / 4 matches played.
    expect($response->json('matchRecord.winrate'))->toBe(25);
    expect($response->json('matchRecord.wins'))->toBe(1);
    expect($response->json('matchRecord.losses'))->toBe(1);
    expect($response->json('matchRecord.draws'))->toBe(2);
});
