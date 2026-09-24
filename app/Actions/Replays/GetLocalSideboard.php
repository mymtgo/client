<?php

namespace App\Actions\Replays;

use App\Models\Game;

class GetLocalSideboard
{
    /**
     * Your sideboard as the game began, one entry per card, from the deck
     * recorded for the game. CreateGames builds that deck from MTGO's log,
     * so it is there whichever source owns the frames, where the sidecar's
     * frames never hold the sideboard. Empty when no deck was recorded.
     *
     * @return list<array{mtgo_id: int, quantity: int}>
     */
    public static function run(Game $game): array
    {
        $deck = $game->localPlayers->first()?->pivot->deck_json ?? [];
        $sideboard = [];

        foreach (is_array($deck) ? $deck : [] as $entry) {
            if (($entry['sideboard'] ?? false) !== true || ! is_numeric($entry['mtgo_id'] ?? null) || (int) ($entry['quantity'] ?? 0) < 1) {
                continue;
            }

            $sideboard[] = ['mtgo_id' => (int) $entry['mtgo_id'], 'quantity' => (int) $entry['quantity']];
        }

        return $sideboard;
    }
}
