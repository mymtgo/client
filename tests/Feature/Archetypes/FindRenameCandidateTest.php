<?php

use App\Actions\Archetypes\FindRenameCandidate;
use App\Models\Archetype;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('matches a punctuation and case rename exactly', function () {
    $removed = Archetype::factory()->create(['name' => 'Mono-Green Tron', 'format' => 'modern', 'color_identity' => 'G']);
    $renamed = Archetype::factory()->create(['name' => 'Mono green Tron', 'format' => 'modern', 'color_identity' => 'G']);
    $decoy = Archetype::factory()->create(['name' => 'Burn', 'format' => 'modern', 'color_identity' => 'R']);

    $candidate = FindRenameCandidate::run($removed, collect([$renamed, $decoy]));

    expect($candidate?->id)->toBe($renamed->id);
});

it('matches a rename that adds a word', function () {
    $removed = Archetype::factory()->create(['name' => 'Tron', 'format' => 'modern', 'color_identity' => null]);
    $renamed = Archetype::factory()->create(['name' => 'Colorless Tron', 'format' => 'modern', 'color_identity' => null]);
    $decoy = Archetype::factory()->create(['name' => 'Affinity', 'format' => 'modern', 'color_identity' => null]);

    $candidate = FindRenameCandidate::run($removed, collect([$renamed, $decoy]));

    expect($candidate?->id)->toBe($renamed->id);
});

it('matches a rename that drops words', function () {
    $removed = Archetype::factory()->create(['name' => 'Mono-Green Tron', 'format' => 'modern', 'color_identity' => 'G']);
    $renamed = Archetype::factory()->create(['name' => 'Tron', 'format' => 'modern', 'color_identity' => 'G']);
    $decoy = Archetype::factory()->create(['name' => 'Mono-Red Burn', 'format' => 'modern', 'color_identity' => 'R']);

    $candidate = FindRenameCandidate::run($removed, collect([$renamed, $decoy]));

    expect($candidate?->id)->toBe($renamed->id);
});

it('prefers the candidate sharing the color identity when names tie', function () {
    $removed = Archetype::factory()->create(['name' => 'Zoo', 'format' => 'modern', 'color_identity' => 'RG']);
    $wrongColors = Archetype::factory()->create(['name' => 'Domain Zoo', 'format' => 'modern', 'color_identity' => 'WUBRG']);
    $rightColors = Archetype::factory()->create(['name' => 'Gruul Zoo', 'format' => 'modern', 'color_identity' => 'RG']);

    $candidate = FindRenameCandidate::run($removed, collect([$wrongColors, $rightColors]));

    expect($candidate?->id)->toBe($rightColors->id);
});

it('prefers the defining word over shared color words', function () {
    $removed = Archetype::factory()->create(['name' => 'Mono-Green Tron', 'format' => 'modern', 'color_identity' => 'G']);
    $wrongDeck = Archetype::factory()->create(['name' => 'Mono-Green Titan', 'format' => 'modern', 'color_identity' => 'G']);
    $renamed = Archetype::factory()->create(['name' => 'Tron', 'format' => 'modern', 'color_identity' => null]);

    $candidate = FindRenameCandidate::run($removed, collect([$wrongDeck, $renamed]));

    expect($candidate?->id)->toBe($renamed->id);
});

it('does not match unrelated decks that only share color words', function () {
    $removed = Archetype::factory()->create(['name' => 'Mono-Green Elves', 'format' => 'pauper', 'color_identity' => 'G']);
    $unrelated = Archetype::factory()->create(['name' => 'Mono-Blue Delver', 'format' => 'pauper', 'color_identity' => 'U']);

    $candidate = FindRenameCandidate::run($removed, collect([$unrelated]));

    expect($candidate)->toBeNull();
});

it('returns null when nothing is similar', function () {
    $removed = Archetype::factory()->create(['name' => 'Neobrand', 'format' => 'modern', 'color_identity' => 'GU']);
    $unrelated = Archetype::factory()->create(['name' => 'Mono-Red Burn', 'format' => 'modern', 'color_identity' => 'R']);

    $candidate = FindRenameCandidate::run($removed, collect([$unrelated]));

    expect($candidate)->toBeNull();
});

it('only considers candidates in the same format', function () {
    $removed = Archetype::factory()->create(['name' => 'Tron', 'format' => 'modern', 'color_identity' => null]);
    $wrongFormat = Archetype::factory()->create(['name' => 'Tron', 'format' => 'pauper', 'color_identity' => null]);

    $candidate = FindRenameCandidate::run($removed, collect([$wrongFormat]));

    expect($candidate)->toBeNull();
});
