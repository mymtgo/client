<?php

use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds Homebrew and Rogue fallback archetypes after migration', function () {
    $homebrew = Archetype::where('uuid', Archetype::HOMEBREW_UUID)->first();
    $rogue = Archetype::where('uuid', Archetype::ROGUE_UUID)->first();

    expect($homebrew)->not->toBeNull()
        ->and($homebrew->name)->toBe('Homebrew')
        ->and($homebrew->is_fallback)->toBeTrue()
        ->and($homebrew->format)->toBeNull();

    expect($rogue)->not->toBeNull()
        ->and($rogue->name)->toBe('Rogue')
        ->and($rogue->is_fallback)->toBeTrue()
        ->and($rogue->format)->toBeNull();
});
