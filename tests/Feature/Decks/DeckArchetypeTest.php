<?php

use App\Actions\Decks\SyncDecks;
use App\Actions\DetermineMatchArchetypes;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Clear the global Http::fake() from Pest.php beforeEach so test-specific
// fakes take priority. Re-add a catch-all '*' in each test's fake array
// to keep NativePHP facades happy.
beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

it('can assign an archetype to a deck', function () {
    $archetype = Archetype::factory()->create(['name' => 'Eldrazi Ramp', 'format' => 'modern']);
    $deck = Deck::factory()->create();

    $deck->update(['archetype_id' => $archetype->id]);

    expect($deck->fresh()->archetype->name)->toBe('Eldrazi Ramp');
});

it('nullifies archetype_id when archetype is deleted', function () {
    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['archetype_id' => $archetype->id]);

    $archetype->delete();

    expect($deck->fresh()->archetype_id)->toBeNull();
});

it('prefills archetype on sync when archetype_id is null', function () {
    $archetype = Archetype::factory()->create([
        'name' => 'Tron',
        'format' => 'modern',
        'uuid' => 'tron-uuid',
    ]);
    $deck = Deck::factory()->create(['format' => 'CMODERN', 'archetype_id' => null]);
    DeckVersion::factory()->create(['deck_id' => $deck->id]);

    Http::fake([
        '*/api/archetypes/estimate' => Http::response([
            ['uuid' => 'tron-uuid', 'confidence' => 0.95],
        ]),
        '*' => Http::response([]),
    ]);

    SyncDecks::prefillArchetype($deck);

    expect($deck->fresh()->archetype_id)->toBe($archetype->id);
});

it('does not overwrite existing archetype on sync', function () {
    $existingArchetype = Archetype::factory()->create(['name' => 'Eldrazi Ramp']);
    $deck = Deck::factory()->create(['archetype_id' => $existingArchetype->id]);
    DeckVersion::factory()->create(['deck_id' => $deck->id]);

    Http::fake([
        '*/api/archetypes/estimate' => Http::response([], 500),
        '*' => Http::response([]),
    ]);

    SyncDecks::prefillArchetype($deck);

    expect($deck->fresh()->archetype_id)->toBe($existingArchetype->id);
});

it('can update deck archetype via PATCH endpoint', function () {
    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create();

    $response = $this->patch("/decks/{$deck->id}/archetype", [
        'archetype_id' => $archetype->id,
    ]);

    $response->assertRedirect();
    expect($deck->fresh()->archetype_id)->toBe($archetype->id);
});

it('can clear deck archetype via PATCH endpoint', function () {
    $archetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['archetype_id' => $archetype->id]);

    $response = $this->patch("/decks/{$deck->id}/archetype", [
        'archetype_id' => null,
    ]);

    $response->assertRedirect();
    expect($deck->fresh()->archetype_id)->toBeNull();
});

it('uses deck archetype for player instead of calling estimate API', function () {
    $archetype = Archetype::factory()->create(['uuid' => 'eldrazi-ramp-uuid']);
    $deck = Deck::factory()->create(['archetype_id' => $archetype->id]);
    $deckVersion = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $deckVersion->id,
        'format' => 'CMODERN',
        'state' => 'complete',
    ]);

    $player = Player::create(['username' => 'local_player']);
    $game = Game::factory()->create(['match_id' => $match->id]);
    $game->players()->attach($player->id, [
        'instance_id' => 1,
        'is_local' => true,
        'on_play' => true,
        'starting_hand_size' => 7,
        'deck_json' => json_encode([['mtgo_id' => '123', 'quantity' => 4]]),
    ]);

    // If the estimate API is called, it should fail — we don't want it called
    Http::fake([
        '*/api/archetypes/estimate' => Http::response([], 500),
        '*' => Http::response([]),
    ]);

    DetermineMatchArchetypes::run($match);

    $playerArchetype = $match->archetypes()->where('player_id', $player->id)->first();
    expect($playerArchetype)->not->toBeNull();
    expect($playerArchetype->archetype_id)->toBe($archetype->id);
    expect((float) $playerArchetype->confidence)->toBe(1.0);
});
