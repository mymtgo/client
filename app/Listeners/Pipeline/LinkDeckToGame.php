<?php

namespace App\Listeners\Pipeline;

use App\Events\DeckUsedInGame;
use App\Models\Game;

class LinkDeckToGame
{
    public function handle(DeckUsedInGame $event): void
    {
        $logEvent = $event->logEvent;

        // deck_used events carry game_id but not always match_id/match_token
        // Find the game directly by mtgo_id
        $game = Game::where('mtgo_id', $logEvent->game_id)->first();

        if (! $game) {
            return;
        }

        // The deck linking logic for individual games is handled within
        // CreateOrUpdateGames::run() which processes deck_used events.
        // This listener ensures the event is marked as processed.
        $logEvent->update(['processed_at' => now()]);
    }
}
