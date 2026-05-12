<?php

use App\Models\Archetype;
use App\Models\Card;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates archetype_decks table with expected columns', function () {
    expect(Schema::hasTable('archetype_decks'))->toBeTrue();
    expect(Schema::hasColumns('archetype_decks', [
        'id', 'uuid', 'archetype_id', 'seen_count', 'last_synced_at', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('cascades delete from archetypes', function () {
    $archetype = Archetype::factory()->create();
    $deckId = DB::table('archetype_decks')->insertGetId([
        'uuid' => '11111111-1111-1111-1111-111111111111',
        'archetype_id' => $archetype->id,
        'seen_count' => 1,
        'last_synced_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $archetype->delete();

    expect(DB::table('archetype_decks')->where('id', $deckId)->exists())->toBeFalse();
});

it('has composite index on archetype_id and seen_count', function () {
    $indexes = Schema::getIndexes('archetype_decks');

    $hasComposite = collect($indexes)->contains(function ($index) {
        $columns = $index['columns'] ?? [];

        return in_array('archetype_id', $columns, true)
            && in_array('seen_count', $columns, true);
    });

    expect($hasComposite)->toBeTrue();
});

it('creates archetype_deck_cards table with expected columns', function () {
    expect(Schema::hasTable('archetype_deck_cards'))->toBeTrue();
    expect(Schema::hasColumns('archetype_deck_cards', [
        'id', 'archetype_deck_id', 'card_id', 'quantity', 'sideboard',
    ]))->toBeTrue();
});

it('enforces unique deck/card/sideboard combo', function () {
    $archetype = Archetype::factory()->create();
    $card = Card::factory()->create();

    $deckId = DB::table('archetype_decks')->insertGetId([
        'uuid' => '22222222-2222-2222-2222-222222222222',
        'archetype_id' => $archetype->id,
        'seen_count' => 1,
        'last_synced_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('archetype_deck_cards')->insert([
        'archetype_deck_id' => $deckId,
        'card_id' => $card->id,
        'quantity' => 4,
        'sideboard' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('archetype_deck_cards')->insert([
        'archetype_deck_id' => $deckId,
        'card_id' => $card->id,
        'quantity' => 2,
        'sideboard' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('cascades delete from archetype_decks to archetype_deck_cards', function () {
    $archetype = Archetype::factory()->create();
    $card = Card::factory()->create();

    $deckId = DB::table('archetype_decks')->insertGetId([
        'uuid' => '33333333-3333-3333-3333-333333333333',
        'archetype_id' => $archetype->id,
        'seen_count' => 1,
        'last_synced_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pivotId = DB::table('archetype_deck_cards')->insertGetId([
        'archetype_deck_id' => $deckId,
        'card_id' => $card->id,
        'quantity' => 4,
        'sideboard' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('archetype_decks')->where('id', $deckId)->delete();

    expect(DB::table('archetype_deck_cards')->where('id', $pivotId)->exists())->toBeFalse();
});

it('adds archetype_deck_id to match_archetypes', function () {
    expect(Schema::hasColumn('match_archetypes', 'archetype_deck_id'))->toBeTrue();
});
