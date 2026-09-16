<?php

use App\Models\Account;
use App\Models\Archetype;
use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('assigns an archetype to several decks at once', function () {
    $archetype = Archetype::factory()->create(['format' => 'standard']);
    $decks = Deck::factory()->count(3)->create(['archetype_id' => null]);

    $this->patch(route('decks.bulk-update-archetype'), [
        'deck_ids' => $decks->pluck('id')->all(),
        'archetype_id' => $archetype->id,
    ])->assertRedirect();

    expect(Deck::whereIn('id', $decks->pluck('id'))->pluck('archetype_id')->unique()->all())
        ->toBe([$archetype->id]);
});

it('clears archetypes when archetype_id is null', function () {
    $archetype = Archetype::factory()->create(['format' => 'standard']);
    $decks = Deck::factory()->count(2)->create(['archetype_id' => $archetype->id]);

    $this->patch(route('decks.bulk-update-archetype'), [
        'deck_ids' => $decks->pluck('id')->all(),
        'archetype_id' => null,
    ])->assertRedirect();

    expect(Deck::whereIn('id', $decks->pluck('id'))->whereNull('archetype_id')->count())->toBe(2);
});

it('clears archetypes when archetype_id is omitted', function () {
    $deck = Deck::factory()->create(['archetype_id' => Archetype::factory()->create(['format' => 'standard'])->id]);

    $this->patch(route('decks.bulk-update-archetype'), [
        'deck_ids' => [$deck->id],
    ])->assertRedirect();

    expect($deck->refresh()->archetype_id)->toBeNull();
});

it('reassigns trashed decks alongside live ones', function () {
    $archetype = Archetype::factory()->create(['format' => 'standard']);
    $live = Deck::factory()->create(['archetype_id' => null]);
    $trashed = Deck::factory()->create(['archetype_id' => null]);
    $trashed->delete();

    $this->patch(route('decks.bulk-update-archetype'), [
        'deck_ids' => [$live->id, $trashed->id],
        'archetype_id' => $archetype->id,
    ])->assertRedirect();

    expect($live->refresh()->archetype_id)->toBe($archetype->id);
    expect(Deck::withTrashed()->find($trashed->id)->archetype_id)->toBe($archetype->id);
});

it('is idempotent', function () {
    $archetype = Archetype::factory()->create(['format' => 'standard']);
    $deck = Deck::factory()->create(['archetype_id' => null]);
    $payload = ['deck_ids' => [$deck->id], 'archetype_id' => $archetype->id];

    $this->patch(route('decks.bulk-update-archetype'), $payload)->assertRedirect();
    $this->patch(route('decks.bulk-update-archetype'), $payload)->assertRedirect();

    expect($deck->refresh()->archetype_id)->toBe($archetype->id);
});

it('rejects an empty deck list', function () {
    $this->patch(route('decks.bulk-update-archetype'), ['deck_ids' => []])
        ->assertSessionHasErrors('deck_ids');
});

it('rejects duplicate deck ids', function () {
    $deck = Deck::factory()->create();

    $this->patch(route('decks.bulk-update-archetype'), ['deck_ids' => [$deck->id, $deck->id]])
        ->assertSessionHasErrors('deck_ids.0');
});

it('rejects unknown deck and archetype ids', function () {
    $deck = Deck::factory()->create();

    $this->patch(route('decks.bulk-update-archetype'), ['deck_ids' => [999999]])
        ->assertSessionHasErrors('deck_ids.0');

    $this->patch(route('decks.bulk-update-archetype'), ['deck_ids' => [$deck->id], 'archetype_id' => 999999])
        ->assertSessionHasErrors('archetype_id');
});

it('ignores decks that belong to another account', function () {
    $mine = Account::factory()->create(['active' => true]);
    $theirs = Account::factory()->create(['active' => false]);
    Account::flushCurrent();

    $archetype = Archetype::factory()->create(['format' => 'standard']);
    $myDeck = Deck::factory()->create(['account_id' => $mine->id, 'archetype_id' => null]);
    $theirDeck = Deck::factory()->create(['account_id' => $theirs->id, 'archetype_id' => null]);

    $this->patch(route('decks.bulk-update-archetype'), [
        'deck_ids' => [$myDeck->id, $theirDeck->id],
        'archetype_id' => $archetype->id,
    ])->assertRedirect();

    expect($myDeck->refresh()->archetype_id)->toBe($archetype->id);
    expect($theirDeck->refresh()->archetype_id)->toBeNull();
});

it('rejects decks that do not share a format', function () {
    $archetype = Archetype::factory()->create(['format' => 'modern']);
    $modern = Deck::factory()->create(['format' => 'CModern', 'archetype_id' => null]);
    $pauper = Deck::factory()->create(['format' => 'CPauper', 'archetype_id' => null]);

    $this->patch(route('decks.bulk-update-archetype'), [
        'deck_ids' => [$modern->id, $pauper->id],
        'archetype_id' => $archetype->id,
    ])->assertSessionHasErrors('deck_ids');

    expect($modern->refresh()->archetype_id)->toBeNull();
    expect($pauper->refresh()->archetype_id)->toBeNull();
});

it('rejects an archetype from another format', function () {
    $pauperArchetype = Archetype::factory()->create(['format' => 'pauper']);
    $deck = Deck::factory()->create(['format' => 'CModern', 'archetype_id' => null]);

    $this->patch(route('decks.bulk-update-archetype'), [
        'deck_ids' => [$deck->id],
        'archetype_id' => $pauperArchetype->id,
    ])->assertSessionHasErrors('archetype_id');

    expect($deck->refresh()->archetype_id)->toBeNull();
});

it('accepts a fallback archetype for any format', function () {
    $fallback = Archetype::factory()->fallback()->create();
    $deck = Deck::factory()->create(['format' => 'CModern', 'archetype_id' => null]);

    $this->patch(route('decks.bulk-update-archetype'), [
        'deck_ids' => [$deck->id],
        'archetype_id' => $fallback->id,
    ])->assertRedirect();

    expect($deck->refresh()->archetype_id)->toBe($fallback->id);
});

it('clears archetypes across formats since unclassified is format-agnostic', function () {
    $modern = Deck::factory()->create(['format' => 'CModern', 'archetype_id' => Archetype::factory()->create(['format' => 'modern'])->id]);
    $pauper = Deck::factory()->create(['format' => 'CPauper', 'archetype_id' => Archetype::factory()->create(['format' => 'pauper'])->id]);

    $this->patch(route('decks.bulk-update-archetype'), [
        'deck_ids' => [$modern->id, $pauper->id],
        'archetype_id' => null,
    ])->assertRedirect();

    expect($modern->refresh()->archetype_id)->toBeNull();
    expect($pauper->refresh()->archetype_id)->toBeNull();
});
