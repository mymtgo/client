<?php

use App\Actions\Overlay\GetArchetypeNotes;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckArchetypeNote;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('splits notes into this deck and other decks, newest first', function () {
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink', 'format' => 'modern']);
    $otherArchetype = Archetype::factory()->create(['name' => 'Burn', 'format' => 'modern']);

    $deck = Deck::factory()->create(['name' => 'My Murktide']);
    $otherDeck = Deck::factory()->create(['name' => 'My Burn']);

    $older = DeckArchetypeNote::factory()->create([
        'deck_id' => $deck->id, 'archetype_id' => $archetype->id,
        'body' => 'Older note', 'created_at' => now()->subDay(),
    ]);

    $newer = DeckArchetypeNote::factory()->create([
        'deck_id' => $deck->id, 'archetype_id' => $archetype->id,
        'body' => 'Newer note', 'created_at' => now(),
    ]);

    DeckArchetypeNote::factory()->create([
        'deck_id' => $otherDeck->id, 'archetype_id' => $archetype->id, 'body' => 'From the other deck',
    ]);

    DeckArchetypeNote::factory()->create([
        'deck_id' => $deck->id, 'archetype_id' => $otherArchetype->id, 'body' => 'Different matchup',
    ]);

    $result = GetArchetypeNotes::run($deck, $archetype);

    expect(collect($result['current'])->pluck('id')->all())->toBe([$newer->id, $older->id]);
    expect($result['other'])->toHaveCount(1);
    expect($result['other'][0]->body)->toBe('From the other deck');
    expect($result['other'][0]->deckName)->toBe('My Burn');
});

it('returns empty groups when the archetype has no notes', function () {
    $result = GetArchetypeNotes::run(Deck::factory()->create(), Archetype::factory()->create());

    expect($result['current'])->toBe([]);
    expect($result['other'])->toBe([]);
});
