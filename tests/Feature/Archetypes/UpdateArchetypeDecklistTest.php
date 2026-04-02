<?php

use App\Actions\Archetypes\UpdateArchetypeDecklist;
use App\Models\Archetype;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('replaces the card list and sets manual true', function () {
    $archetype = Archetype::factory()->withDecklist()->create([
        'manual' => false,
    ]);

    $oldCard = Card::create([
        'oracle_id' => 'oracle-old',
        'mtgo_id' => 11111,
        'name' => 'Old Card',
        'type' => 'Creature',
    ]);

    $archetype->cards()->attach($oldCard->id, ['quantity' => 4, 'sideboard' => false]);

    $newCard = Card::create([
        'oracle_id' => 'oracle-new',
        'mtgo_id' => 22222,
        'name' => 'New Card',
        'type' => 'Instant',
    ]);

    $resolvedCards = [
        [
            'oracle_id' => 'oracle-new',
            'mtgo_id' => 22222,
            'name' => 'New Card',
            'type' => 'Instant',
            'quantity' => 3,
            'sideboard' => true,
        ],
    ];

    UpdateArchetypeDecklist::run(
        archetype: $archetype,
        resolvedCards: $resolvedCards,
        name: 'Updated Name',
        format: 'legacy',
        colorIdentity: 'U,B',
    );

    $archetype->refresh();

    expect($archetype->manual)->toBeTrue();
    expect($archetype->name)->toBe('Updated Name');
    expect($archetype->format)->toBe('legacy');
    expect($archetype->color_identity)->toBe('U,B');
    expect($archetype->cards)->toHaveCount(1);
    expect($archetype->cards->first()->name)->toBe('New Card');
    expect($archetype->cards->first()->pivot->quantity)->toBe(3);
    expect($archetype->cards->first()->pivot->sideboard)->toBeTrue();
});
