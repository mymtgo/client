<?php

use App\Actions\Pipeline\ApplyLogEvents;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    ApplyLogEvents::$handlers = [];
});

it('processes 10000 unprocessed events under 1 second', function () {
    LogEvent::factory()
        ->count(10000)
        ->create([
            'event_type' => 'noop',
            'processed_at' => null,
        ]);

    $start = microtime(true);
    ApplyLogEvents::run();
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(1.0);
})->group('benchmark');
