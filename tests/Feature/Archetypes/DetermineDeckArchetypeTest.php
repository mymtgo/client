<?php

use App\Actions\DetermineDeckArchetype;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());
});

it('uses local match when confidence is above threshold', function () {
    $archetype = Archetype::factory()->withDecklist()->create([
        'name' => 'Burn',
        'format' => 'modern',
    ]);

    $deck = ArchetypeDeck::factory()->for($archetype)->create();

    $cards = [];
    foreach (['bolt' => 100, 'spike' => 101, 'guide' => 102, 'swift' => 103] as $oracle => $mtgoId) {
        $card = Card::create([
            'oracle_id' => $oracle,
            'mtgo_id' => $mtgoId,
            'name' => "Card $oracle",
            'type' => 'Instant',
        ]);
        $cards[$card->id] = ['quantity' => 4, 'sideboard' => false];
    }

    $deck->cards()->sync($cards);

    $inputCards = collect([
        ['mtgo_id' => 100, 'quantity' => 4],
        ['mtgo_id' => 101, 'quantity' => 4],
        ['mtgo_id' => 102, 'quantity' => 4],
        ['mtgo_id' => 103, 'quantity' => 4],
    ]);

    $result = DetermineDeckArchetype::run($inputCards, 'modern');

    expect($result)->not->toBeNull();
    expect($result['archetype_id'])->toBe($archetype->id);
    expect($result['archetype_deck_id'])->toBe($deck->id);

    Http::assertNothingSent();
});

it('falls back to API when local confidence is too low', function () {
    $archetype = Archetype::factory()->withDecklist()->create([
        'name' => 'Burn',
        'format' => 'modern',
        'uuid' => 'api-burn-uuid',
    ]);

    $deck = ArchetypeDeck::factory()->for($archetype)->create();

    $card = Card::create([
        'oracle_id' => 'bolt',
        'mtgo_id' => 100,
        'name' => 'Lightning Bolt',
        'type' => 'Instant',
    ]);

    $deck->cards()->sync([$card->id => ['quantity' => 4, 'sideboard' => false]]);

    Http::fake([
        '*/api/archetypes/estimate' => Http::response([
            ['uuid' => 'api-burn-uuid', 'confidence' => 0.85],
        ]),
    ]);

    // Input has the matching card PLUS many unknown cards — low local confidence
    $inputCards = collect([
        ['mtgo_id' => 100, 'quantity' => 4],
        ['mtgo_id' => 200, 'quantity' => 4],
        ['mtgo_id' => 201, 'quantity' => 4],
        ['mtgo_id' => 202, 'quantity' => 4],
        ['mtgo_id' => 203, 'quantity' => 4],
        ['mtgo_id' => 204, 'quantity' => 4],
        ['mtgo_id' => 205, 'quantity' => 4],
        ['mtgo_id' => 206, 'quantity' => 4],
        ['mtgo_id' => 207, 'quantity' => 4],
        ['mtgo_id' => 208, 'quantity' => 4],
    ]);

    $result = DetermineDeckArchetype::run($inputCards, 'modern');

    expect($result)->not->toBeNull();
    expect($result['confidence'])->toBe(0.85);
    expect($result['archetype_deck_id'])->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'archetypes/estimate'));
});

it('resolves archetype_deck_id when API returns a known deck_version_uuid', function () {
    $archetype = Archetype::factory()->create([
        'uuid' => 'api-uuid-variant',
        'format' => 'modern',
    ]);

    $deck = ArchetypeDeck::factory()->for($archetype)->create(['uuid' => 'deck-version-uuid-xyz']);

    Http::fake([
        '*/api/archetypes/estimate' => Http::response([
            [
                'uuid' => 'api-uuid-variant',
                'confidence' => 0.92,
                'deck_version_uuid' => 'deck-version-uuid-xyz',
            ],
        ]),
    ]);

    $result = DetermineDeckArchetype::run(collect([['mtgo_id' => 999, 'quantity' => 4]]), 'modern');

    expect($result)->not->toBeNull();
    expect($result['archetype_id'])->toBe($archetype->id);
    expect($result['archetype_deck_id'])->toBe($deck->id);
});

it('falls back to null archetype_deck_id when API deck_version_uuid is unknown locally', function () {
    Archetype::factory()->create(['uuid' => 'api-uuid-unknown-variant', 'format' => 'modern']);

    Http::fake([
        '*/api/archetypes/estimate' => Http::response([
            [
                'uuid' => 'api-uuid-unknown-variant',
                'confidence' => 0.8,
                'deck_version_uuid' => 'unknown-deck-uuid',
            ],
        ]),
    ]);

    $result = DetermineDeckArchetype::run(collect([['mtgo_id' => 999, 'quantity' => 4]]), 'modern');

    expect($result['archetype_deck_id'])->toBeNull();
});

it('falls back to API when no local archetypes exist', function () {
    $archetype = Archetype::factory()->create([
        'uuid' => 'api-uuid',
        'format' => 'modern',
    ]);

    Http::fake([
        '*/api/archetypes/estimate' => Http::response([
            ['uuid' => 'api-uuid', 'confidence' => 0.9],
        ]),
    ]);

    $inputCards = collect([
        ['mtgo_id' => 100, 'quantity' => 4],
    ]);

    $result = DetermineDeckArchetype::run($inputCards, 'modern');

    expect($result)->not->toBeNull();
    expect($result['archetype_deck_id'])->toBeNull();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'archetypes/estimate'));
});

it('returns archetype_deck_id when local estimation hits', function () {
    $archetype = Archetype::factory()->create(['format' => 'modern', 'decklist_downloaded_at' => now()]);
    $card = Card::factory()->create(['oracle_id' => 'a', 'mtgo_id' => '1']);

    $deck = ArchetypeDeck::factory()->for($archetype)->create(['uuid' => 'd1']);
    $deck->cards()->attach($card->id, ['quantity' => 4, 'sideboard' => false]);

    $input = collect([['mtgo_id' => '1', 'quantity' => 4]]);

    $result = DetermineDeckArchetype::run($input, 'modern');

    expect($result)->not->toBeNull();
    expect($result['archetype_id'])->toBe($archetype->id);
    expect($result['archetype_deck_id'])->toBe($deck->id);
});
