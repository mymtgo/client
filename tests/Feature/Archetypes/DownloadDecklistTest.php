<?php

use App\Actions\Archetypes\DownloadArchetypeDecklist;
use App\Models\Archetype;
use App\Models\Card;
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

it('creates one archetype_deck per deck in API response', function () {
    $archetype = Archetype::factory()->create(['uuid' => 'arch-uuid']);
    $card = Card::factory()->create(['oracle_id' => 'oracle-1', 'mtgo_id' => '100']);

    Http::fake([
        '*/api/archetypes/arch-uuid/decklists' => Http::response([
            'uuid' => 'arch-uuid',
            'name' => $archetype->name,
            'format' => 'modern',
            'decks' => [
                [
                    'uuid' => 'deck-uuid-1',
                    'seen_count' => 5,
                    'cards' => [[
                        'mtgo_id' => '100',
                        'oracle_id' => 'oracle-1',
                        'name' => $card->name,
                        'type' => $card->type,
                        'color_identity' => 'W',
                        'image' => 'http://x',
                        'quantity' => 4,
                        'sideboard' => false,
                    ]],
                ],
                [
                    'uuid' => 'deck-uuid-2',
                    'seen_count' => 3,
                    'cards' => [[
                        'mtgo_id' => '100',
                        'oracle_id' => 'oracle-1',
                        'name' => $card->name,
                        'type' => $card->type,
                        'color_identity' => 'W',
                        'image' => 'http://x',
                        'quantity' => 3,
                        'sideboard' => true,
                    ]],
                ],
            ],
        ], 200),
    ]);

    DownloadArchetypeDecklist::run($archetype);

    $archetype->refresh();
    expect($archetype->decks)->toHaveCount(2);
    expect($archetype->decks->pluck('uuid')->sort()->values()->all())->toEqual(['deck-uuid-1', 'deck-uuid-2']);
    expect($archetype->decks->firstWhere('uuid', 'deck-uuid-1')->seen_count)->toBe(5);
    expect($archetype->decks->firstWhere('uuid', 'deck-uuid-1')->cards->first()->pivot->quantity)->toBe(4);
    expect($archetype->decklist_downloaded_at)->not->toBeNull();
});

it('updates seen_count on existing deck without duplicating', function () {
    $archetype = Archetype::factory()->create(['uuid' => 'arch-uuid']);
    $card = Card::factory()->create(['oracle_id' => 'oracle-1', 'mtgo_id' => '100']);

    $existing = $archetype->decks()->create([
        'uuid' => 'deck-uuid-1',
        'seen_count' => 1,
        'last_synced_at' => now()->subWeek(),
    ]);
    $existing->cards()->attach($card->id, ['quantity' => 2, 'sideboard' => false]);

    Http::fake([
        '*/api/archetypes/arch-uuid/decklists' => Http::response([
            'uuid' => 'arch-uuid',
            'name' => $archetype->name,
            'format' => 'modern',
            'decks' => [[
                'uuid' => 'deck-uuid-1',
                'seen_count' => 12,
                'cards' => [[
                    'mtgo_id' => '100',
                    'oracle_id' => 'oracle-1',
                    'name' => $card->name,
                    'type' => $card->type,
                    'color_identity' => 'W',
                    'image' => 'http://x',
                    'quantity' => 4,
                    'sideboard' => false,
                ]],
            ]],
        ], 200),
    ]);

    DownloadArchetypeDecklist::run($archetype);

    $archetype->refresh();
    expect($archetype->decks)->toHaveCount(1);
    $deck = $archetype->decks->first();
    expect($deck->seen_count)->toBe(12);
    expect($deck->cards->first()->pivot->quantity)->toBe(4);
});

it('keeps decks not present in response (additive only)', function () {
    $archetype = Archetype::factory()->create(['uuid' => 'arch-uuid']);
    $card = Card::factory()->create(['oracle_id' => 'oracle-1', 'mtgo_id' => '100']);

    $stale = $archetype->decks()->create([
        'uuid' => 'stale-deck-uuid',
        'seen_count' => 1,
        'last_synced_at' => now()->subMonth(),
    ]);
    $stale->cards()->attach($card->id, ['quantity' => 2, 'sideboard' => false]);

    Http::fake([
        '*/api/archetypes/arch-uuid/decklists' => Http::response([
            'uuid' => 'arch-uuid',
            'name' => $archetype->name,
            'format' => 'modern',
            'decks' => [[
                'uuid' => 'new-deck-uuid',
                'seen_count' => 8,
                'cards' => [[
                    'mtgo_id' => '100',
                    'oracle_id' => 'oracle-1',
                    'name' => $card->name,
                    'type' => $card->type,
                    'color_identity' => 'W',
                    'image' => 'http://x',
                    'quantity' => 4,
                    'sideboard' => false,
                ]],
            ]],
        ], 200),
    ]);

    DownloadArchetypeDecklist::run($archetype);

    $archetype->refresh();
    expect($archetype->decks->pluck('uuid')->sort()->values()->all())
        ->toEqual(['new-deck-uuid', 'stale-deck-uuid']);
});

it('throws when API returns non-2xx', function () {
    $archetype = Archetype::factory()->create(['uuid' => 'arch-uuid']);

    Http::fake([
        '*/api/archetypes/arch-uuid/decklists' => Http::response(null, 500),
    ]);

    expect(fn () => DownloadArchetypeDecklist::run($archetype))
        ->toThrow(RuntimeException::class);
});

it('skips cards with empty mtgo_id without nulling existing card rows', function () {
    $archetype = Archetype::factory()->create(['uuid' => 'arch-uuid']);
    $existing = Card::factory()->create(['oracle_id' => 'oracle-keep', 'mtgo_id' => '999']);

    Http::fake([
        '*/api/archetypes/arch-uuid/decklists' => Http::response([
            'uuid' => 'arch-uuid',
            'name' => $archetype->name,
            'format' => 'modern',
            'decks' => [[
                'uuid' => 'deck-uuid',
                'seen_count' => 1,
                'cards' => [
                    [
                        'mtgo_id' => null,
                        'oracle_id' => 'oracle-keep',
                        'name' => $existing->name,
                        'type' => $existing->type,
                        'color_identity' => null,
                        'image' => null,
                        'quantity' => 4,
                        'sideboard' => false,
                    ],
                ],
            ]],
        ], 200),
    ]);

    DownloadArchetypeDecklist::run($archetype);

    expect($existing->fresh()->mtgo_id)->toBe('999');
    expect($archetype->fresh()->decks->first()->cards)->toHaveCount(0);
});

it('retries on 401 after re-registration', function () {
    $card = Card::factory()->create(['oracle_id' => 'oracle-retry', 'mtgo_id' => '11111']);

    Http::fake([
        '*/api/archetypes/arch-uuid/decklists' => Http::sequence()
            ->push([], 401)
            ->push([
                'uuid' => 'arch-uuid',
                'name' => 'Test Archetype',
                'format' => 'modern',
                'decks' => [[
                    'uuid' => 'retry-deck-uuid',
                    'seen_count' => 4,
                    'cards' => [[
                        'mtgo_id' => '11111',
                        'oracle_id' => 'oracle-retry',
                        'name' => $card->name,
                        'type' => $card->type,
                        'color_identity' => 'G',
                        'image' => null,
                        'quantity' => 4,
                        'sideboard' => false,
                    ]],
                ]],
            ], 200),
        '*/api/devices/register' => Http::response(['api_key' => 'new-key']),
        '*' => Http::response('', 200),
    ]);

    $archetype = Archetype::factory()->create(['uuid' => 'arch-uuid']);

    DownloadArchetypeDecklist::run($archetype);

    $archetype->refresh();
    expect($archetype->decklist_downloaded_at)->not->toBeNull();
    expect($archetype->decks)->toHaveCount(1);
    expect($archetype->decks->first()->uuid)->toBe('retry-deck-uuid');
});
