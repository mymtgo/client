<?php

namespace App\Actions\Pipeline\Handlers;

use App\Models\LogEvent;
use App\Support\PipelineContext;

interface Handler
{
    public function handle(LogEvent $event, PipelineContext $context): void;
}
