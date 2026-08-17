<?php

use App\Actions\Archetypes\ApplyArchetypeRefresh;
use App\Jobs\DetermineMatchArchetypesJob;
use App\Jobs\RefreshArchetypes;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

function fakeRefreshApi(array $rows): void
{
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    Http::fake([
        '*/api/archetypes' => Http::response($rows, 200),
    ]);
}

it('upserts api archetypes and deletes removed ones while preserving manual and fallback', function () {
    Queue::fake();

    $kept = Archetype::factory()->create(['format' => 'modern', 'name' => 'Old Name']);
    $dead = Archetype::factory()->create();
    $manual = Archetype::factory()->manual()->create();
    $fallback = Archetype::factory()->create(['is_fallback' => true]);

    fakeRefreshApi([
        ['uuid' => $kept->uuid, 'name' => 'New Name', 'format' => 'Modern', 'colorIdentity' => $kept->color_identity],
        ['uuid' => (string) Str::uuid(), 'name' => 'Brand New', 'format' => 'Modern', 'colorIdentity' => 'UR'],
    ]);

    ApplyArchetypeRefresh::run();

    expect($kept->refresh()->name)->toBe('New Name')
        ->and(Archetype::where('name', 'Brand New')->exists())->toBeTrue()
        ->and(Archetype::whereKey($dead->id)->exists())->toBeFalse()
        ->and(Archetype::whereKey($manual->id)->exists())->toBeTrue()
        ->and(Archetype::whereKey($fallback->id)->exists())->toBeTrue();
});

it('queues archetype re-detection for matches attached to removed archetypes', function () {
    Queue::fake();

    $dead = Archetype::factory()->create();
    $player = Player::create(['username' => 'Opponent']);
    $match = MtgoMatch::factory()->create();
    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $player->id,
        'archetype_id' => $dead->id,
        'confidence' => 1.0,
    ]);

    fakeRefreshApi([]);

    ApplyArchetypeRefresh::run();

    expect($match->refresh()->archetype_detection_queued_at)->not->toBeNull();
    Queue::assertPushed(DetermineMatchArchetypesJob::class, fn ($job) => $job->matchId === $match->id);
});

it('unmerges custom archetypes merged into a removed archetype without deleting them', function () {
    Queue::fake();

    $dead = Archetype::factory()->create();
    $custom = Archetype::factory()->manual()->create(['merged_into_id' => $dead->id]);

    fakeRefreshApi([]);

    ApplyArchetypeRefresh::run();

    expect(Archetype::whereKey($custom->id)->exists())->toBeTrue()
        ->and($custom->refresh()->merged_into_id)->toBeNull();
});

it('dispatches the decklist refresh job', function () {
    Queue::fake();
    fakeRefreshApi([]);

    ApplyArchetypeRefresh::run();

    Queue::assertPushed(RefreshArchetypes::class);
});

it('returns a summary of the refresh', function () {
    Queue::fake();

    $dead = Archetype::factory()->create();
    $player = Player::create(['username' => 'Opponent']);
    $match = MtgoMatch::factory()->create();
    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $player->id,
        'archetype_id' => $dead->id,
        'confidence' => 1.0,
    ]);

    fakeRefreshApi([
        ['uuid' => (string) Str::uuid(), 'name' => 'Brand New', 'format' => 'Modern', 'colorIdentity' => 'UR'],
    ]);

    $summary = ApplyArchetypeRefresh::run();

    expect($summary['added'])->toBe(1)
        ->and($summary['removed'])->toBe(1)
        ->and($summary['matches_queued'])->toBe(1);
});

it('remaps matches to a mapped successor instead of re-detecting', function () {
    Queue::fake();

    $dead = Archetype::factory()->create(['name' => 'Mono-Green Tron', 'format' => 'modern']);
    $successor = Archetype::factory()->create(['name' => 'Tron', 'format' => 'modern']);

    $player = Player::create(['username' => 'Opponent']);
    $match = MtgoMatch::factory()->create();
    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $player->id,
        'archetype_id' => $dead->id,
        'confidence' => 1.0,
    ]);

    fakeRefreshApi([
        ['uuid' => $successor->uuid, 'name' => $successor->name, 'format' => $successor->format, 'colorIdentity' => $successor->color_identity],
    ]);

    $summary = ApplyArchetypeRefresh::run([$dead->id => $successor->id]);

    expect(Archetype::whereKey($dead->id)->exists())->toBeFalse()
        ->and(MatchArchetype::where('mtgo_match_id', $match->id)->value('archetype_id'))->toBe($successor->id)
        ->and($match->refresh()->archetype_detection_queued_at)->toBeNull()
        ->and($summary['remapped'])->toBe(1)
        ->and($summary['matches_queued'])->toBe(0);
    Queue::assertNotPushed(DetermineMatchArchetypesJob::class);
});

it('moves deck links and merged children to the mapped successor', function () {
    Queue::fake();

    $dead = Archetype::factory()->create(['format' => 'modern']);
    $successor = Archetype::factory()->create(['format' => 'modern']);
    $custom = Archetype::factory()->manual()->create(['merged_into_id' => $dead->id]);
    $deck = Deck::factory()->create(['archetype_id' => $dead->id]);

    fakeRefreshApi([
        ['uuid' => $successor->uuid, 'name' => $successor->name, 'format' => $successor->format, 'colorIdentity' => $successor->color_identity],
    ]);

    ApplyArchetypeRefresh::run([$dead->id => $successor->id]);

    expect($deck->refresh()->archetype_id)->toBe($successor->id)
        ->and($custom->refresh()->merged_into_id)->toBe($successor->id);
});

it('drops a remapped row when the successor is already assigned to that match and player', function () {
    Queue::fake();

    $dead = Archetype::factory()->create(['format' => 'modern']);
    $successor = Archetype::factory()->create(['format' => 'modern']);

    $player = Player::create(['username' => 'Opponent']);
    $match = MtgoMatch::factory()->create();
    MatchArchetype::create(['mtgo_match_id' => $match->id, 'player_id' => $player->id, 'archetype_id' => $dead->id, 'confidence' => 1.0]);
    MatchArchetype::create(['mtgo_match_id' => $match->id, 'player_id' => $player->id, 'archetype_id' => $successor->id, 'confidence' => 1.0]);

    fakeRefreshApi([
        ['uuid' => $successor->uuid, 'name' => $successor->name, 'format' => $successor->format, 'colorIdentity' => $successor->color_identity],
    ]);

    ApplyArchetypeRefresh::run([$dead->id => $successor->id]);

    expect(MatchArchetype::where('mtgo_match_id', $match->id)->where('player_id', $player->id)->count())->toBe(1)
        ->and(MatchArchetype::where('mtgo_match_id', $match->id)->value('archetype_id'))->toBe($successor->id);
});

it('ignores mappings whose successor does not survive the refresh', function () {
    Queue::fake();

    $dead = Archetype::factory()->create(['format' => 'modern']);
    $alsoDead = Archetype::factory()->create(['format' => 'modern']);

    $player = Player::create(['username' => 'Opponent']);
    $match = MtgoMatch::factory()->create();
    MatchArchetype::create(['mtgo_match_id' => $match->id, 'player_id' => $player->id, 'archetype_id' => $dead->id, 'confidence' => 1.0]);

    fakeRefreshApi([]);

    $summary = ApplyArchetypeRefresh::run([$dead->id => $alsoDead->id]);

    expect(Archetype::whereKey($alsoDead->id)->exists())->toBeFalse()
        ->and($summary['remapped'])->toBe(0)
        ->and($summary['matches_queued'])->toBe(1);
    Queue::assertPushed(DetermineMatchArchetypesJob::class, fn ($job) => $job->matchId === $match->id);
});

it('is idempotent — a second run removes nothing and queues no re-detection', function () {
    Queue::fake();

    Archetype::factory()->create();
    fakeRefreshApi([]);

    ApplyArchetypeRefresh::run();
    $summary = ApplyArchetypeRefresh::run();

    expect($summary['removed'])->toBe(0)
        ->and($summary['matches_queued'])->toBe(0);
    Queue::assertNotPushed(DetermineMatchArchetypesJob::class);
});
