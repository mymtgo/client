<?php

use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake());

it('shows the dashboard for a soft-deleted deck', function () {
    $deck = Deck::factory()->create();
    $deck->delete();

    $response = $this->get(route('decks.show', ['deck' => $deck->id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('decks/Dashboard')
        ->where('deck.id', $deck->id)
        ->where('deck.deletedAt', fn ($v) => $v !== null)
    );
});

it('shows the matches page for a soft-deleted deck', function () {
    $deck = Deck::factory()->create();
    $deck->delete();

    $this->get(route('decks.matches', ['deck' => $deck->id]))->assertOk();
});

it('shows the decklist page for a soft-deleted deck', function () {
    $deck = Deck::factory()->create();
    $deck->delete();

    $this->get(route('decks.decklist', ['deck' => $deck->id]))->assertOk();
});
