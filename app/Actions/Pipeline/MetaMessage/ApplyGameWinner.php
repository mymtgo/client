<?php

namespace App\Actions\Pipeline\MetaMessage;

use App\Actions\Logs\ConvertMtgoTimestamp;
use App\Models\Game;
use App\Models\LogEvent;
use App\Support\PipelineContext;

class ApplyGameWinner implements SubHandler
{
    public function apply(LogEvent $event, array $parsed, PipelineContext $context): void
    {
        if (! $event->game_id || empty($parsed['event']['player'])) {
            return;
        }

        $game = Game::where('mtgo_id', $event->game_id)->first();

        if (! $game || $game->ended_at !== null) {
            return;
        }

        $localUsername = $context->localUsername();
        $winner = $parsed['event']['player'];

        $game->update([
            'won' => $localUsername !== null && $winner === $localUsername,
            'ended_at' => ConvertMtgoTimestamp::run($event->logged_at, $event->timestamp),
        ]);
    }
}
