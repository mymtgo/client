<?php

use App\Facades\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake());

it('persists the grouping flag when enabling', function () {
    AppSettings::setDecksGroupedByArchetype(false);

    $response = $this->post(route('decks.toggle-grouping'), ['grouped' => true]);

    $response->assertRedirect();
    expect(AppSettings::decksGroupedByArchetype())->toBeTrue();
});

it('persists the grouping flag when disabling', function () {
    AppSettings::setDecksGroupedByArchetype(true);

    $response = $this->post(route('decks.toggle-grouping'), ['grouped' => false]);

    $response->assertRedirect();
    expect(AppSettings::decksGroupedByArchetype())->toBeFalse();
});

it('rejects the request when the grouped field is missing', function () {
    $response = $this->post(route('decks.toggle-grouping'), []);

    $response->assertSessionHasErrors('grouped');
});

it('rejects the request when the grouped field is not boolean', function () {
    $response = $this->post(route('decks.toggle-grouping'), ['grouped' => 'yes-please']);

    $response->assertSessionHasErrors('grouped');
});
