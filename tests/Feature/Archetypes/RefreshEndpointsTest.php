<?php

use App\Models\Archetype;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(LazilyRefreshDatabase::class);

function fakeEndpointApi(array|int $rowsOrStatus): void
{
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());

    Http::fake([
        '*/api/archetypes' => is_int($rowsOrStatus)
            ? Http::response(null, $rowsOrStatus)
            : Http::response($rowsOrStatus, 200),
    ]);
}

it('renders the refresh page listing removals with matches and folding matchless ones', function () {
    $deadWithMatches = Archetype::factory()->create(['name' => 'Mono-Green Tron', 'format' => 'modern', 'color_identity' => 'G']);
    $deadWithout = Archetype::factory()->create(['name' => 'Neobrand', 'format' => 'modern']);
    $survivor = Archetype::factory()->create(['name' => 'Tron', 'format' => 'modern', 'color_identity' => 'G']);

    $player = Player::create(['username' => 'Opponent']);
    $match = MtgoMatch::factory()->create();
    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'player_id' => $player->id,
        'archetype_id' => $deadWithMatches->id,
        'confidence' => 1.0,
    ]);

    fakeEndpointApi([
        ['uuid' => $survivor->uuid, 'name' => $survivor->name, 'format' => $survivor->format, 'colorIdentity' => $survivor->color_identity],
    ]);

    $this->get(route('archetypes.refresh'))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('archetypes/Refresh')
            ->where('removals.0.id', $deadWithMatches->id)
            ->where('removals.0.match_count', 1)
            ->where('removals.0.suggested_id', $survivor->id)
            ->count('removals', 1)
            ->where('removed_without_matches', 1)
            ->where('matches_affected', 1)
            ->has('options')
        );

    expect(Archetype::whereKey($deadWithout->id)->exists())->toBeTrue();
});

it('excludes removed archetypes from the successor options', function () {
    $dead = Archetype::factory()->create(['format' => 'modern']);
    $survivor = Archetype::factory()->create(['format' => 'modern']);

    fakeEndpointApi([
        ['uuid' => $survivor->uuid, 'name' => $survivor->name, 'format' => $survivor->format, 'colorIdentity' => $survivor->color_identity],
    ]);

    $this->get(route('archetypes.refresh'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('archetypes/Refresh')
            ->where('options', fn ($options) => ! collect($options)->contains('id', $dead->id))
        );
});

it('applies the refresh with mappings and redirects with a summary', function () {
    Queue::fake();

    $dead = Archetype::factory()->create(['format' => 'modern']);
    $successor = Archetype::factory()->create(['format' => 'modern']);

    $player = Player::create(['username' => 'Opponent']);
    $match = MtgoMatch::factory()->create();
    MatchArchetype::create(['mtgo_match_id' => $match->id, 'player_id' => $player->id, 'archetype_id' => $dead->id, 'confidence' => 1.0]);

    fakeEndpointApi([
        ['uuid' => $successor->uuid, 'name' => $successor->name, 'format' => $successor->format, 'colorIdentity' => $successor->color_identity],
    ]);

    $this->post(route('archetypes.refresh.apply'), [
        'mappings' => [$dead->id => $successor->id],
    ])
        ->assertRedirect(route('archetypes.index'))
        ->assertSessionHas('success');

    expect(Archetype::whereKey($dead->id)->exists())->toBeFalse()
        ->and(MatchArchetype::where('mtgo_match_id', $match->id)->value('archetype_id'))->toBe($successor->id);
});

it('redirects back with an error when the api is unreachable', function () {
    fakeEndpointApi(500);

    $this->from(route('archetypes.index'))
        ->get(route('archetypes.refresh'))
        ->assertRedirect(route('archetypes.index'))
        ->assertSessionHas('error');

    $this->from(route('archetypes.refresh'))
        ->post(route('archetypes.refresh.apply'))
        ->assertRedirect(route('archetypes.refresh'))
        ->assertSessionHas('error');
});
