<?php

use App\Data\Front\MatchRecordData;
use App\Enums\MatchOutcome;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use App\Support\MatchRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('divides wins by every match played, draws included', function () {
    $record = MatchRecord::fromCounts(wins: 83, losses: 98, draws: 6);

    expect($record->total())->toBe(187)
        ->and($record->winrate())->toBe(44);
});

it('derives draws from a total', function () {
    $record = MatchRecord::fromTotal(wins: 1, losses: 1, total: 3);

    expect($record->draws)->toBe(1)
        ->and($record->winrate())->toBe(33);
});

it('never reports negative draws when counts disagree', function () {
    $record = MatchRecord::fromTotal(wins: 2, losses: 2, total: 3);

    expect($record->draws)->toBe(0)
        ->and($record->total())->toBe(4);
});

it('formats the label with draws only when there are any', function () {
    expect(MatchRecord::fromCounts(3, 2)->label())->toBe('3 - 2')
        ->and(MatchRecord::fromCounts(3, 2, 1)->label())->toBe('3 - 2 - 1');
});

it('is zero percent and empty when nothing was played', function () {
    $record = MatchRecord::empty();

    expect($record->isEmpty())->toBeTrue()
        ->and($record->winrate())->toBe(0)
        ->and($record->label())->toBe('0 - 0');
});

it('adds two records together', function () {
    $sum = MatchRecord::fromCounts(6, 4, 2)->add(MatchRecord::fromCounts(18, 2));

    expect($sum->wins)->toBe(24)
        ->and($sum->losses)->toBe(6)
        ->and($sum->draws)->toBe(2)
        ->and($sum->winrate())->toBe(75);
});

it('converts to wire data with every derived field', function () {
    $data = MatchRecord::fromCounts(83, 98, 6)->toData();

    expect($data)->toBeInstanceOf(MatchRecordData::class)
        ->and($data->toArray())->toBe([
            'wins' => 83,
            'losses' => 98,
            'draws' => 6,
            'total' => 187,
            'winrate' => 44,
            'label' => '83 - 98 - 6',
        ]);
});

it('aggregates a match query into a record', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id]);
    MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'outcome' => MatchOutcome::Draw]);
    MtgoMatch::factory()->create(['deck_version_id' => $version->id, 'outcome' => MatchOutcome::Unknown]);

    $record = MatchRecord::fromQuery(MtgoMatch::complete()->orderByDesc('started_at')->limit(1));

    expect($record->wins)->toBe(1)
        ->and($record->losses)->toBe(1)
        ->and($record->draws)->toBe(2)
        ->and($record->total())->toBe(4)
        ->and($record->winrate())->toBe(25);
});

it('aggregates a has-many-through match relation', function () {
    $deck = Deck::factory()->create();
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id]);
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id]);

    $record = MatchRecord::fromQuery($deck->matches()->getQuery());

    expect($record->wins)->toBe(2)->and($record->total())->toBe(2);
});
