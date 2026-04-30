<?php

use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renames a deck and stores the previous name in original_name', function () {
    $deck = Deck::factory()->create(['name' => 'Poison storm', 'original_name' => null]);

    $this->patch(route('decks.update-name', $deck), ['name' => 'Goblin Aggro'])
        ->assertRedirect();

    $deck->refresh();
    expect($deck->name)->toBe('Goblin Aggro');
    expect($deck->original_name)->toBe('Poison storm');
});

it('preserves the original_name across subsequent renames', function () {
    $deck = Deck::factory()->create(['name' => 'A', 'original_name' => null]);

    $this->patch(route('decks.update-name', $deck), ['name' => 'B'])->assertRedirect();
    $this->patch(route('decks.update-name', $deck), ['name' => 'C'])->assertRedirect();

    $deck->refresh();
    expect($deck->name)->toBe('C');
    expect($deck->original_name)->toBe('A');
});

it('clears original_name when reverting to the original', function () {
    $deck = Deck::factory()->create(['name' => 'Custom', 'original_name' => 'MTGO Name']);

    $this->patch(route('decks.update-name', $deck), ['name' => 'MTGO Name'])
        ->assertRedirect();

    $deck->refresh();
    expect($deck->name)->toBe('MTGO Name');
    expect($deck->original_name)->toBeNull();
});

it('trims whitespace and ignores no-op renames', function () {
    $deck = Deck::factory()->create(['name' => 'Foo', 'original_name' => null]);

    $this->patch(route('decks.update-name', $deck), ['name' => '  Foo  '])
        ->assertRedirect();

    $deck->refresh();
    expect($deck->name)->toBe('Foo');
    expect($deck->original_name)->toBeNull();
});

it('rejects an empty name', function () {
    $deck = Deck::factory()->create(['name' => 'Foo']);

    $this->patch(route('decks.update-name', $deck), ['name' => ''])
        ->assertSessionHasErrors('name');

    expect($deck->fresh()->name)->toBe('Foo');
});

it('does not enforce uniqueness on the deck name', function () {
    Deck::factory()->create(['name' => 'Shared']);
    $deck = Deck::factory()->create(['name' => 'Foo']);

    $this->patch(route('decks.update-name', $deck), ['name' => 'Shared'])
        ->assertRedirect();

    expect($deck->fresh()->name)->toBe('Shared');
});
