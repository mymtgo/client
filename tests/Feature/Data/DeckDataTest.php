<?php

use App\Data\Front\DeckData;
use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes a match record built from every match played', function () {
    $deck = Deck::factory()->create();
    $deck->setAttribute('won_matches_count', 83);
    $deck->setAttribute('lost_matches_count', 98);
    $deck->setAttribute('matches_count', 187);

    $data = DeckData::fromModel($deck);

    expect($data->record->draws)->toBe(6)
        ->and($data->record->total)->toBe(187)
        ->and($data->record->winrate)->toBe(44)
        ->and($data->record->label)->toBe('83 - 98 - 6');
});
