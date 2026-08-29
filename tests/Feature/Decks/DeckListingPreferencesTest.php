<?php

use App\Facades\AppSettings;
use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake());

it('persists the deck page size', function (int $perPage) {
    $this->post(route('decks.update-per-page'), ['per_page' => $perPage])->assertRedirect();

    expect(AppSettings::decksPerPage())->toBe($perPage);
})->with([12, 24, 48]);

it('rejects a page size that is not offered', function (mixed $perPage) {
    AppSettings::setDecksPerPage(24);

    $this->post(route('decks.update-per-page'), array_filter(['per_page' => $perPage]))
        ->assertSessionHasErrors('per_page');

    expect(AppSettings::decksPerPage())->toBe(24);
})->with([
    'missing' => [null],
    'unsupported size' => [100],
    'not a number' => ['loads'],
]);

it('paginates the deck listing at the stored page size', function () {
    AppSettings::setDecksGroupedByArchetype(false);
    AppSettings::setDecksPerPage(24);
    Deck::factory()->count(15)->create();

    $this->get(route('decks.index'))->assertInertia(fn ($page) => $page
        ->where('decks.per_page', 24)
        ->has('decks.data', 15)
        ->where('filters.per_page', 24)
    );
});

it('defaults the deck listing to 12 per page', function () {
    AppSettings::setDecksGroupedByArchetype(false);
    Deck::factory()->count(15)->create();

    $this->get(route('decks.index'))->assertInertia(fn ($page) => $page
        ->where('decks.per_page', 12)
        ->has('decks.data', 12)
    );
});

it('persists the deck card size', function (string $size) {
    $this->post(route('decks.update-card-size'), ['size' => $size])->assertRedirect();

    expect(AppSettings::deckCardSize())->toBe($size);
})->with(['large', 'compact']);

it('rejects an unknown deck card size', function () {
    AppSettings::setDeckCardSize('compact');

    $this->post(route('decks.update-card-size'), ['size' => 'enormous'])
        ->assertSessionHasErrors('size');

    expect(AppSettings::deckCardSize())->toBe('compact');
});

it('exposes the card size to the listing, defaulting to large', function () {
    AppSettings::setDecksGroupedByArchetype(false);
    Deck::factory()->create();

    $this->get(route('decks.index'))->assertInertia(fn ($page) => $page->where('filters.card_size', 'large'));

    AppSettings::setDeckCardSize('compact');

    $this->get(route('decks.index'))->assertInertia(fn ($page) => $page->where('filters.card_size', 'compact'));
});

it('walks back to the last page when the current page no longer exists', function () {
    AppSettings::setDecksGroupedByArchetype(false);
    AppSettings::setHideArchivedDecks(false);
    AppSettings::setDecksPerPage(12);

    Deck::factory()->count(12)->create();
    $archived = Deck::factory()->count(4)->create();
    $archived->each->delete();

    $this->get(route('decks.index', ['page' => 2]))->assertInertia(fn ($page) => $page->has('decks.data', 4));

    // Hiding the archived decks empties page 2.
    AppSettings::setHideArchivedDecks(true);

    $this->get(route('decks.index', ['page' => 2]))
        ->assertRedirect(route('decks.index', ['page' => 1]));
});

it('leaves an in-range page alone', function () {
    AppSettings::setDecksGroupedByArchetype(false);
    Deck::factory()->count(20)->create();

    $this->get(route('decks.index', ['page' => 2]))->assertInertia(fn ($page) => $page->has('decks.data', 8));
});
