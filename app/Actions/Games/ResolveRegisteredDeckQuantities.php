<?php

namespace App\Actions\Games;

use App\Models\DeckVersion;
use App\Models\Game;

class ResolveRegisteredDeckQuantities
{
    /**
     * Registered deck version of a game's match, split into maindeck and
     * sideboard quantities. Rows without an mtgo_id (old signature format)
     * are skipped, matching ComputeCardGameStats::resolveVersionDeck.
     *
     * @return array{0: array<int, int>, 1: array<int, int>} [mains, sideboard] as mtgo_id => quantity
     */
    public static function run(Game $game): array
    {
        $game->loadMissing('match');

        $mains = [];
        $sideboard = [];

        foreach (DeckVersion::find($game->match->deck_version_id)?->cards ?? [] as $row) {
            if (! isset($row['mtgo_id'])) {
                continue;
            }

            $mtgoId = (int) $row['mtgo_id'];
            $value = $row['sideboard'] ?? false;
            $isSideboard = is_bool($value) ? $value : strtolower((string) $value) === 'true';

            if ($isSideboard) {
                $sideboard[$mtgoId] = ($sideboard[$mtgoId] ?? 0) + (int) $row['quantity'];
            } else {
                $mains[$mtgoId] = ($mains[$mtgoId] ?? 0) + (int) $row['quantity'];
            }
        }

        return [$mains, $sideboard];
    }
}
