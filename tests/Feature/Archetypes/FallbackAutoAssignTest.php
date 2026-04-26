<?php

use App\Actions\DetermineMatchArchetypes;
use App\Jobs\DownloadArchetypeDecklists;
use App\Models\Archetype;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
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

    $opponent = Player::create(['username' => 'opponent']);
    $game = Game::factory()->create(['match_id' => $match->id]);
    $game->players()->attach($opponent->id, [
        'instance_id' => 2,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
        'deck_json' => [
            ['mtgo_id' => '999999', 'quantity' => 4],
        ],
    ]);

    DetermineMatchArchetypes::run($match);

    $homebrewId = Archetype::where('uuid', Archetype::HOMEBREW_UUID)->value('id');

    expect(MatchArchetype::where('mtgo_match_id', $match->id)
        ->where('player_id', $opponent->id)
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

    $localPlayer = Player::create(['username' => 'me']);
    $game = Game::factory()->create(['match_id' => $match->id]);
    $game->players()->attach($localPlayer->id, [
        'instance_id' => 1,
        'is_local' => true,
        'on_play' => true,
        'starting_hand_size' => 7,
        'deck_json' => [
            ['mtgo_id' => '999999', 'quantity' => 4],
        ],
    ]);

    DetermineMatchArchetypes::run($match);

    $homebrewId = Archetype::where('uuid', Archetype::HOMEBREW_UUID)->value('id');

    expect(MatchArchetype::where('mtgo_match_id', $match->id)
        ->where('player_id', $localPlayer->id)
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

    $opponent = Player::create(['username' => 'opp_'.uniqid()]);
    $game = Game::factory()->create(['match_id' => $match->id]);
    $game->players()->attach($opponent->id, [
        'instance_id' => 2,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
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

    $opponent = Player::create(['username' => 'opp_idempotent']);
    $game = Game::factory()->create(['match_id' => $match->id]);
    $game->players()->attach($opponent->id, [
        'instance_id' => 2,
        'is_local' => false,
        'on_play' => false,
        'starting_hand_size' => 7,
        'deck_json' => [
            ['mtgo_id' => '999999', 'quantity' => 4],
        ],
    ]);

    DetermineMatchArchetypes::run($match);
    DetermineMatchArchetypes::run($match->fresh(['games.players', 'games.opponents']));

    expect(MatchArchetype::where('mtgo_match_id', $match->id)
        ->where('player_id', $opponent->id)
        ->count())->toBe(1);
});
