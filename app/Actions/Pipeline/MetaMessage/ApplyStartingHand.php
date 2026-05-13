<?php

namespace App\Actions\Pipeline\MetaMessage;

use App\Models\Game;
use App\Models\LogEvent;
use App\Models\Player;
use App\Support\PipelineContext;

class ApplyStartingHand implements SubHandler
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

        $row = $game->players()->where('player_id', $player->id)->first();

        if (! $row) {
            return;
        }

        $newValue = (int) $parsed['event']['value'];
        $current = (int) ($row->pivot->starting_hand_size ?? 7);

        $game->players()->updateExistingPivot($player->id, [
            'starting_hand_size' => min($current, $newValue),
        ]);
    }
}
