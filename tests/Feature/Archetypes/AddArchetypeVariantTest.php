<?php

use App\Actions\Archetypes\AddArchetypeVariant;
use App\Exceptions\DuplicateVariantException;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a new ArchetypeDeck with synced cards', function () {
    $archetype = Archetype::factory()->create();
    $card = Card::factory()->create(['oracle_id' => 'oracle-1']);

    $deck = AddArchetypeVariant::run($archetype, [
        ['oracle_id' => 'oracle-1', 'mtgo_id' => 1, 'quantity' => 4, 'sideboard' => false],
    ]);

    expect($deck)->toBeInstanceOf(ArchetypeDeck::class);
    expect($deck->archetype_id)->toBe($archetype->id);
    expect($deck->seen_count)->toBe(1);
    expect($deck->last_synced_at)->not->toBeNull();
    expect($deck->cards)->toHaveCount(1);
    expect($deck->cards->first()->id)->toBe($card->id);
    expect($deck->cards->first()->pivot->quantity)->toBe(4);
});

it('skips cards with no oracle_id', function () {
    $archetype = Archetype::factory()->create();
    Card::factory()->create(['oracle_id' => 'oracle-1']);

    $deck = AddArchetypeVariant::run($archetype, [
        ['oracle_id' => 'oracle-1', 'mtgo_id' => 1, 'quantity' => 4, 'sideboard' => false],
        ['oracle_id' => null, 'mtgo_id' => 2, 'quantity' => 1, 'sideboard' => false],
    ]);

    expect($deck->cards)->toHaveCount(1);
});

it('skips cards whose oracle_id has no Card row', function () {
    $archetype = Archetype::factory()->create();
    Card::factory()->create(['oracle_id' => 'oracle-1']);

    $deck = AddArchetypeVariant::run($archetype, [
        ['oracle_id' => 'oracle-1', 'mtgo_id' => 1, 'quantity' => 4, 'sideboard' => false],
        ['oracle_id' => 'oracle-missing', 'mtgo_id' => 99, 'quantity' => 1, 'sideboard' => true],
    ]);

    expect($deck->cards)->toHaveCount(1);
});

it('throws DuplicateVariantException when an identical variant exists', function () {
    $archetype = Archetype::factory()->create();
    Card::factory()->create(['oracle_id' => 'oracle-1']);

    AddArchetypeVariant::run($archetype, [
        ['oracle_id' => 'oracle-1', 'mtgo_id' => 1, 'quantity' => 4, 'sideboard' => false],
    ]);

    AddArchetypeVariant::run($archetype, [
        ['oracle_id' => 'oracle-1', 'mtgo_id' => 1, 'quantity' => 4, 'sideboard' => false],
    ]);
})->throws(DuplicateVariantException::class);

it('allows two variants that differ only in quantity', function () {
    $archetype = Archetype::factory()->create();
    Card::factory()->create(['oracle_id' => 'oracle-1']);

    AddArchetypeVariant::run($archetype, [
        ['oracle_id' => 'oracle-1', 'mtgo_id' => 1, 'quantity' => 4, 'sideboard' => false],
    ]);

    AddArchetypeVariant::run($archetype, [
        ['oracle_id' => 'oracle-1', 'mtgo_id' => 1, 'quantity' => 3, 'sideboard' => false],
    ]);

    expect($archetype->fresh()->decks)->toHaveCount(2);
});

it('treats sideboard differences as distinct variants', function () {
    $archetype = Archetype::factory()->create();
    Card::factory()->create(['oracle_id' => 'oracle-1']);

    AddArchetypeVariant::run($archetype, [
        ['oracle_id' => 'oracle-1', 'mtgo_id' => 1, 'quantity' => 4, 'sideboard' => false],
    ]);

    AddArchetypeVariant::run($archetype, [
        ['oracle_id' => 'oracle-1', 'mtgo_id' => 1, 'quantity' => 4, 'sideboard' => true],
    ]);

    expect($archetype->fresh()->decks)->toHaveCount(2);
});
