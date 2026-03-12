<?php

use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns paginated archetypes', function () {
    Archetype::factory()->count(30)->create(['format' => 'modern']);

    $response = $this->get('/archetypes');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('archetypes/Index')
        ->has('archetypes.data', 25)
    );
});

it('filters archetypes by format', function () {
    Archetype::factory()->count(5)->create(['format' => 'modern']);
    Archetype::factory()->count(3)->create(['format' => 'legacy']);

    $response = $this->get('/archetypes?format=modern');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('archetypes.data', 5)
    );
});

it('searches archetypes by name', function () {
    Archetype::factory()->create(['name' => 'Mono Red Aggro']);
    Archetype::factory()->create(['name' => 'Azorius Control']);

    $response = $this->get('/archetypes?search=mono');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('archetypes.data', 1)
    );
});

it('returns format options', function () {
    Archetype::factory()->create(['format' => 'modern']);
    Archetype::factory()->create(['format' => 'legacy']);

    $response = $this->get('/archetypes');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('formats', 2)
    );
});
