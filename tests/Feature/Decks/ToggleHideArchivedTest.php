<?php

use App\Facades\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake());

it('persists hide-archived state via AppSettings', function () {
    expect(AppSettings::hideArchivedDecks())->toBeFalse();

    $this->from(route('decks.index'))
        ->post(route('decks.toggle-hide-archived'), ['hide' => true])
        ->assertRedirect(route('decks.index'));

    expect(AppSettings::hideArchivedDecks())->toBeTrue();

    $this->from(route('decks.index'))
        ->post(route('decks.toggle-hide-archived'), ['hide' => false])
        ->assertRedirect(route('decks.index'));

    expect(AppSettings::hideArchivedDecks())->toBeFalse();
});

it('validates the hide payload', function () {
    $this->from(route('decks.index'))
        ->post(route('decks.toggle-hide-archived'), [])
        ->assertSessionHasErrors('hide');
});
