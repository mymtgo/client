<?php

use App\Actions\Archetypes\RemoveArchetypeVariant;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeMatch(): MtgoMatch
{
    return MtgoMatch::create([
        'token' => fake()->uuid(),
        'mtgo_id' => fake()->unique()->numerify('######'),
        'format' => 'modern',
        'match_type' => 'league',
        'state' => 'complete',
        'outcome' => 'win',
        'started_at' => now(),
        'ended_at' => now(),
    ]);
}

it('deletes the deck and its pivot rows', function () {
    $archetype = Archetype::factory()->create();
    $card = Card::factory()->create();
    $deck = $archetype->decks()->create([
        'uuid' => (string) Str::uuid(),
        'seen_count' => 1,
        'last_synced_at' => now(),
    ]);
    $deck->cards()->attach($card->id, ['quantity' => 4, 'sideboard' => false]);

    RemoveArchetypeVariant::run($archetype, $deck);

    expect(ArchetypeDeck::find($deck->id))->toBeNull();
    expect($archetype->fresh()->decks)->toHaveCount(0);
});

it('nulls archetype_deck_id on match_archetypes but preserves archetype_id', function () {
    $archetype = Archetype::factory()->create();
    $deck = $archetype->decks()->create([
        'uuid' => (string) Str::uuid(),
        'seen_count' => 1,
        'last_synced_at' => now(),
    ]);
    $match = makeMatch();

    $matchArchetype = MatchArchetype::create([
        'archetype_id' => $archetype->id,
        'archetype_deck_id' => $deck->id,
        'mtgo_match_id' => $match->id,
        'confidence' => 0.9,
    ]);

    RemoveArchetypeVariant::run($archetype, $deck);

    $matchArchetype->refresh();
    expect($matchArchetype->archetype_deck_id)->toBeNull();
    expect($matchArchetype->archetype_id)->toBe($archetype->id);
});
