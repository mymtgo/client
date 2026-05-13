<?php

namespace App\Actions\Pipeline\Handlers;

use App\Actions\Leagues\ProcessLeagueEvents;
use App\Models\LogEvent;
use App\Support\PipelineContext;

/**
 * Thin wrapper that delegates to the existing ProcessLeagueEvents action.
 *
 * ProcessLeagueEvents scans the database for unprocessed league events in a
 * single pass and is idempotent (it stamps processed_at as it goes). Calling
 * it once per league_joined event is safe: the walker only re-stamps
 * processed_at on the current event after this handler returns.
 */
class HandleLeagueJoined implements Handler
{
    public function handle(LogEvent $event, PipelineContext $context): void
    {
        ProcessLeagueEvents::run();
    }
}
