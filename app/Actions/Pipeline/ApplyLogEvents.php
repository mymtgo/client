<?php

namespace App\Actions\Pipeline;

use App\Actions\Pipeline\Handlers\Handler;
use App\Models\LogEvent;
use App\Support\PipelineContext;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        $instances = [];
        $unknownLogged = [];

        LogEvent::query()
            ->whereNull('processed_at')
            ->chunkById(200, function ($events) use ($context, &$instances, &$unknownLogged): void {
                foreach ($events as $event) {
                    self::dispatch($event, $context, $instances, $unknownLogged);
                }
            });
    }

    /**
     * @param  array<class-string<Handler>, Handler>  $instances
     * @param  array<string, bool>  $unknownLogged
     */
    private static function dispatch(
        LogEvent $event,
        PipelineContext $context,
        array &$instances,
        array &$unknownLogged,
    ): void {
        $handlerClass = self::$handlers[$event->event_type] ?? null;

        if ($handlerClass === null) {
            if (! isset($unknownLogged[$event->event_type])) {
                Log::channel('pipeline')->debug("ApplyLogEvents: no handler for event_type {$event->event_type}");
                $unknownLogged[$event->event_type] = true;
            }

            return;
        }

        try {
            $instances[$handlerClass] ??= app($handlerClass);
            /** @var Handler $handler */
            $handler = $instances[$handlerClass];
            $handler->handle($event, $context);
            $event->update(['processed_at' => now()]);
        } catch (Throwable $e) {
            Log::channel('pipeline')->error('ApplyLogEvents: handler failed', [
                'handler' => $handlerClass,
                'event_id' => $event->id,
                'event_type' => $event->event_type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Leave processed_at NULL — next tick will retry.
        }
    }
}
