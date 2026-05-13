<?php

namespace App\Actions\Pipeline\MetaMessage;

use App\Models\Game;
use App\Models\LogEvent;
use App\Support\PipelineContext;

class ApplyTurnStart implements SubHandler
{
    public function apply(LogEvent $event, array $parsed, PipelineContext $context): void
    {
        if (! $event->game_id) {
            return;
        }

        $game = Game::where('mtgo_id', $event->game_id)->first();

        if (! $game) {
            return;
        }

        $turn = (int) ($parsed['event']['value'] ?? 0);
        $current = (int) ($game->turn_count ?? 0);

        if ($turn > $current) {
            $game->update(['turn_count' => $turn]);
        }
    }
}
