<?php

use App\Models\Archetype;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeArchetypeWithVariants(int $count): Archetype
{
    $archetype = Archetype::factory()->create();
    for ($i = 0; $i < $count; $i++) {
        $archetype->decks()->create([
            'uuid' => (string) Str::uuid(),
            'seen_count' => 1,
            'last_synced_at' => now(),
        ]);
    }

    return $archetype->fresh();
}

it('removes a variant when more than one exists', function () {
    $archetype = makeArchetypeWithVariants(2);
    $deck = $archetype->decks->first();

    $this->delete(route('archetypes.variants.destroy', [
        'archetype' => $archetype,
        'deck' => $deck,
    ]))->assertRedirect(route('archetypes.edit', $archetype));

    expect($archetype->fresh()->decks)->toHaveCount(1);
});

it('nulls match_archetypes.archetype_deck_id and preserves archetype_id', function () {
    $archetype = makeArchetypeWithVariants(2);
    $deck = $archetype->decks->first();
    $player = Player::create(['username' => 'p']);
    $match = MtgoMatch::create([
        'token' => fake()->uuid(),
        'mtgo_id' => fake()->unique()->numerify('######'),
        'format' => 'modern',
        'match_type' => 'league',
        'state' => 'complete',
        'outcome' => 'win',
        'started_at' => now(),
        'ended_at' => now(),
    ]);
    $matchArchetype = MatchArchetype::create([
        'archetype_id' => $archetype->id,
        'archetype_deck_id' => $deck->id,
        'mtgo_match_id' => $match->id,
        'player_id' => $player->id,
        'confidence' => 0.9,
    ]);

    $this->delete(route('archetypes.variants.destroy', [
        'archetype' => $archetype,
        'deck' => $deck,
    ]));

    $matchArchetype->refresh();
    expect($matchArchetype->archetype_deck_id)->toBeNull();
    expect($matchArchetype->archetype_id)->toBe($archetype->id);
});

it('422s when the variant is the only one', function () {
    $archetype = makeArchetypeWithVariants(1);
    $deck = $archetype->decks->first();

    $this->delete(route('archetypes.variants.destroy', [
        'archetype' => $archetype,
        'deck' => $deck,
    ]))->assertStatus(422);

    expect($archetype->fresh()->decks)->toHaveCount(1);
});

it('403s when the archetype is a fallback', function () {
    $archetype = Archetype::factory()->create(['is_fallback' => true]);
    $a = $archetype->decks()->create(['uuid' => (string) Str::uuid(), 'seen_count' => 1, 'last_synced_at' => now()]);
    $archetype->decks()->create(['uuid' => (string) Str::uuid(), 'seen_count' => 1, 'last_synced_at' => now()]);

    $this->delete(route('archetypes.variants.destroy', [
        'archetype' => $archetype,
        'deck' => $a,
    ]))->assertForbidden();
});

it('404s when the deck does not belong to the archetype', function () {
    $a1 = makeArchetypeWithVariants(2);
    $a2 = makeArchetypeWithVariants(2);
    $foreignDeck = $a2->decks->first();

    $this->delete(route('archetypes.variants.destroy', [
        'archetype' => $a1,
        'deck' => $foreignDeck,
    ]))->assertNotFound();
});
