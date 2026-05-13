<?php

namespace App\Actions\Pipeline;

use App\Actions\Pipeline\Handlers\Handler;
use App\Models\LogEvent;
use App\Support\PipelineContext;
use Illuminate\Support\Facades\Log;

/**
 * Walk unprocessed LogEvents in id order and dispatch each to its registered
 * handler. New PipelineContext per invocation — do NOT bind as a singleton.
 */
class ApplyLogEvents
{
    /**
     * Map of event_type → handler class. Populated by AppServiceProvider.
     *
     * @var array<string, class-string<Handler>>
     */
    public static array $handlers = [];

    public static function run(): void
    {
        $context = new PipelineContext;

        LogEvent::query()
            ->whereNull('processed_at')
            ->orderBy('id')
            ->chunkById(200, function ($events) use ($context): void {
                foreach ($events as $event) {
                    self::dispatch($event, $context);
                }
            });
    }

    private static function dispatch(LogEvent $event, PipelineContext $context): void
    {
        $handlerClass = self::$handlers[$event->event_type] ?? null;

        if ($handlerClass === null) {
            Log::channel('pipeline')->debug("ApplyLogEvents: no handler for event_type {$event->event_type}");

            return;
        }

        try {
            /** @var Handler $handler */
            $handler = app($handlerClass);
            $handler->handle($event, $context);
            $event->update(['processed_at' => now()]);
        } catch (\Throwable $e) {
            Log::channel('pipeline')->error("ApplyLogEvents: handler {$handlerClass} failed on event {$event->id}", [
                'error' => $e->getMessage(),
            ]);
            // Leave processed_at NULL — next tick will retry.
        }
    }
}
