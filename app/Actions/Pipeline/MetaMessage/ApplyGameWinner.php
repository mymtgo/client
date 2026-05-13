<?php

namespace App\Actions\Pipeline\MetaMessage;

use App\Actions\Logs\ConvertMtgoTimestamp;
use App\Models\CardGameStat;
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

        $localUsername = $context->localUsername();
        if ($localUsername === null) {
            return;  // can't tell win/loss without local username — retry next tick
        }

        $game = Game::where('mtgo_id', $event->game_id)->first();
        if (! $game || $game->ended_at !== null) {
            return;
        }

        $won = $parsed['event']['player'] === $localUsername;

        $game->update([
            'won' => $won,
            'ended_at' => ConvertMtgoTimestamp::run($event->logged_at, $event->timestamp),
        ]);

        CardGameStat::where('game_id', $game->id)->update(['won' => $won]);

        SynthesizeGameLog::run($game->fresh());
    }
}
