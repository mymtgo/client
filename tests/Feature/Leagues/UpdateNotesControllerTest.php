<?php

use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('updates league notes', function () {
    $deck = Deck::factory()->create();
    $dv = DeckVersion::factory()->for($deck)->create();
    $league = League::factory()->for($dv)->create(['notes' => null]);

    $this->patch("/leagues/{$league->id}/notes", ['notes' => 'Wrath into Karn — felt unbeatable.'])
        ->assertRedirect();

    expect($league->fresh()->notes)->toBe('Wrath into Karn — felt unbeatable.');
});

it('clears league notes when null', function () {
    $deck = Deck::factory()->create();
    $dv = DeckVersion::factory()->for($deck)->create();
    $league = League::factory()->for($dv)->create(['notes' => 'old']);

    $this->patch("/leagues/{$league->id}/notes", ['notes' => null])
        ->assertRedirect();

    expect($league->fresh()->notes)->toBeNull();
});

it('rejects notes over 5000 chars', function () {
    $deck = Deck::factory()->create();
    $dv = DeckVersion::factory()->for($deck)->create();
    $league = League::factory()->for($dv)->create();

    $this->patch("/leagues/{$league->id}/notes", ['notes' => str_repeat('a', 5001)])
        ->assertSessionHasErrors('notes');
});
