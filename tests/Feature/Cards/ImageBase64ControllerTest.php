<?php

use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('returns null dataUrl when oracle id has no card', function () {
    $this->getJson(route('cards.image-base64', ['oracleId' => 'missing-oracle-id']))
        ->assertOk()
        ->assertJson(['dataUrl' => null]);
});

it('returns null dataUrl when card has no image source', function () {
    $card = Card::factory()->create([
        'oracle_id' => 'oracle-no-image',
        'image' => null,
        'local_image' => null,
    ]);

    $this->getJson(route('cards.image-base64', ['oracleId' => $card->oracle_id]))
        ->assertOk()
        ->assertJson(['dataUrl' => null]);
});

it('returns base64 data url from local image when present', function () {
    Storage::fake('cards');

    $bytes = File::image('card.jpg', 4, 4)->getContent();
    Storage::disk('cards')->put('cards/abc.jpg', $bytes);

    $card = Card::factory()->create([
        'oracle_id' => 'oracle-local',
        'image' => 'https://example.test/cards/abc.jpg',
        'local_image' => 'cards/abc.jpg',
    ]);

    $response = $this->getJson(route('cards.image-base64', ['oracleId' => $card->oracle_id]))
        ->assertOk();

    $dataUrl = $response->json('dataUrl');

    expect($dataUrl)->toStartWith('data:image/jpeg;base64,');
    expect(base64_decode(substr($dataUrl, strlen('data:image/jpeg;base64,'))))->toBe($bytes);
});
