<?php

namespace App\Actions\Pipeline\MetaMessage;

use App\Models\Game;
use App\Models\LogEvent;
use App\Support\PipelineContext;

class ApplyDeckList implements SubHandler
{
    public function apply(LogEvent $event, array $parsed, PipelineContext $context): void
    {
        if (! $event->game_id || empty($parsed['cards'])) {
            return;
        }

        $game = Game::where('mtgo_id', $event->game_id)->first();

        if (! $game) {
            return;
        }

        $localPlayerId = $game->players()
            ->wherePivot('is_local', true)
            ->value('player_id');

        if (! $localPlayerId) {
            return;
        }

        $game->players()->updateExistingPivot($localPlayerId, [
            'deck_json' => $parsed['cards'],
        ]);
    }
}
