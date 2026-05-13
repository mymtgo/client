<?php

namespace App\Actions\Pipeline\Handlers;

use App\Actions\Matches\AdvanceMatchState;
use App\Enums\MatchState;
use App\Models\LogEvent;
use App\Support\PipelineContext;

class HandleMatchStateChanged implements Handler
{
    public function handle(LogEvent $event, PipelineContext $context): void
    {
        if (! $event->match_token) {
            return;
        }

        $matchId = $event->match_id
            ?? LogEvent::where('match_token', $event->match_token)
                ->whereNotNull('match_id')
                ->value('match_id');

        if (! $matchId) {
            return;
        }

        $match = AdvanceMatchState::run($event->match_token, $matchId);

        if (! $match) {
            return;
        }

        $context->rememberMatch($match);

        if (in_array($match->state, [MatchState::Ended, MatchState::Complete], true)) {
            app(HandleMatchClosed::class)->handle($event, $context);
        }
    }
}
