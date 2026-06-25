<?php

namespace App\Actions\Matches;

use App\Models\Game;

class ExtractGameHandData
{
    /**
     * Extract hand data from a game for API reporting.
     *
     * Returns raw mulligan/hand data with catalog IDs (no display formatting).
     *
     * @return array{mulligan_count: int, starting_hand_size: int, kept_hand: int[], opponent_mulligan_count: int, on_play: bool, won: bool}
     */
    public static function run(Game $game): array
    {
        $localInstanceId = (int) ($game->local_instance ?? 1);
        $opponentInstanceId = (int) ($game->opp_instance ?? 0);

        $parsed = ParseOpeningHand::run($game, $localInstanceId, $opponentInstanceId);

        $keptHand = array_values($parsed['kept_hand']);

        return [
            'mulligan_count' => count($parsed['mulliganed_hands']),
            'starting_hand_size' => count($keptHand),
            'kept_hand' => $keptHand,
            'opponent_mulligan_count' => $parsed['opponent_mulligans'],
            'on_play' => (bool) ($game->local_on_play ?? false),
            'won' => (bool) $game->won,
        ];
    }
}
