<?php

use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runBackfill(): void
{
    $migration = include database_path('migrations/2026_05_07_120003_backfill_archetype_decks_from_archetype_cards.php');
    $migration::backfill();
}

it('creates one archetype_deck per archetype with cards copied across', function () {
    $archetype = Archetype::factory()->create([
        'decklist_downloaded_at' => now(),
    ]);
    $cards = Card::factory()->count(3)->create();
    $archetype->cards()->attach([
        $cards[0]->id => ['quantity' => 4, 'sideboard' => false],
        $cards[1]->id => ['quantity' => 2, 'sideboard' => false],
        $cards[2]->id => ['quantity' => 1, 'sideboard' => true],
    ]);

    runBackfill();

    $archetype->refresh();

    expect($archetype->decks)->toHaveCount(1);

    $deck = $archetype->decks->first();
    expect($deck->cards)->toHaveCount(3);
    expect($deck->cards->firstWhere('id', $cards[0]->id)->pivot->quantity)->toBe(4);
    expect($deck->cards->firstWhere('id', $cards[2]->id)->pivot->sideboard)->toBeTrue();
});

it('skips archetypes that already have decks (idempotent)', function () {
    $archetype = Archetype::factory()->create();
    ArchetypeDeck::factory()->for($archetype)->create();

    runBackfill();

    $archetype->refresh();
    expect($archetype->decks)->toHaveCount(1);
});

it('skips archetypes with no cards (e.g. fallback Homebrew/Rogue)', function () {
    $archetype = Archetype::factory()->create([
        'is_fallback' => true,
        'decklist_downloaded_at' => null,
    ]);

    runBackfill();

    $archetype->refresh();
    expect($archetype->decks)->toBeEmpty();
});

it('repoints existing match_archetypes at the new deck', function () {
    $archetype = Archetype::factory()->create(['decklist_downloaded_at' => now()]);
    $card = Card::factory()->create();
    $archetype->cards()->attach($card->id, ['quantity' => 4, 'sideboard' => false]);

    $match = MtgoMatch::factory()->create();
    $player = Player::factory()->create();
    $matchArchetype = MatchArchetype::create([
        'archetype_id' => $archetype->id,
        'mtgo_match_id' => $match->id,
        'player_id' => $player->id,
        'confidence' => 0.9,
    ]);

    runBackfill();

    $matchArchetype->refresh();
    $archetype->refresh();
    $deck = $archetype->decks->first();

    expect($matchArchetype->archetype_deck_id)->toBe($deck->id);
});
