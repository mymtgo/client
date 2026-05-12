<?php

use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('archetype has many decks', function () {
    $archetype = Archetype::factory()->create();
    ArchetypeDeck::factory()->for($archetype)->count(2)->create();

    expect($archetype->decks)->toHaveCount(2);
});

it('deck has cards via pivot with quantity and sideboard', function () {
    $archetype = Archetype::factory()->create();
    $deck = ArchetypeDeck::factory()->for($archetype)->create();
    $card = Card::factory()->create();

    $deck->cards()->attach($card->id, ['quantity' => 3, 'sideboard' => true]);

    $deck->refresh();
    expect($deck->cards)->toHaveCount(1);
    expect($deck->cards->first()->pivot->quantity)->toBe(3);
    expect($deck->cards->first()->pivot->sideboard)->toBeTrue();
});

it('deck belongs to archetype', function () {
    $archetype = Archetype::factory()->create();
    $deck = ArchetypeDeck::factory()->for($archetype)->create();

    expect($deck->archetype->is($archetype))->toBeTrue();
});

it('match_archetype belongs to archetype_deck', function () {
    $archetype = Archetype::factory()->create();
    $deck = ArchetypeDeck::factory()->for($archetype)->create();
    $match = MtgoMatch::factory()->create();
    $player = Player::factory()->create();

    $matchArchetype = MatchArchetype::create([
        'archetype_id' => $archetype->id,
        'archetype_deck_id' => $deck->id,
        'mtgo_match_id' => $match->id,
        'player_id' => $player->id,
        'confidence' => 0.85,
    ]);

    expect($matchArchetype->archetypeDeck->is($deck))->toBeTrue();
});
