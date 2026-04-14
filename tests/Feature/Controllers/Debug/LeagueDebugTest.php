<?php

use App\Enums\LeagueState;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Desktop\Facades\Settings;

uses(RefreshDatabase::class);

beforeEach(function () {
    Settings::set('debug_mode', true);
});

it('lists leagues with pagination', function () {
    League::factory()->count(3)->create();

    $this->get('/debug/leagues')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('debug/Leagues')
            ->has('leagues.data', 3)
            ->has('stateOptions', 3)
            ->has('deckVersionOptions')
        );
});

it('includes soft-deleted leagues', function () {
    League::factory()->create();
    League::factory()->create(['deleted_at' => now()]);

    $this->get('/debug/leagues')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leagues.data', 2)
        );
});

it('updates a league field', function () {
    $league = League::factory()->create(['name' => 'Old Name']);

    $this->patch("/debug/leagues/{$league->id}", ['name' => 'New Name'])
        ->assertRedirect();

    expect($league->fresh()->name)->toBe('New Name');
});

it('updates league state', function () {
    $league = League::factory()->create(['state' => LeagueState::Active]);

    $this->patch("/debug/leagues/{$league->id}", ['state' => 'partial'])
        ->assertRedirect();

    expect($league->fresh()->state)->toBe(LeagueState::Partial);
});

it('updates league deck version', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $league = League::factory()->create(['deck_version_id' => null]);

    $this->patch("/debug/leagues/{$league->id}", ['deck_version_id' => $version->id])
        ->assertRedirect();

    expect($league->fresh()->deck_version_id)->toBe($version->id);
});

it('validates state field against enum', function () {
    $league = League::factory()->create();

    $this->patch("/debug/leagues/{$league->id}", ['state' => 'invalid'])
        ->assertSessionHasErrors('state');
});

it('soft-deletes a league', function () {
    $league = League::factory()->create();

    $this->delete("/debug/leagues/{$league->id}")
        ->assertRedirect();

    expect($league->fresh()->trashed())->toBeTrue();
});

it('restores a soft-deleted league', function () {
    $league = League::factory()->create(['deleted_at' => now()]);

    $this->patch("/debug/leagues/{$league->id}/restore")
        ->assertRedirect();

    expect($league->fresh()->trashed())->toBeFalse();
});
