<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Desktop\Facades\Settings;

uses(RefreshDatabase::class);

it('persists the grouping flag when enabling', function () {
    Settings::set('decks_grouped_by_archetype', 0);

    $response = $this->post(route('decks.toggle-grouping'), ['grouped' => true]);

    $response->assertRedirect();
    expect(Settings::get('decks_grouped_by_archetype'))->toBe(1);
});

it('persists the grouping flag when disabling', function () {
    Settings::set('decks_grouped_by_archetype', 1);

    $response = $this->post(route('decks.toggle-grouping'), ['grouped' => false]);

    $response->assertRedirect();
    expect(Settings::get('decks_grouped_by_archetype'))->toBe(0);
});

it('rejects the request when the grouped field is missing', function () {
    $response = $this->post(route('decks.toggle-grouping'), []);

    $response->assertSessionHasErrors('grouped');
});

it('rejects the request when the grouped field is not boolean', function () {
    $response = $this->post(route('decks.toggle-grouping'), ['grouped' => 'yes-please']);

    $response->assertSessionHasErrors('grouped');
});
