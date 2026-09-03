<?php

use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('returns the league deck cover art as a data url from local storage', function () {
    Storage::fake('cards');
    Storage::disk('cards')->put('art/abc.jpg', File::image('abc.jpg', 4, 4)->getContent());

    $cover = Card::factory()->create([
        'art_crop' => 'https://cards.example.test/art/abc.jpg',
        'local_art_crop' => 'art/abc.jpg',
    ]);
    $deck = Deck::factory()->create(['cover_id' => $cover->id]);
    $dv = DeckVersion::factory()->for($deck)->create();
    $league = League::factory()->for($dv)->create();

    $response = $this->getJson(route('leagues.screenshot-data', $league))->assertOk();

    expect($response->json('coverArtBase64'))->toStartWith('data:image/jpeg;base64,');
});

it('returns a null cover when the league deck has no cover art', function () {
    $deck = Deck::factory()->create(['cover_id' => null]);
    $dv = DeckVersion::factory()->for($deck)->create();
    $league = League::factory()->for($dv)->create();

    $this->getJson(route('leagues.screenshot-data', $league))
        ->assertOk()
        ->assertJson(['coverArtBase64' => null]);
});
