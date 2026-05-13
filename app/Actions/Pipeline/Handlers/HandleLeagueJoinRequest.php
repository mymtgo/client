<?php

namespace App\Actions\Pipeline\Handlers;

use App\Actions\Leagues\ProcessLeagueEvents;
use App\Models\LogEvent;
use App\Support\PipelineContext;

/**
 * Thin wrapper that delegates to the existing ProcessLeagueEvents action.
 *
 * league_join_request is a marker event with no business effect today —
 * ProcessLeagueEvents stamps it processed and moves on. We still route it
 * through the walker so that processed_at advances consistently.
 */
class HandleLeagueJoinRequest implements Handler
{
    public function handle(LogEvent $event, PipelineContext $context): void
    {
        ProcessLeagueEvents::run();
    }
}
