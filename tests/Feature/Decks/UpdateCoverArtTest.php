<?php

use App\Models\Card;
use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('saves a cover art card', function () {
    $card = Card::factory()->create(['art_crop' => 'https://example.com/art.jpg']);
    $deck = Deck::factory()->create();

    $this->patch(route('decks.update-cover-art', $deck), ['cover_id' => $card->id])
        ->assertRedirect();

    expect($deck->fresh()->cover_id)->toBe($card->id);
});

it('rejects a card without art crop', function () {
    $card = Card::factory()->create(['art_crop' => null]);
    $deck = Deck::factory()->create();

    $this->patch(route('decks.update-cover-art', $deck), ['cover_id' => $card->id])
        ->assertStatus(404);

    expect($deck->fresh()->cover_id)->toBeNull();
});

it('clears cover art when null is sent', function () {
    $card = Card::factory()->create(['art_crop' => 'https://example.com/art.jpg']);
    $deck = Deck::factory()->create(['cover_id' => $card->id]);

    $this->patch(route('decks.update-cover-art', $deck), ['cover_id' => null])
        ->assertRedirect();

    expect($deck->fresh()->cover_id)->toBeNull();
});

it('rejects a non-existent card', function () {
    $deck = Deck::factory()->create();

    $this->patch(route('decks.update-cover-art', $deck), ['cover_id' => 99999])
        ->assertSessionHasErrors('cover_id');
});
