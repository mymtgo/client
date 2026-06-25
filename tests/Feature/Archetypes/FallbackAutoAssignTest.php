<?php

use App\Actions\DetermineMatchArchetypes;
use App\Jobs\DownloadArchetypeDecklists;
use App\Models\Archetype;
use App\Models\Game;
use App\Models\GameDeck;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

it('auto-assigns Homebrew to opponent when local and API detection both fail', function () {
    Http::fake([
        '*/api/archetypes/estimate' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    $match = MtgoMatch::factory()->create([
        'format' => 'CMODERN',
        'state' => 'complete',
    ]);

    $game = Game::factory()->create(['match_id' => $match->id, 'opp_instance' => 2]);
    GameDeck::create([
        'game_id' => $game->id,
        'is_opponent' => true,
        'deck_json' => [
            ['mtgo_id' => '999999', 'quantity' => 4],
        ],
    ]);

    DetermineMatchArchetypes::run($match);

    $homebrewId = Archetype::where('uuid', Archetype::HOMEBREW_UUID)->value('id');

    expect(MatchArchetype::where('mtgo_match_id', $match->id)
        ->where('is_opponent', true)
        ->where('archetype_id', $homebrewId)
        ->where('confidence', 0)
        ->exists())->toBeTrue();
});

it('does not auto-assign Homebrew to the local player when their deck does not match', function () {
    Http::fake([
        '*/api/archetypes/estimate' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    $match = MtgoMatch::factory()->create([
        'format' => 'CMODERN',
        'state' => 'complete',
    ]);

    $game = Game::factory()->create(['match_id' => $match->id, 'local_instance' => 1]);
    GameDeck::create([
        'game_id' => $game->id,
        'is_opponent' => false,
        'deck_json' => [
            ['mtgo_id' => '999999', 'quantity' => 4],
        ],
    ]);

    DetermineMatchArchetypes::run($match);

    $homebrewId = Archetype::where('uuid', Archetype::HOMEBREW_UUID)->value('id');

    expect(MatchArchetype::where('mtgo_match_id', $match->id)
        ->where('is_opponent', false)
        ->where('archetype_id', $homebrewId)
        ->exists())->toBeFalse();
});

it('does not dispatch DownloadArchetypeDecklists for fallback archetypes', function () {
    Bus::fake();
    Http::fake([
        '*/api/archetypes/estimate' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    $match = MtgoMatch::factory()->create([
        'format' => 'CMODERN',
        'state' => 'complete',
    ]);

    $game = Game::factory()->create(['match_id' => $match->id, 'opp_instance' => 2]);
    GameDeck::create([
        'game_id' => $game->id,
        'is_opponent' => true,
        'deck_json' => [
            ['mtgo_id' => '999999', 'quantity' => 4],
        ],
    ]);

    DetermineMatchArchetypes::run($match);

    $homebrewId = Archetype::where('uuid', Archetype::HOMEBREW_UUID)->value('id');

    Bus::assertNotDispatched(
        DownloadArchetypeDecklists::class,
        fn (DownloadArchetypeDecklists $job) => $job->archetypeId === $homebrewId
    );
});

it('is idempotent — re-running detection produces only one fallback row per opponent', function () {
    Http::fake([
        '*/api/archetypes/estimate' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    $match = MtgoMatch::factory()->create([
        'format' => 'CMODERN',
        'state' => 'complete',
    ]);

    $game = Game::factory()->create(['match_id' => $match->id, 'opp_instance' => 2]);
    GameDeck::create([
        'game_id' => $game->id,
        'is_opponent' => true,
        'deck_json' => [
            ['mtgo_id' => '999999', 'quantity' => 4],
        ],
    ]);

    DetermineMatchArchetypes::run($match);
    DetermineMatchArchetypes::run($match->fresh(['games.decks']));

    expect(MatchArchetype::where('mtgo_match_id', $match->id)
        ->where('is_opponent', true)
        ->count())->toBe(1);
});
