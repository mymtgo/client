<?php

namespace App\Actions\Pipeline\Handlers;

use App\Models\Game;
use App\Models\LogEvent;
use App\Support\PipelineContext;

class HandleGameStateUpdate implements Handler
{
    public function handle(LogEvent $event, PipelineContext $context): void
    {
        if (! $event->game_id || ! $event->match_token) {
            return;
        }

        $match = $context->matchByToken($event->match_token);

        if (! $match) {
            return;
        }

        Game::firstOrCreate(
            ['mtgo_id' => $event->game_id],
            ['match_id' => $match->id, 'started_at' => now()],
        );
    }
}
