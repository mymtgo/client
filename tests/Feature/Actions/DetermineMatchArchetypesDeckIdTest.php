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

it('persists archetype_deck_id on match_archetypes when local estimation finds a variant', function () {
    Http::fake([
        '*/api/archetypes/estimate' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    $archetype = Archetype::factory()->create(['format' => 'modern', 'decklist_downloaded_at' => now()]);
    $card = Card::factory()->create(['mtgo_id' => 99001, 'oracle_id' => 'oracle-test-card-001']);
    $deck = ArchetypeDeck::factory()->for($archetype)->create();
    $deck->cards()->attach($card->id, ['quantity' => 4, 'sideboard' => false]);

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
        'deck_json' => [['mtgo_id' => 99001, 'quantity' => 4]],
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
    expect((int) $row->archetype_id)->toBe($archetype->id);
    expect((int) $row->archetype_deck_id)->toBe($deck->id);
});
