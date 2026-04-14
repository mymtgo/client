<?php

use App\Actions\Archetypes\StoreManualArchetype;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Desktop\Facades\Settings;

uses(RefreshDatabase::class);

it('creates a manual archetype with cards', function () {
    Settings::set('device_id', 'abcdef1234567890');

    $card = Card::create([
        'oracle_id' => 'oracle-bolt',
        'mtgo_id' => 12345,
        'name' => 'Lightning Bolt',
        'type' => 'Instant',
    ]);

    $resolvedCards = [
        [
            'mtgo_id' => 12345,
            'oracle_id' => 'oracle-bolt',
            'name' => 'Lightning Bolt',
            'type' => 'Instant',
            'quantity' => 4,
            'sideboard' => false,
        ],
    ];

    $archetype = StoreManualArchetype::run(
        name: 'My Burn Deck',
        format: 'modern',
        colorIdentity: 'R',
        resolvedCards: $resolvedCards,
    );

    expect($archetype->manual)->toBeTrue();
    expect($archetype->name)->toBe('My Burn Deck');
    expect($archetype->format)->toBe('modern');
    expect($archetype->color_identity)->toBe('R');
    expect($archetype->decklist_downloaded_at)->not->toBeNull();
    expect($archetype->uuid)->toStartWith('abcdef12-');
    expect($archetype->cards)->toHaveCount(1);
    expect($archetype->cards->first()->pivot->quantity)->toBe(4);
    expect($archetype->cards->first()->pivot->sideboard)->toBeFalse();
});
