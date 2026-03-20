<?php

use App\Events\ArchetypeEstimateUpdated;

it('can be constructed with all properties', function () {
    $event = new ArchetypeEstimateUpdated(
        matchToken: 'token-abc',
        playerName: 'Opponent',
        archetypeName: 'Burn',
        archetypeColorIdentity: 'RG',
        confidence: 87,
        cardsSeen: 12,
    );

    expect($event->matchToken)->toBe('token-abc');
    expect($event->playerName)->toBe('Opponent');
    expect($event->archetypeName)->toBe('Burn');
    expect($event->archetypeColorIdentity)->toBe('RG');
    expect($event->confidence)->toBe(87);
    expect($event->cardsSeen)->toBe(12);
});

it('accepts null for archetypeColorIdentity', function () {
    $event = new ArchetypeEstimateUpdated(
        matchToken: 'token-xyz',
        playerName: 'Player',
        archetypeName: 'Unknown',
        archetypeColorIdentity: null,
        confidence: 50,
        cardsSeen: 5,
    );

    expect($event->archetypeColorIdentity)->toBeNull();
});

it('returns nativephp broadcast channel', function () {
    $event = new ArchetypeEstimateUpdated(
        matchToken: 'token-abc',
        playerName: 'Opponent',
        archetypeName: 'Burn',
        archetypeColorIdentity: 'R',
        confidence: 90,
        cardsSeen: 8,
    );

    expect($event->broadcastOn())->toBe(['nativephp']);
});
