<?php

use App\Models\Archetype;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates dek file and triggers save dialog', function () {
    $archetype = Archetype::factory()->withDecklist()->create();
    $card = Card::factory()->create([
        'mtgo_id' => 12345,
        'oracle_id' => 'test-oracle',
        'name' => 'Lightning Bolt',
        'type' => 'Instant',
    ]);
    $archetype->cards()->attach($card->id, ['quantity' => 4, 'sideboard' => false]);

    $response = $this->post("/archetypes/{$archetype->id}/export");

    $response->assertRedirect();
});

it('redirects back when dialog is cancelled', function () {
    $archetype = Archetype::factory()->withDecklist()->create();

    $response = $this->post("/archetypes/{$archetype->id}/export");

    $response->assertRedirect();
});
