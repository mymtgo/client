<?php

use App\Actions\Archetypes\ComputeArchetypeRefreshPlan;
use App\Models\Archetype;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

function fakeArchetypeApi(array $rows): void
{
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    Http::fake([
        '*/api/archetypes' => Http::response($rows, 200),
    ]);
}

function apiRow(Archetype $archetype): array
{
    return [
        'uuid' => $archetype->uuid,
        'name' => $archetype->name,
        'format' => $archetype->format,
        'colorIdentity' => $archetype->color_identity,
    ];
}

it('flags non-manual archetypes missing from the api as removed with match counts', function () {
    $kept = Archetype::factory()->create();
    $dead = Archetype::factory()->create();

    $player = Player::create(['username' => 'Opponent']);
    $match = MtgoMatch::factory()->create();
    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $player->id,
        'archetype_id' => $dead->id,
        'confidence' => 1.0,
    ]);

    fakeArchetypeApi([apiRow($kept)]);

    $plan = ComputeArchetypeRefreshPlan::run();

    expect($plan['removed'])->toHaveCount(1)
        ->and($plan['removed'][0]['id'])->toBe($dead->id)
        ->and($plan['removed'][0]['name'])->toBe($dead->name)
        ->and($plan['removed'][0]['match_count'])->toBe(1);
});

it('never flags manual or fallback archetypes as removed', function () {
    Archetype::factory()->manual()->create();
    Archetype::factory()->create(['is_fallback' => true]);

    fakeArchetypeApi([]);

    $plan = ComputeArchetypeRefreshPlan::run();

    expect($plan['removed'])->toBeEmpty();
});

it('counts added and updated archetypes', function () {
    $unchanged = Archetype::factory()->create(['format' => 'modern']);
    $renamed = Archetype::factory()->create(['format' => 'modern', 'name' => 'Old Name']);

    fakeArchetypeApi([
        apiRow($unchanged),
        [...apiRow($renamed), 'name' => 'New Name'],
        ['uuid' => (string) Str::uuid(), 'name' => 'Brand New', 'format' => 'Modern', 'colorIdentity' => 'UR'],
    ]);

    $plan = ComputeArchetypeRefreshPlan::run();

    expect($plan['added'])->toBe(1)
        ->and($plan['updated'])->toBe(1)
        ->and($plan['removed'])->toBeEmpty();
});

it('suggests a rename successor for removed archetypes that have matches', function () {
    $dead = Archetype::factory()->create(['name' => 'Mono-Green Tron', 'format' => 'modern', 'color_identity' => 'G']);
    $successor = Archetype::factory()->create(['name' => 'Tron', 'format' => 'modern', 'color_identity' => 'G']);

    $player = Player::create(['username' => 'Opponent']);
    $match = MtgoMatch::factory()->create();
    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $player->id,
        'archetype_id' => $dead->id,
        'confidence' => 1.0,
    ]);

    fakeArchetypeApi([apiRow($successor)]);

    $plan = ComputeArchetypeRefreshPlan::run();

    expect($plan['removed'][0]['suggested_id'])->toBe($successor->id);
});

it('does not suggest successors for removed archetypes without matches', function () {
    Archetype::factory()->create(['name' => 'Mono-Green Tron', 'format' => 'modern', 'color_identity' => 'G']);
    $successor = Archetype::factory()->create(['name' => 'Tron', 'format' => 'modern', 'color_identity' => 'G']);

    fakeArchetypeApi([apiRow($successor)]);

    $plan = ComputeArchetypeRefreshPlan::run();

    expect($plan['removed'][0]['suggested_id'])->toBeNull();
});

it('collects distinct match ids across all removed archetypes', function () {
    $deadOne = Archetype::factory()->create();
    $deadTwo = Archetype::factory()->create();

    $player = Player::create(['username' => 'Opponent']);
    $matchOne = MtgoMatch::factory()->create();
    $matchTwo = MtgoMatch::factory()->create();

    MatchArchetype::create(['mtgo_match_id' => $matchOne->id, 'player_id' => $player->id, 'archetype_id' => $deadOne->id, 'confidence' => 1.0]);
    MatchArchetype::create(['mtgo_match_id' => $matchTwo->id, 'player_id' => $player->id, 'archetype_id' => $deadOne->id, 'confidence' => 1.0]);
    MatchArchetype::create(['mtgo_match_id' => $matchTwo->id, 'player_id' => $player->id, 'archetype_id' => $deadTwo->id, 'confidence' => 1.0]);

    fakeArchetypeApi([]);

    $plan = ComputeArchetypeRefreshPlan::run();

    expect($plan['match_ids'])->toHaveCount(2)
        ->and($plan['match_ids'])->toContain($matchOne->id, $matchTwo->id);
});

it('suggests an incoming api archetype by uuid when the server rekeyed a same-name archetype', function () {
    // Server-side rekey: same name, brand-new uuid. The removed archetype's
    // best successor is the INCOMING api row, which has no local id yet.
    $player = Player::create(['username' => 'opp-'.uniqid()]);
    $dead = Archetype::factory()->create(['name' => 'Mono-Green Tron', 'format' => 'modern', 'color_identity' => 'G']);
    $match = MtgoMatch::factory()->create();
    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $player->id,
        'archetype_id' => $dead->id,
        'confidence' => 1.0,
    ]);

    $newUuid = (string) Str::uuid();
    fakeArchetypeApi([
        ['uuid' => $newUuid, 'name' => 'Mono-Green Tron', 'format' => 'modern', 'colorIdentity' => 'G'],
    ]);

    $plan = ComputeArchetypeRefreshPlan::run();

    expect($plan['removed'][0]['id'])->toBe($dead->id);
    expect($plan['removed'][0]['suggested_uuid'])->toBe($newUuid);
    expect($plan['removed'][0]['suggested_id'])->toBeNull();
});

it('lists incoming api archetypes as added rows', function () {
    $survivor = Archetype::factory()->create(['name' => 'Burn', 'format' => 'modern']);
    $newUuid = (string) Str::uuid();

    fakeArchetypeApi([
        apiRow($survivor),
        ['uuid' => $newUuid, 'name' => 'Fresh Deck', 'format' => 'Modern', 'colorIdentity' => 'R'],
    ]);

    $plan = ComputeArchetypeRefreshPlan::run();

    expect($plan['added_rows'])->toHaveCount(1);
    expect($plan['added_rows'][0]['uuid'])->toBe($newUuid);
    expect($plan['added_rows'][0]['name'])->toBe('Fresh Deck');
    expect($plan['added_rows'][0]['format'])->toBe('modern');
});
