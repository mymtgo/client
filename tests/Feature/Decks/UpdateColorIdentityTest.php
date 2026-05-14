<?php

use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('saves a sorted, comma-separated color identity', function () {
    $deck = Deck::factory()->create(['color_identity' => null]);

    $this->patch(route('decks.update-color-identity', $deck), [
        'color_identity' => ['G', 'W', 'U'],
    ])->assertRedirect();

    expect($deck->fresh()->color_identity)->toBe('W,U,G');
});

it('clears color identity when no colors are submitted', function () {
    $deck = Deck::factory()->create(['color_identity' => 'W,U']);

    $this->patch(route('decks.update-color-identity', $deck), [
        'color_identity' => [],
    ])->assertRedirect();

    expect($deck->fresh()->color_identity)->toBeNull();
});

it('deduplicates submitted colors', function () {
    $deck = Deck::factory()->create();

    $this->patch(route('decks.update-color-identity', $deck), [
        'color_identity' => ['R', 'R', 'G'],
    ])->assertRedirect();

    expect($deck->fresh()->color_identity)->toBe('R,G');
});

it('preserves colorless alongside other colors', function () {
    $deck = Deck::factory()->create();

    $this->patch(route('decks.update-color-identity', $deck), [
        'color_identity' => ['C', 'U'],
    ])->assertRedirect();

    expect($deck->fresh()->color_identity)->toBe('U,C');
});

it('keeps colorless when it is the only color', function () {
    $deck = Deck::factory()->create();

    $this->patch(route('decks.update-color-identity', $deck), [
        'color_identity' => ['C'],
    ])->assertRedirect();

    expect($deck->fresh()->color_identity)->toBe('C');
});

it('rejects invalid color values', function () {
    $deck = Deck::factory()->create(['color_identity' => 'W']);

    $this->patch(route('decks.update-color-identity', $deck), [
        'color_identity' => ['X'],
    ])->assertSessionHasErrors('color_identity.0');

    expect($deck->fresh()->color_identity)->toBe('W');
});

it('refuses to update a trashed deck', function () {
    $deck = Deck::factory()->create(['color_identity' => 'W']);
    $deck->delete();

    $this->patch(route('decks.update-color-identity', $deck), [
        'color_identity' => ['U'],
    ])->assertForbidden();

    expect($deck->fresh()->color_identity)->toBe('W');
});
