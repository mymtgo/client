<?php

use App\Models\CardStatShipQueue;
use App\Updates\BackfillCardStatsShipQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\CardStatsTelemetryFactory;

uses(RefreshDatabase::class);

it('enqueues every eligible historic game', function () {
    for ($i = 0; $i < 3; $i++) {
        CardStatsTelemetryFactory::make();
    }

    (new BackfillCardStatsShipQueue)->run();

    expect(CardStatShipQueue::count())->toBe(3);
});

it('is idempotent on repeated runs', function () {
    CardStatsTelemetryFactory::make();

    (new BackfillCardStatsShipQueue)->run();
    (new BackfillCardStatsShipQueue)->run();

    expect(CardStatShipQueue::count())->toBe(1);
});
