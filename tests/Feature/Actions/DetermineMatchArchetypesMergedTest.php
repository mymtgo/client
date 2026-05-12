<?php

use App\Actions\DetermineMatchArchetypes;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

it('writes parent archetype_id when detection returns a merged source', function (): void {
    Http::fake([
        '*/api/archetypes/estimate' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    $parent = Archetype::factory()->create([
        'format' => 'modern',
        'decklist_downloaded_at' => now(),
    ]);
    $parentDeck = ArchetypeDeck::factory()->for($parent)->create([
        'last_synced_at' => now(),
    ]);

    $source = Archetype::factory()->create([
        'format' => 'modern',
        'decklist_downloaded_at' => now(),
        'merged_into_id' => $parent->id,
    ]);

    // Make detection identify $source: register a card and attach it to a
    // variant of $source, then attach an opponent whose deck_json contains
    // that card. DetermineDeckArchetype will match $source by signature.
    $card = Card::factory()->create(['mtgo_id' => 99101, 'oracle_id' => 'oracle-merge-1']);
    $sourceDeck = ArchetypeDeck::factory()->for($source)->create();
    $sourceDeck->cards()->attach($card->id, ['quantity' => 4, 'sideboard' => false]);

    $match = MtgoMatch::factory()->create(['format' => 'modern']);
    $game = Game::factory()->create(['match_id' => $match->id]);

    $opponent = Player::factory()->create();
    $local = Player::factory()->create();

    $game->players()->attach($opponent->id, [
        'instance_id' => 1,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
        'deck_json' => [['mtgo_id' => 99101, 'quantity' => 4]],
    ]);

    $game->players()->attach($local->id, [
        'instance_id' => 2,
        'is_local' => true,
        'on_play' => true,
        'starting_hand_size' => 7,
        'deck_json' => [],
    ]);

    DetermineMatchArchetypes::run($match);

    $row = DB::table('match_archetypes')
        ->where('mtgo_match_id', $match->id)
        ->where('player_id', $opponent->id)
        ->first();

    expect($row)->not->toBeNull();
    expect((int) $row->archetype_id)->toBe($parent->id);
    expect((int) $row->archetype_deck_id)->toBe($parentDeck->id);
});

it('does not rewrite pre-existing match_archetypes rows pointing at a merged source when a new match is processed', function (): void {
    Http::fake([
        '*/api/archetypes/estimate' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    $parent = Archetype::factory()->create([
        'format' => 'modern',
        'decklist_downloaded_at' => now(),
    ]);
    $source = Archetype::factory()->create([
        'format' => 'modern',
        'decklist_downloaded_at' => now(),
        'merged_into_id' => $parent->id,
    ]);

    // Pre-existing historical row pointing at $source (before the merge was applied).
    $historicalMatch = MtgoMatch::factory()->create(['format' => 'modern']);
    $existingRowId = DB::table('match_archetypes')->insertGetId([
        'mtgo_match_id' => $historicalMatch->id,
        'archetype_id' => $source->id,
        'player_id' => Player::factory()->create()->id,
        'confidence' => 0.9,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A fresh, separate match processed after the merge — opponent has an empty
    // deck so local detection falls back to homebrew/rogue (null archetype).
    $newMatch = MtgoMatch::factory()->create(['format' => 'modern']);
    $game = Game::factory()->create(['match_id' => $newMatch->id]);

    $opponent = Player::factory()->create();
    $local = Player::factory()->create();

    $game->players()->attach($opponent->id, [
        'instance_id' => 1,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
        'deck_json' => [],
    ]);

    $game->players()->attach($local->id, [
        'instance_id' => 2,
        'is_local' => true,
        'on_play' => true,
        'starting_hand_size' => 7,
        'deck_json' => [],
    ]);

    DetermineMatchArchetypes::run($newMatch);

    // The historical row must be unchanged — still pointing at $source, not $parent.
    $historicalRow = DB::table('match_archetypes')->find($existingRowId);

    expect($historicalRow)->not->toBeNull();
    expect((int) $historicalRow->archetype_id)->toBe($source->id);
});
