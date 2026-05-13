<?php

namespace App\Actions\Pipeline\Handlers;

use App\Actions\Logs\ExtractMetaMessageBytes;
use App\Actions\Logs\ParseMetaMessage;
use App\Actions\Pipeline\MetaMessage\SubHandler;
use App\Models\LogEvent;
use App\Support\PipelineContext;

class HandleGameManagementJson implements Handler
{
    /**
     * Map of MetaMessageKind value → SubHandler class.
     *
     * @var array<string, class-string<SubHandler>>
     */
    public static array $subHandlers = [];

    public function handle(LogEvent $event, PipelineContext $context): void
    {
        $bytes = ExtractMetaMessageBytes::run($event->raw_text ?? '');

        if ($bytes === null) {
            return;
        }

        $parsed = ParseMetaMessage::run($bytes);

        if ($parsed === null) {
            return;
        }

        $subHandlerClass = self::$subHandlers[$parsed['kind']] ?? null;

        if ($subHandlerClass === null) {
            return;
        }

        app($subHandlerClass)->apply($event, $parsed, $context);
    }
}
