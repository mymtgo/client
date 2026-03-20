<?php

namespace App\Listeners\Pipeline;

use App\Events\GameResultDetermined;
use App\Models\Game;
use App\Models\MtgoMatch;

class ResolveGameResult
{
    public function handle(GameResultDetermined $event): void
    {
        $logEvent = $event->logEvent;

        $match = MtgoMatch::where('token', $logEvent->match_token)->first();

        if (! $match) {
            return;
        }

        $game = Game::where('match_id', $match->id)
            ->where('mtgo_id', $logEvent->game_id)
            ->first();

        if (! $game || $game->won !== null) {
            return;
        }

        $data = json_decode($logEvent->raw_text, true);

        if (! is_array($data) || ! isset($data['won'])) {
            return;
        }

        $game->update([
            'won' => $data['won'],
            'ended_at' => $game->ended_at ?? now(),
        ]);

        $logEvent->update(['processed_at' => now()]);
    }
}
