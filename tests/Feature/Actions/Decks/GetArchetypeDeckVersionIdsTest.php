<?php

use App\Actions\Decks\GetArchetypeDeckVersionIds;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns every version of every deck in the archetype', function () {
    $archetype = Archetype::factory()->create(['format' => 'modern']);
    $deckA = Deck::factory()->create(['archetype_id' => $archetype->id, 'format' => 'CModern']);
    $deckB = Deck::factory()->create(['archetype_id' => $archetype->id, 'format' => 'CModern']);
    $other = Deck::factory()->create(['format' => 'CModern']);

    $a1 = DeckVersion::factory()->create(['deck_id' => $deckA->id]);
    $a2 = DeckVersion::factory()->create(['deck_id' => $deckA->id]);
    $b1 = DeckVersion::factory()->create(['deck_id' => $deckB->id]);
    DeckVersion::factory()->create(['deck_id' => $other->id]);

    $ids = GetArchetypeDeckVersionIds::run($archetype, null, false);

    expect($ids)->toEqualCanonicalizing([$a1->id, $a2->id, $b1->id]);
});

it('drops archived decks when hide-archived is on', function () {
    $archetype = Archetype::factory()->create(['format' => 'modern']);
    $live = Deck::factory()->create(['archetype_id' => $archetype->id, 'format' => 'CModern']);
    $archived = Deck::factory()->create(['archetype_id' => $archetype->id, 'format' => 'CModern']);
    $liveVersion = DeckVersion::factory()->create(['deck_id' => $live->id]);
    DeckVersion::factory()->create(['deck_id' => $archived->id]);
    $archived->delete();

    expect(GetArchetypeDeckVersionIds::run($archetype, null, true))->toBe([$liveVersion->id])
        ->and(GetArchetypeDeckVersionIds::run($archetype, null, false))->toHaveCount(2);
});

it('respects the format filter', function () {
    $archetype = Archetype::factory()->create(['format' => 'modern']);
    $deck = Deck::factory()->create(['archetype_id' => $archetype->id, 'format' => 'CModern']);
    DeckVersion::factory()->create(['deck_id' => $deck->id]);

    expect(GetArchetypeDeckVersionIds::run($archetype, 'CPioneer', false))->toBe([]);
});
