<?php

use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;

uses(RefreshDatabase::class);

it('keeps the sidebar filtered after an action redirects without a query string', function () {
    Archetype::factory()->create(['name' => 'Mono Red Aggro', 'format' => 'modern']);
    Archetype::factory()->create(['name' => 'Azorius Control', 'format' => 'modern']);
    $target = Archetype::factory()->create(['name' => 'Mono Red Prowess', 'format' => 'modern']);

    $this->get('/archetypes?search=mono&format=modern')->assertOk();

    // Every archetype action redirects to archetypes.show with no query
    // string, which is where the filter used to be lost.
    $response = $this->get("/archetypes/{$target->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('archetypes.data', 2)
        ->where('filters.search', 'mono')
        ->where('filters.format', 'modern')
    );
});

it('clears remembered filters when the user empties them', function () {
    Archetype::factory()->create(['name' => 'Mono Red Aggro', 'format' => 'modern']);
    Archetype::factory()->create(['name' => 'Azorius Control', 'format' => 'legacy']);

    $this->get('/archetypes?search=mono&format=modern')->assertOk();

    $response = $this->get('/archetypes?search=&format=');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('archetypes.data', 2)
        ->where('filters.search', '')
        ->where('filters.format', '')
    );
});

it('does not let a partial reload overwrite the remembered filters', function () {
    Archetype::factory()->create(['name' => 'Mono Red Aggro', 'format' => 'modern']);
    Archetype::factory()->create(['name' => 'Azorius Control', 'format' => 'legacy']);

    $this->get('/archetypes?search=mono&format=modern')->assertOk();

    // The match picker on the create screen reloads with the form's format and
    // a blank search, using the same parameter names as the sidebar.
    $this->get('/archetypes/create?format=legacy&search=&match_search=bob', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) Inertia::getVersion(),
        'X-Inertia-Partial-Data' => 'matches',
        'X-Inertia-Partial-Component' => 'archetypes/Create',
    ])->assertOk();

    $response = $this->get('/archetypes');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('archetypes.data', 1)
        ->where('filters.search', 'mono')
        ->where('filters.format', 'modern')
    );
});

it('replaces remembered filters when a new filter is applied', function () {
    Archetype::factory()->create(['name' => 'Mono Red Aggro', 'format' => 'modern']);
    Archetype::factory()->create(['name' => 'Azorius Control', 'format' => 'legacy']);

    $this->get('/archetypes?search=mono&format=modern')->assertOk();

    $response = $this->get('/archetypes?search=azorius&format=legacy');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('archetypes.data', 1)
        ->where('filters.search', 'azorius')
    );
});
