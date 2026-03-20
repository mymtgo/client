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

        $match = MtgoMatch::findByEvent($logEvent);

        if (! $match) {
            return;
        }

        $game = null;

        if ($logEvent->game_id) {
            $game = Game::where('match_id', $match->id)
                ->where('mtgo_id', $logEvent->game_id)
                ->first();
        } else {
            // IngestGameState creates game_result events with game_index instead of game_id
            $data = json_decode($logEvent->raw_text, true);
            if (isset($data['game_index'])) {
                $game = $match->games()->orderBy('started_at')->skip($data['game_index'])->first();
            }
        }

        if (! $game || $game->won !== null) {
            return;
        }

        $data = $data ?? json_decode($logEvent->raw_text, true);

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
