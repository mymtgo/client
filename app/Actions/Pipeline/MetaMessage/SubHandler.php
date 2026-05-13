<?php

namespace App\Actions\Pipeline\MetaMessage;

use App\Models\LogEvent;
use App\Support\PipelineContext;

interface SubHandler
{
    /**
     * @param  array{type: int, kind: string, text: ?string, cards: ?array<int, int>, event: ?array<string, mixed>}  $parsed
     */
    public function apply(LogEvent $event, array $parsed, PipelineContext $context): void;
}
