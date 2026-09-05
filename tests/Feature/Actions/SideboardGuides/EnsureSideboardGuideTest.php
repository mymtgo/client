<?php

use App\Actions\SideboardGuides\EnsureSideboardGuide;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\SideboardGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a guide for a new deck and archetype pair', function () {
    $deck = Deck::factory()->create();
    $archetype = Archetype::factory()->create();

    $guide = EnsureSideboardGuide::run($deck, $archetype);

    expect($guide->deck_id)->toBe($deck->id);
    expect($guide->archetype_id)->toBe($archetype->id);
    expect(SideboardGuide::count())->toBe(1);
});

it('returns the existing guide on repeat calls', function () {
    $deck = Deck::factory()->create();
    $archetype = Archetype::factory()->create();

    $first = EnsureSideboardGuide::run($deck, $archetype);
    $second = EnsureSideboardGuide::run($deck, $archetype);

    expect($second->id)->toBe($first->id);
    expect(SideboardGuide::count())->toBe(1);
});
