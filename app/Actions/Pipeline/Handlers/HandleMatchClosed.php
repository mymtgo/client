<?php

namespace App\Actions\Pipeline\Handlers;

use App\Jobs\DetermineMatchArchetypesJob;
use App\Jobs\SubmitMatch;
use App\Models\LogEvent;
use App\Support\PipelineContext;

class HandleMatchClosed implements Handler
{
    public function handle(LogEvent $event, PipelineContext $context): void
    {
        $match = $context->matchByToken($event->match_token ?? '');

        if (! $match) {
            return;
        }

        DetermineMatchArchetypesJob::dispatch($match->id);
        SubmitMatch::dispatch($match->id);
    }
}
