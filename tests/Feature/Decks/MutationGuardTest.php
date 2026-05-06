<?php

use App\Jobs\ComputeCardGameStats;
use App\Jobs\DetermineMatchArchetypesJob;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake());

it('blocks renaming a trashed deck', function () {
    $deck = Deck::factory()->create(['name' => 'Original']);
    $deck->delete();

    $this->patch(route('decks.update-name', ['deck' => $deck->id]), ['name' => 'New'])
        ->assertForbidden();

    expect($deck->fresh()->name)->toBe('Original');
});

it('blocks updating cover art on a trashed deck', function () {
    $card = Card::factory()->create(['art_crop' => 'https://example.com/art.jpg']);
    $deck = Deck::factory()->create(['cover_id' => null]);
    $deck->delete();

    $this->patch(route('decks.update-cover-art', ['deck' => $deck->id]), ['cover_id' => $card->id])
        ->assertForbidden();

    expect($deck->fresh()->cover_id)->toBeNull();
});

it('blocks updating archetype on a trashed deck', function () {
    $originalArchetype = Archetype::factory()->create();
    $newArchetype = Archetype::factory()->create();
    $deck = Deck::factory()->create(['archetype_id' => $originalArchetype->id]);
    $deck->delete();

    $this->patch(route('decks.update-archetype', ['deck' => $deck->id]), ['archetype_id' => $newArchetype->id])
        ->assertForbidden();

    expect($deck->fresh()->archetype_id)->toBe($originalArchetype->id);
});

it('blocks triggering archetype detection on a trashed deck', function () {
    Queue::fake();

    $deck = Deck::factory()->create();
    $deck->delete();

    $this->post(route('decks.archetypes.detect', ['deck' => $deck->id]), ['filter_archetype' => 'none'])
        ->assertForbidden();

    Queue::assertNotPushed(DetermineMatchArchetypesJob::class);
});

it('blocks regenerating card stats on a trashed deck', function () {
    Queue::fake();

    $deck = Deck::factory()->create();
    $deck->delete();

    $this->post(route('decks.card-stats.regenerate', ['deck' => $deck->id]))
        ->assertForbidden();

    Queue::assertNotPushed(ComputeCardGameStats::class);
});
