<?php

use App\Jobs\DownloadArchetypeDecklists;
use App\Jobs\DownloadArchetypes;
use App\Models\Archetype;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Clear the global Http::fake() from Pest.php beforeEach so test-specific
// fakes take priority when needed.
beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

it('creates an archetype with manual flag', function () {
    $archetype = Archetype::factory()->manual()->create();

    expect($archetype->manual)->toBeTrue();
});

it('defaults manual to false', function () {
    $archetype = Archetype::factory()->create();

    expect($archetype->manual)->toBeFalse();
});

it('cascades delete to match_archetypes', function () {
    $archetype = Archetype::factory()->create();
    $player = Player::create(['username' => 'testuser']);
    $match = MtgoMatch::create([
        'token' => fake()->uuid(),
        'mtgo_id' => fake()->unique()->numerify('######'),
        'format' => 'modern',
        'match_type' => 'league',
        'state' => 'complete',
        'outcome' => 'win',
        'started_at' => now(),
        'ended_at' => now(),
    ]);

    MatchArchetype::create([
        'archetype_id' => $archetype->id,
        'mtgo_match_id' => $match->id,
        'player_id' => $player->id,
        'confidence' => 0.9,
    ]);

    $archetype->delete();

    expect(MatchArchetype::where('archetype_id', $archetype->id)->count())->toBe(0);
});

it('does not overwrite manual archetypes during API sync', function () {
    $archetype = Archetype::factory()->manual()->create([
        'uuid' => 'manual-test-uuid',
        'name' => 'My Custom Deck',
        'format' => 'modern',
    ]);

    Http::fake([
        '*/api/archetypes' => Http::response([
            [
                'uuid' => 'manual-test-uuid',
                'name' => 'API Name Override',
                'format' => 'Modern',
                'colorIdentity' => 'R,G',
            ],
        ]),
    ]);

    (new DownloadArchetypes)->handle();

    expect($archetype->fresh()->name)->toBe('My Custom Deck');
});

it('does not download decklists for manual archetypes', function () {
    Http::fake();

    $archetype = Archetype::factory()->manual()->create([
        'decklist_downloaded_at' => null,
    ]);

    (new DownloadArchetypeDecklists($archetype->id))->handle();

    Http::assertNothingSent();
});
