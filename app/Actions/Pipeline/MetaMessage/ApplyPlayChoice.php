<?php

namespace App\Actions\Pipeline\MetaMessage;

use App\Models\Game;
use App\Models\LogEvent;
use App\Models\Player;
use App\Support\PipelineContext;

class ApplyPlayChoice implements SubHandler
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

        $isPlay = ($parsed['event']['value'] ?? 'play') === 'play';

        $game->players()->updateExistingPivot($player->id, ['on_play' => $isPlay]);

        $game->players()
            ->where('player_id', '!=', $player->id)
            ->get()
            ->each(function ($otherPlayer) use ($game, $isPlay): void {
                $game->players()->updateExistingPivot($otherPlayer->id, ['on_play' => ! $isPlay]);
            });
    }
}
