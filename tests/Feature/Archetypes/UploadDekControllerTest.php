<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());
});

it('parses an uploaded dek file and returns resolved cards', function () {
    Http::fake([
        '*/api/cards/resolve' => Http::response([
            'cards' => [
                [
                    'mtgo_id' => 12345,
                    'oracle_id' => 'oracle-bolt',
                    'name' => 'Lightning Bolt',
                    'type' => 'Instant',
                    'image' => 'https://example.com/bolt.jpg',
                    'art_crop' => null,
                    'cmc' => 1,
                    'identity' => 'R',
                ],
            ],
        ]),
    ]);

    $dekContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Deck xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <NetDeckID>0</NetDeckID>
  <PreconstructedDeckID>0</PreconstructedDeckID>
  <Cards CatID="12345" Quantity="4" Sideboard="false" Name="Lightning Bolt" Annotation="0"/>
</Deck>
XML;

    $file = UploadedFile::fake()->createWithContent('deck.dek', $dekContent);

    $response = $this->postJson('/archetypes/upload-dek', [
        'dek_file' => $file,
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'cards' => [['mtgo_id', 'name', 'type', 'quantity', 'sideboard']],
        'color_identity',
    ]);
    $response->assertJsonPath('cards.0.name', 'Lightning Bolt');
    $response->assertJsonPath('color_identity', 'R');
});

it('rejects non-dek files', function () {
    $file = UploadedFile::fake()->create('deck.txt', 100);

    $response = $this->postJson('/archetypes/upload-dek', [
        'dek_file' => $file,
    ]);

    $response->assertUnprocessable();
});

it('rejects missing file', function () {
    $response = $this->postJson('/archetypes/upload-dek', []);

    $response->assertUnprocessable();
});
