<?php

use App\Models\Challenge;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the challenges index page', function () {
    Challenge::factory()->inProgress()->create();

    $response = $this->get('/challenges');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->component('challenges/Index'));
});

it('filters by format', function () {
    Challenge::factory()->create(['format' => 'Modern']);
    Challenge::factory()->create(['format' => 'Legacy']);

    $response = $this->get('/challenges?format=Modern');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('challenges.data', 1)
        );
});

it('filters active challenges by default', function () {
    Challenge::factory()->inProgress()->create();
    Challenge::factory()->completed()->create();

    $response = $this->get('/challenges');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('challenges.data', 1)
        );
});

it('shows all challenges when state is all', function () {
    Challenge::factory()->inProgress()->create();
    Challenge::factory()->completed()->create();

    $response = $this->get('/challenges?state=all');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('challenges.data', 2)
        );
});
