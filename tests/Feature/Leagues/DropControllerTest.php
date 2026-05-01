<?php

use App\Enums\LeagueState;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('drops an active league', function () {
    $deck = Deck::factory()->create();
    $dv = DeckVersion::factory()->for($deck)->create();
    $league = League::factory()->for($dv)->create([
        'state' => LeagueState::Active,
        'dropped_at' => null,
    ]);

    $this->patch("/leagues/{$league->id}/drop")->assertRedirect();

    $fresh = $league->fresh();
    expect($fresh->state)->toBe(LeagueState::Dropped)
        ->and($fresh->dropped_at)->not->toBeNull();
});
