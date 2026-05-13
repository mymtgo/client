<?php

namespace App\Actions\Pipeline\MetaMessage;

use App\Models\Game;
use App\Models\LogEvent;
use App\Models\Player;
use App\Support\PipelineContext;

class ApplyJoined implements SubHandler
{
    public function apply(LogEvent $event, array $parsed, PipelineContext $context): void
    {
        if (! $event->game_id || empty($parsed['event']['player'])) {
            return;
        }

        $localUsername = $context->localUsername();
        if ($localUsername === null) {
            return;  // can't set is_local correctly without local username — retry next tick
        }

        $game = Game::where('mtgo_id', $event->game_id)->first();
        if (! $game) {
            return;
        }

        $username = $parsed['event']['player'];
        $player = Player::firstOrCreate(['username' => $username]);

        if (! $game->players()->where('player_id', $player->id)->exists()) {
            $game->players()->attach($player->id, [
                'instance_id' => (int) ($parsed['event']['instance_id'] ?? 0),
                'is_local' => $localUsername === $username,
            ]);
        }
    }
}
