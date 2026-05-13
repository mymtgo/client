<?php

use App\Actions\Pipeline\ApplyLogEvents;
use App\Actions\Pipeline\Handlers\Handler;
use App\Models\LogEvent;
use App\Support\PipelineContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('skips events with no registered handler without marking them processed', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'unregistered_event_type',
        'processed_at' => null,
    ]);

    ApplyLogEvents::$handlers = [];

    ApplyLogEvents::run();

    expect($event->fresh()->processed_at)->toBeNull();
});

it('runs the registered handler and marks the event processed', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'test_handler_event',
        'processed_at' => null,
    ]);

    $handler = new class implements Handler
    {
        public static int $calls = 0;

        public function handle(LogEvent $event, PipelineContext $context): void
        {
            self::$calls++;
        }
    };

    app()->instance(get_class($handler), $handler);
    ApplyLogEvents::$handlers = ['test_handler_event' => get_class($handler)];

    ApplyLogEvents::run();

    expect($handler::$calls)->toBe(1)
        ->and($event->fresh()->processed_at)->not->toBeNull();
});

it('processes events in id ascending order', function () {
    $ids = collect();
    for ($i = 0; $i < 5; $i++) {
        $ids->push(LogEvent::factory()->create([
            'event_type' => 'order_test',
            'processed_at' => null,
        ])->id);
    }

    $seen = [];
    $handler = new class($seen) implements Handler
    {
        public function __construct(public array &$seen) {}

        public function handle(LogEvent $event, PipelineContext $context): void
        {
            $this->seen[] = $event->id;
        }
    };

    app()->instance(get_class($handler), $handler);
    ApplyLogEvents::$handlers = ['order_test' => get_class($handler)];

    ApplyLogEvents::run();

    expect($seen)->toBe($ids->sort()->values()->all());
});

it('leaves an event unprocessed when its handler throws', function () {
    $event = LogEvent::factory()->create([
        'event_type' => 'failing_handler',
        'processed_at' => null,
    ]);

    $handler = new class implements Handler
    {
        public function handle(LogEvent $event, PipelineContext $context): void
        {
            throw new RuntimeException('boom');
        }
    };

    app()->instance(get_class($handler), $handler);
    ApplyLogEvents::$handlers = ['failing_handler' => get_class($handler)];

    ApplyLogEvents::run();

    expect($event->fresh()->processed_at)->toBeNull();
});
