<?php

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

it('downloads decklist from API and stores cards', function () {
    Http::fake([
        '*/api/archetypes/*/decklist' => Http::response([
            'uuid' => 'test-uuid',
            'cards' => [
                [
                    'mtgo_id' => 12345,
                    'oracle_id' => 'oracle-1',
                    'name' => 'Lightning Bolt',
                    'type' => 'Instant',
                    'color_identity' => 'R',
                    'image' => 'https://example.com/bolt.jpg',
                    'quantity' => 4,
                    'sideboard' => false,
                ],
                [
                    'mtgo_id' => 67890,
                    'oracle_id' => 'oracle-2',
                    'name' => 'Smash to Smithereens',
                    'type' => 'Instant',
                    'color_identity' => 'R',
                    'image' => 'https://example.com/smash.jpg',
                    'quantity' => 2,
                    'sideboard' => true,
                ],
            ],
        ]),
        '*' => Http::response('', 200),
    ]);

    $archetype = Archetype::factory()->create(['uuid' => 'test-uuid']);

    $response = $this->post("/archetypes/{$archetype->id}/download");

    $response->assertRedirect("/archetypes/{$archetype->id}");

    $archetype->refresh();
    expect($archetype->decklist_downloaded_at)->not->toBeNull();
    expect($archetype->cards)->toHaveCount(2);
    expect(Card::where('oracle_id', 'oracle-1')->exists())->toBeTrue();
});

it('does not duplicate existing cards', function () {
    Http::fake([
        '*/api/archetypes/*/decklist' => Http::response([
            'uuid' => 'test-uuid',
            'cards' => [
                [
                    'mtgo_id' => 12345,
                    'oracle_id' => 'oracle-existing',
                    'name' => 'Lightning Bolt',
                    'type' => 'Instant',
                    'color_identity' => 'R',
                    'image' => 'https://example.com/bolt.jpg',
                    'quantity' => 4,
                    'sideboard' => false,
                ],
            ],
        ]),
        '*' => Http::response('', 200),
    ]);

    Card::factory()->create(['oracle_id' => 'oracle-existing', 'mtgo_id' => 12345]);

    $archetype = Archetype::factory()->create(['uuid' => 'test-uuid']);

    $this->post("/archetypes/{$archetype->id}/download");

    expect(Card::where('oracle_id', 'oracle-existing')->count())->toBe(1);
});

it('is idempotent on re-download', function () {
    Http::fake([
        '*/api/archetypes/*/decklist' => Http::response([
            'uuid' => 'test-uuid',
            'cards' => [
                [
                    'mtgo_id' => 12345,
                    'oracle_id' => 'oracle-1',
                    'name' => 'Lightning Bolt',
                    'type' => 'Instant',
                    'color_identity' => 'R',
                    'image' => 'https://example.com/bolt.jpg',
                    'quantity' => 4,
                    'sideboard' => false,
                ],
            ],
        ]),
        '*' => Http::response('', 200),
    ]);

    $archetype = Archetype::factory()->create(['uuid' => 'test-uuid']);

    $this->post("/archetypes/{$archetype->id}/download");
    $this->post("/archetypes/{$archetype->id}/download");

    expect($archetype->cards()->count())->toBe(1);
});

it('flashes error on API failure', function () {
    Http::fake([
        '*/api/archetypes/*/decklist' => Http::response([], 500),
        '*' => Http::response('', 200),
    ]);

    $archetype = Archetype::factory()->create();

    $response = $this->post("/archetypes/{$archetype->id}/download");

    $response->assertRedirect();
    $response->assertSessionHas('error');

    $archetype->refresh();
    expect($archetype->decklist_downloaded_at)->toBeNull();
});

it('retries on 401 after re-registration', function () {
    Http::fake([
        '*/api/archetypes/*/decklist' => Http::sequence()
            ->push([], 401)
            ->push([
                'uuid' => 'test-uuid',
                'cards' => [
                    [
                        'mtgo_id' => 11111,
                        'oracle_id' => 'oracle-retry',
                        'name' => 'Retry Card',
                        'type' => 'Creature',
                        'color_identity' => 'G',
                        'image' => null,
                        'quantity' => 4,
                        'sideboard' => false,
                    ],
                ],
            ], 200),
        '*/api/devices/register' => Http::response(['api_key' => 'new-key']),
        '*' => Http::response('', 200),
    ]);

    $archetype = Archetype::factory()->create(['uuid' => 'test-uuid']);

    $response = $this->post("/archetypes/{$archetype->id}/download");

    $response->assertRedirect();
    $archetype->refresh();
    expect($archetype->decklist_downloaded_at)->not->toBeNull();
    expect($archetype->cards)->toHaveCount(1);
});

it('skips cards without oracle_id', function () {
    Http::fake([
        '*/api/archetypes/*/decklist' => Http::response([
            'uuid' => 'test-uuid',
            'cards' => [
                [
                    'mtgo_id' => 11111,
                    'oracle_id' => null,
                    'name' => 'No Oracle Card',
                    'type' => 'Creature',
                    'color_identity' => 'R',
                    'image' => null,
                    'quantity' => 4,
                    'sideboard' => false,
                ],
                [
                    'mtgo_id' => 22222,
                    'oracle_id' => 'oracle-valid',
                    'name' => 'Valid Card',
                    'type' => 'Instant',
                    'color_identity' => 'R',
                    'image' => null,
                    'quantity' => 4,
                    'sideboard' => false,
                ],
            ],
        ]),
        '*' => Http::response('', 200),
    ]);

    $archetype = Archetype::factory()->create(['uuid' => 'test-uuid']);

    $this->post("/archetypes/{$archetype->id}/download");

    expect($archetype->cards()->count())->toBe(1);
    expect(Card::where('oracle_id', 'oracle-valid')->exists())->toBeTrue();
});
