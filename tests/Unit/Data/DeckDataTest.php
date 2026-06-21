<?php

use App\Data\Front\DeckData;
use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('exposes deletedAt for trashed decks', function () {
    $deck = Deck::factory()->create();
    $deck->delete();
    $deck->refresh();

    $data = DeckData::fromModel($deck);

    expect($data->deletedAt)->not->toBeNull();
});

it('deletedAt is null for live decks', function () {
    $deck = Deck::factory()->create();

    $data = DeckData::fromModel($deck);

    expect($data->deletedAt)->toBeNull();
});

it('reports drawn matches as total minus wins and losses', function () {
    $deck = Deck::factory()->create();
    $deck->setAttribute('matches_count', 187);
    $deck->setAttribute('won_matches_count', 83);
    $deck->setAttribute('lost_matches_count', 98);

    $data = DeckData::fromModel($deck);

    expect($data->matchesDrawn)->toBe(6);
});
