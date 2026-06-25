<?php

use App\Actions\DetermineMatchArchetypes;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use App\Models\Game;
use App\Models\GameDeck;
use App\Models\MtgoMatch;
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
    $game = Game::factory()->create([
        'match_id' => $match->id,
        'opp_instance' => 1,
        'local_instance' => 2,
        'local_on_play' => true,
    ]);

    GameDeck::create([
        'game_id' => $game->id,
        'is_opponent' => true,
        'deck_json' => [['mtgo_id' => 99101, 'quantity' => 4]],
    ]);

    GameDeck::create([
        'game_id' => $game->id,
        'is_opponent' => false,
        'deck_json' => [],
    ]);

    DetermineMatchArchetypes::run($match);

    $row = DB::table('match_archetypes')
        ->where('mtgo_match_id', $match->id)
        ->where('is_opponent', true)
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
        'is_opponent' => true,
        'confidence' => 0.9,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A fresh, separate match processed after the merge — opponent has an empty
    // deck so local detection falls back to homebrew/rogue (null archetype).
    $newMatch = MtgoMatch::factory()->create(['format' => 'modern']);
    $game = Game::factory()->create([
        'match_id' => $newMatch->id,
        'opp_instance' => 1,
        'local_instance' => 2,
        'local_on_play' => true,
    ]);

    GameDeck::create([
        'game_id' => $game->id,
        'is_opponent' => true,
        'deck_json' => [],
    ]);

    GameDeck::create([
        'game_id' => $game->id,
        'is_opponent' => false,
        'deck_json' => [],
    ]);

    DetermineMatchArchetypes::run($newMatch);

    // The historical row must be unchanged — still pointing at $source, not $parent.
    $historicalRow = DB::table('match_archetypes')->find($existingRowId);

    expect($historicalRow)->not->toBeNull();
    expect((int) $historicalRow->archetype_id)->toBe($source->id);
});
