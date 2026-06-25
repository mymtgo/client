<?php

use App\Actions\Archetypes\StoreManualArchetype;
use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a manual archetype with cards', function () {
    AppSettings::setDeviceId('abcdef1234567890');

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

it('persists source_match_id and incomplete flag when supplied', function () {
    AppSettings::setDeviceId('abcdef1234567890');

    $match = MtgoMatch::factory()->create();

    $archetype = StoreManualArchetype::run(
        name: 'From Match',
        format: 'modern',
        colorIdentity: 'R',
        resolvedCards: [],
        sourceMatchId: $match->id,
        incomplete: true,
    );

    expect($archetype->source_match_id)->toBe($match->id);
    expect($archetype->incomplete)->toBeTrue();
});

it('upserts MatchArchetype for opponent when created from a match', function () {
    AppSettings::setDeviceId('abcdef1234567890');

    $match = MtgoMatch::factory()->create();

    $archetype = StoreManualArchetype::run(
        name: 'From Match',
        format: 'modern',
        colorIdentity: null,
        resolvedCards: [],
        sourceMatchId: $match->id,
        incomplete: true,
    );

    $matchArchetype = MatchArchetype::where('mtgo_match_id', $match->id)
        ->where('is_opponent', true)
        ->first();

    expect($matchArchetype)->not->toBeNull();
    expect($matchArchetype->archetype_id)->toBe($archetype->id);
});

it('overwrites an existing MatchArchetype for opponent on the source match', function () {
    AppSettings::setDeviceId('abcdef1234567890');

    $existingArchetype = Archetype::factory()->create();
    $match = MtgoMatch::factory()->create();

    MatchArchetype::create([
        'archetype_id' => $existingArchetype->id,
        'mtgo_match_id' => $match->id,
        'is_opponent' => true,
        'confidence' => 0.5,
    ]);

    $newArchetype = StoreManualArchetype::run(
        name: 'New From Match',
        format: 'modern',
        colorIdentity: null,
        resolvedCards: [],
        sourceMatchId: $match->id,
        incomplete: true,
    );

    expect(MatchArchetype::where('mtgo_match_id', $match->id)
        ->where('is_opponent', true)
        ->count())->toBe(1);

    $matchArchetype = MatchArchetype::where('mtgo_match_id', $match->id)
        ->where('is_opponent', true)
        ->first();

    expect($matchArchetype->archetype_id)->toBe($newArchetype->id);
});
