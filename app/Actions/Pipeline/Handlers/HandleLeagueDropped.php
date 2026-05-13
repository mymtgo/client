<?php

namespace App\Actions\Pipeline\Handlers;

use App\Actions\Leagues\ProcessLeagueEvents;
use App\Models\LogEvent;
use App\Support\PipelineContext;

/**
 * Thin wrapper that delegates to the existing ProcessLeagueEvents action.
 *
 * @see HandleLeagueJoined for the rationale.
 */
class HandleLeagueDropped implements Handler
{
    public function handle(LogEvent $event, PipelineContext $context): void
    {
        ProcessLeagueEvents::run();
    }
}
