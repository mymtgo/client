<?php

namespace App\Actions\Pipeline\MetaMessage;

use App\Models\Game;
use App\Models\LogEvent;
use App\Models\Player;
use App\Support\PipelineContext;

class ApplyDieRoll implements SubHandler
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

        $game->players()->updateExistingPivot($player->id, [
            'dice_roll' => (int) $parsed['event']['value'],
        ]);
    }
}
