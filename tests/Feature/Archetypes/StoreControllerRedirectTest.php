<?php

use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeCardPayload(): array
{
    Card::create([
        'oracle_id' => 'oracle-bolt',
        'mtgo_id' => 12345,
        'name' => 'Lightning Bolt',
        'type' => 'Instant',
    ]);

    return [[
        'mtgo_id' => 12345,
        'oracle_id' => 'oracle-bolt',
        'name' => 'Lightning Bolt',
        'type' => 'Instant',
        'quantity' => 4,
        'sideboard' => false,
    ]];
}

it('redirects to deck matches page when source match has a deck', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $match = MtgoMatch::factory()->create(['deck_version_id' => $version->id]);

    $response = $this->post('/archetypes', [
        'name' => 'From Match',
        'format' => 'modern',
        'cards' => makeCardPayload(),
        'source_match_id' => $match->id,
        'incomplete' => true,
    ]);

    $response->assertRedirect(route('decks.matches', ['deck' => $deck->id]));
});

it('falls back to archetypes.show when source match has no deck', function () {
    $match = MtgoMatch::factory()->create(['deck_version_id' => null]);

    $response = $this->post('/archetypes', [
        'name' => 'From Match',
        'format' => 'modern',
        'cards' => makeCardPayload(),
        'source_match_id' => $match->id,
        'incomplete' => true,
    ]);

    $response->assertRedirectContains('/archetypes/');
    expect($response->headers->get('Location'))->not->toContain('/decks/');
});

it('still redirects to archetypes.show when no source_match_id', function () {
    $response = $this->post('/archetypes', [
        'name' => 'Manual',
        'format' => 'modern',
        'cards' => makeCardPayload(),
    ]);

    $response->assertRedirectContains('/archetypes/');
});
