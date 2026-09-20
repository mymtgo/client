<?php

use App\Models\Archetype;
use App\Models\Card;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runManualArchetypeVariantsMigration(): void
{
    $migration = require database_path('migrations/2026_09_20_074128_backfill_manual_archetype_variants.php');
    $migration->up();
}

function manualArchetypeWithLegacyCards(): Archetype
{
    $archetype = Archetype::factory()->create(['manual' => true]);
    $card = Card::create([
        'oracle_id' => 'oracle-bolt',
        'mtgo_id' => 12345,
        'name' => 'Lightning Bolt',
        'type' => 'Instant',
    ]);

    $archetype->cards()->sync([
        $card->id => ['quantity' => 4, 'sideboard' => false],
    ]);

    return $archetype;
}

it('rebuilds a variant from the legacy pivot', function () {
    $archetype = manualArchetypeWithLegacyCards();

    runManualArchetypeVariantsMigration();

    $deck = $archetype->decks()->first();

    expect($deck)->not->toBeNull();
    expect($deck->cards)->toHaveCount(1);
    expect($deck->cards->first()->pivot->quantity)->toBe(4);
});

it('backfills archetype_deck_id on existing match archetypes', function () {
    $archetype = manualArchetypeWithLegacyCards();
    $match = MtgoMatch::factory()->create();
    $player = Player::create(['username' => 'opponent']);

    MatchArchetype::create([
        'archetype_id' => $archetype->id,
        'mtgo_match_id' => $match->id,
        'player_id' => $player->id,
        'confidence' => 1,
    ]);

    runManualArchetypeVariantsMigration();

    expect(MatchArchetype::first()->archetype_deck_id)->toBe($archetype->decks()->first()->id);
});

it('is safe to run twice', function () {
    $archetype = manualArchetypeWithLegacyCards();

    runManualArchetypeVariantsMigration();
    runManualArchetypeVariantsMigration();

    expect($archetype->decks()->count())->toBe(1);
});

it('leaves archetypes that already have variants alone', function () {
    $archetype = manualArchetypeWithLegacyCards();
    runManualArchetypeVariantsMigration();
    $deckId = $archetype->decks()->first()->id;

    runManualArchetypeVariantsMigration();

    expect($archetype->decks()->count())->toBe(1);
    expect($archetype->decks()->first()->id)->toBe($deckId);
});
