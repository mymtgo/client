<?php

namespace App\Actions\Pipeline\MetaMessage;

use App\Models\Game;
use App\Models\LogEvent;
use App\Models\Player;
use App\Support\PipelineContext;

class ApplyMulligan implements SubHandler
{
    public function apply(LogEvent $event, array $parsed, PipelineContext $context): void
    {
        if (! $event->game_id || empty($parsed['event']['player'])) {
            return;
        }

        $game = Game::where('mtgo_id', $event->game_id)->first();

        if (! $game) {
            return;
        }

        $player = Player::where('username', $parsed['event']['player'])->first();

        if (! $player) {
            return;
        }

        $newHandSize = (int) $parsed['event']['value'];
        $mulliganCount = 7 - $newHandSize;

        $game->players()->updateExistingPivot($player->id, [
            'mulligan_count' => $mulliganCount,
            'starting_hand_size' => $newHandSize,
        ]);
    }
}
