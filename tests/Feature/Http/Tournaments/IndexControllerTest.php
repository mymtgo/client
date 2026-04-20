<?php

use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the tournaments index page', function () {
    Tournament::factory()->inProgress()->create();

    $response = $this->get('/tournaments');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->component('tournaments/Index'));
});

it('filters by format', function () {
    Tournament::factory()->create(['format' => 'Modern']);
    Tournament::factory()->create(['format' => 'Legacy']);

    $response = $this->get('/tournaments?format=Modern');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('tournaments.data', 1)
        );
});

it('filters active tournaments by default', function () {
    Tournament::factory()->inProgress()->create();
    Tournament::factory()->completed()->create();

    $response = $this->get('/tournaments');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('tournaments.data', 1)
        );
});

it('shows all tournaments when state is all', function () {
    Tournament::factory()->inProgress()->create();
    Tournament::factory()->completed()->create();

    $response = $this->get('/tournaments?state=all');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('tournaments.data', 2)
        );
});
