<?php

use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('soft deletes a deck when DELETE is confirmed and returns to the listing', function () {
    $deck = Deck::factory()->create();

    $this->delete(route('decks.destroy', ['deck' => $deck->id]), ['confirmation' => 'DELETE'])
        ->assertRedirect(route('decks.index'));

    expect($deck->fresh()->trashed())->toBeTrue();
});

it('refuses to delete a deck without the DELETE confirmation', function (?string $confirmation) {
    $deck = Deck::factory()->create();

    $this->from(route('decks.settings', ['deck' => $deck->id]))
        ->delete(route('decks.destroy', ['deck' => $deck->id]), array_filter(['confirmation' => $confirmation]))
        ->assertSessionHasErrors('confirmation');

    expect($deck->fresh()->trashed())->toBeFalse();
})->with([
    'missing' => [null],
    'wrong keyword' => ['delete me'],
    'lowercase' => ['delete'],
]);

it('restores a deleted deck', function () {
    $deck = Deck::factory()->create();
    $deck->delete();

    $this->from(route('decks.settings', ['deck' => $deck->id]))
        ->patch(route('decks.restore', ['deck' => $deck->id]))
        ->assertRedirect(route('decks.settings', ['deck' => $deck->id]));

    expect($deck->fresh()->trashed())->toBeFalse();
});

it('keeps deck versions and matches when a deck is deleted', function () {
    $deck = Deck::factory()->create();
    $version = $deck->versions()->create(['signature' => 'sig', 'modified_at' => now()]);

    $this->delete(route('decks.destroy', ['deck' => $deck->id]), ['confirmation' => 'DELETE'])
        ->assertRedirect(route('decks.index'));

    expect($deck->fresh()->trashed())->toBeTrue();
    expect($version->fresh())->not->toBeNull();
});

it('leaves an already deleted deck deleted without changing its timestamp', function () {
    $deck = Deck::factory()->create();
    $deck->delete();
    $deletedAt = $deck->fresh()->deleted_at;

    $this->delete(route('decks.destroy', ['deck' => $deck->id]), ['confirmation' => 'DELETE'])
        ->assertRedirect(route('decks.index'));

    expect($deck->fresh()->deleted_at->eq($deletedAt))->toBeTrue();
});
