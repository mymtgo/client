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
