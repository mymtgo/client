<?php

use App\Actions\Cards\FetchExternalCardStats;
use App\Exceptions\ExternalCardStatsUnavailable;
use App\Models\Archetype;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

it('sends correct query params to the api', function () {
    $player = Archetype::factory()->create(['uuid' => 'player-uuid']);
    $opponent = Archetype::factory()->create(['uuid' => 'opp-uuid']);

    Http::fake([
        '*card-stats*' => Http::response([
            'stats' => [],
            'archetype_winrate' => ['games' => 100, 'wins' => 55, 'rate' => 0.55],
            'opponents' => [],
            'refreshed_at' => '2026-05-22T00:00:00Z',
        ], 200),
    ]);

    FetchExternalCardStats::run(
        archetype: $player,
        format: 'Standard',
        opponentArchetypeId: $opponent->id,
        onPlay: true,
        isPostboard: false,
        perspective: 'mine',
    );

    Http::assertSent(function ($request) {
        $url = $request->url();

        return str_contains($url, 'player_archetype_uuid=player-uuid')
            && str_contains($url, 'opponent_archetype_uuid=opp-uuid')
            && str_contains($url, 'format=Standard')
            && str_contains($url, 'on_play=1')
            && str_contains($url, 'is_postboard=0')
            && str_contains($url, 'perspective=mine');
    });
});

it('omits null filters from the query string', function () {
    $player = Archetype::factory()->create(['uuid' => 'player-uuid']);
    Http::fake(['*' => Http::response([
        'stats' => [],
        'archetype_winrate' => ['games' => 0, 'wins' => 0, 'rate' => 0.0],
        'opponents' => [],
        'refreshed_at' => null,
    ], 200)]);

    FetchExternalCardStats::run(
        archetype: $player,
        format: 'Standard',
        opponentArchetypeId: null,
        onPlay: null,
        isPostboard: null,
        perspective: 'mine',
    );

    Http::assertSent(function ($request) {
        $url = $request->url();

        return ! str_contains($url, 'opponent_archetype_uuid=')
            && ! str_contains($url, 'on_play=')
            && ! str_contains($url, 'is_postboard=');
    });
});

it('returns empty response on 404', function () {
    $player = Archetype::factory()->create(['uuid' => 'player-uuid']);
    Http::fake(['*' => Http::response(['message' => 'Archetype not found'], 404)]);

    $result = FetchExternalCardStats::run(
        archetype: $player,
        format: 'Standard',
        opponentArchetypeId: null,
        onPlay: null,
        isPostboard: null,
        perspective: 'mine',
    );

    expect(count($result->stats))->toBe(0);
    expect($result->opponents->count())->toBe(0);
    expect($result->refreshedAt)->toBeNull();
});

it('throws on 500', function () {
    $player = Archetype::factory()->create(['uuid' => 'player-uuid']);
    Http::fake(['*' => Http::response(['message' => 'oops'], 500)]);

    expect(fn () => FetchExternalCardStats::run(
        archetype: $player,
        format: 'Standard',
        opponentArchetypeId: null,
        onPlay: null,
        isPostboard: null,
        perspective: 'mine',
    ))->toThrow(ExternalCardStatsUnavailable::class);
});

it('throws on malformed json', function () {
    $player = Archetype::factory()->create(['uuid' => 'player-uuid']);
    Http::fake(['*' => Http::response(['unexpected' => 'shape'], 200)]);

    expect(fn () => FetchExternalCardStats::run(
        archetype: $player,
        format: 'Standard',
        opponentArchetypeId: null,
        onPlay: null,
        isPostboard: null,
        perspective: 'mine',
    ))->toThrow(ExternalCardStatsUnavailable::class);
});

it('maps opponent uuids to local archetype ids', function () {
    $player = Archetype::factory()->create(['uuid' => 'player-uuid']);
    $localOpp = Archetype::factory()->create(['uuid' => 'opp-1', 'name' => 'UW Control']);
    Http::fake(['*' => Http::response([
        'stats' => [],
        'archetype_winrate' => ['games' => 0, 'wins' => 0, 'rate' => 0.0],
        'opponents' => [
            ['uuid' => 'opp-1', 'name' => 'UW Control'],
            ['uuid' => 'unknown-uuid', 'name' => 'Unknown Deck'],
        ],
        'refreshed_at' => null,
    ], 200)]);

    $result = FetchExternalCardStats::run(
        archetype: $player,
        format: 'Standard',
        opponentArchetypeId: null,
        onPlay: null,
        isPostboard: null,
        perspective: 'mine',
    );

    expect($result->opponents->count())->toBe(1);
    expect($result->opponents[0]->id)->toBe($localOpp->id);
    expect($result->opponents[0]->uuid)->toBe('opp-1');
});

it('enriches stats with card name and image from cards table', function () {
    $player = Archetype::factory()->create(['uuid' => 'player-uuid']);
    Card::factory()->create([
        'oracle_id' => 'card-oracle-1',
        'name' => 'Lightning Bolt',
        'color_identity' => 'R',
        'type' => 'Instant',
        'image' => 'https://example.com/bolt.png',
        'local_image' => null,
    ]);
    Http::fake(['*' => Http::response([
        'stats' => [[
            'oracle_id' => 'card-oracle-1',
            'games' => 50,
            'kept' => ['samples' => 40, 'wins' => 25],
            'seen' => ['samples' => 45, 'wins' => 27],
            'cast' => ['samples' => 35, 'wins' => 22],
            'pregame' => ['samples' => 10, 'wins' => 6],
        ]],
        'archetype_winrate' => ['games' => 100, 'wins' => 55, 'rate' => 0.55],
        'opponents' => [],
        'refreshed_at' => null,
    ], 200)]);

    $result = FetchExternalCardStats::run(
        archetype: $player,
        format: 'Standard',
        opponentArchetypeId: null,
        onPlay: null,
        isPostboard: null,
        perspective: 'mine',
    );

    expect($result->stats)->toHaveCount(1);
    $row = $result->stats[0];
    expect($row['name'])->toBe('Lightning Bolt');
    expect($row['colorIdentity'])->toBe('R');
    expect($row['type'])->toBe('Instant');
    expect($row['image'])->toBe('https://example.com/bolt.png');
    expect($row['oracleId'])->toBe('card-oracle-1');
    expect($row['totalGames'])->toBe(50);
    expect($row['keptGames'])->toBe(40);
    expect($row['keptWon'])->toBe(25);
    expect($row['keptLost'])->toBe(15);
});

it('emits Unknown name when card not in local table', function () {
    $player = Archetype::factory()->create(['uuid' => 'player-uuid']);
    Http::fake(['*' => Http::response([
        'stats' => [[
            'oracle_id' => 'unknown-oracle',
            'games' => 10,
            'kept' => ['samples' => 0, 'wins' => 0],
            'seen' => ['samples' => 0, 'wins' => 0],
            'cast' => ['samples' => 0, 'wins' => 0],
            'pregame' => ['samples' => 0, 'wins' => 0],
        ]],
        'archetype_winrate' => ['games' => 0, 'wins' => 0, 'rate' => 0.0],
        'opponents' => [],
        'refreshed_at' => null,
    ], 200)]);

    $result = FetchExternalCardStats::run(
        archetype: $player,
        format: 'Standard',
        opponentArchetypeId: null,
        onPlay: null,
        isPostboard: null,
        perspective: 'mine',
    );

    expect($result->stats[0]['name'])->toBe('Unknown');
    expect($result->stats[0]['colorIdentity'])->toBeNull();
});
