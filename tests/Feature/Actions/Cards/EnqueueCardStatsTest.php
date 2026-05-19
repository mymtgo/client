<?php

use App\Actions\Cards\EnqueueCardStats;
use App\Enums\MatchState;
use App\Models\CardStatShipQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\CardStatsTelemetryFactory;

uses(RefreshDatabase::class);

it('enqueues an eligible game exactly once', function () {
    $scaffold = CardStatsTelemetryFactory::make();

    $inserted = EnqueueCardStats::run();

    expect($inserted)->toBe(1);
    expect(CardStatShipQueue::count())->toBe(1);
    expect(CardStatShipQueue::first()->game_id)->toBe($scaffold['games'][0]->id);
});

it('is idempotent on re-run', function () {
    CardStatsTelemetryFactory::make();

    EnqueueCardStats::run();
    EnqueueCardStats::run();

    expect(CardStatShipQueue::count())->toBe(1);
});

it('skips games without resolved outcome', function () {
    $scaffold = CardStatsTelemetryFactory::make(games: [[
        'won' => null,
        'cards' => [[]],
    ]]);
    $scaffold['games'][0]->update(['won' => null]);

    EnqueueCardStats::run();

    expect(CardStatShipQueue::count())->toBe(0);
});

it('skips matches not in Complete state', function () {
    CardStatsTelemetryFactory::make(matchOverrides: ['state' => MatchState::InProgress]);

    EnqueueCardStats::run();

    expect(CardStatShipQueue::count())->toBe(0);
});

it('skips matches without deck_version_id', function () {
    $scaffold = CardStatsTelemetryFactory::make();
    $scaffold['match']->update(['deck_version_id' => null]);

    EnqueueCardStats::run();

    expect(CardStatShipQueue::count())->toBe(0);
});

it('skips matches without archetypes', function () {
    CardStatsTelemetryFactory::make(withLocalArchetype: false, withOpponentArchetype: false);

    EnqueueCardStats::run();

    expect(CardStatShipQueue::count())->toBe(0);
});

it('skips games where all card stats are opponent-side', function () {
    CardStatsTelemetryFactory::make(games: [[
        'cards' => [['opponent' => true]],
    ]]);

    EnqueueCardStats::run();

    expect(CardStatShipQueue::count())->toBe(0);
});

it('stores serialized payload at enqueue time', function () {
    $scaffold = CardStatsTelemetryFactory::make();

    EnqueueCardStats::run();

    $row = CardStatShipQueue::first();
    expect($row->payload['format'])->toBe('CStandard');
    expect($row->payload['player_archetype_uuid'])->toBe($scaffold['archetype']->uuid);
});

it('respects limit parameter', function () {
    CardStatsTelemetryFactory::make();
    CardStatsTelemetryFactory::make();
    CardStatsTelemetryFactory::make();

    $inserted = EnqueueCardStats::run(limit: 2);

    expect($inserted)->toBe(2);
    expect(CardStatShipQueue::count())->toBe(2);
});
