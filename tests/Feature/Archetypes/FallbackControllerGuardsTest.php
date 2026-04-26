<?php

use App\Models\Archetype;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fallback = Archetype::where('uuid', Archetype::HOMEBREW_UUID)->firstOrFail();
});

it('returns 403 when updating a fallback archetype', function () {
    $response = $this->put(route('archetypes.update', $this->fallback), [
        'name' => 'Should Not Save',
        'format' => 'modern',
        'color_identity' => null,
        'cards' => [
            ['oracle_id' => null, 'mtgo_id' => 1, 'quantity' => 1, 'sideboard' => false],
        ],
    ]);

    $response->assertForbidden();
});

it('returns 403 when destroying a fallback archetype', function () {
    $response = $this->delete(route('archetypes.destroy', $this->fallback));

    $response->assertForbidden();

    expect(Archetype::find($this->fallback->id))->not->toBeNull();
});

it('returns 403 when downloading a decklist for a fallback archetype', function () {
    $response = $this->postJson(route('archetypes.download', $this->fallback));

    $response->assertForbidden();
});

it('returns 403 when exporting a .dek for a fallback archetype', function () {
    $response = $this->postJson(route('archetypes.export', $this->fallback));

    $response->assertForbidden();
});
