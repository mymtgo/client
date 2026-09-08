<?php

namespace App\Actions\Games;

use App\Models\DeckVersion;
use App\Models\Game;

class ResolveManualGameMaindeck
{
    /**
     * The maindeck a manual game was played with: the local pivot deck_json
     * when sideboarding has been recorded, otherwise the registered version.
     *
     * @return array<int, int> mtgo_id => quantity
     */
    public static function run(Game $game): array
    {
        $game->loadMissing('players', 'match');

        $pivotDeck = $game->players->first(fn ($p) => $p->pivot->is_local)?->pivot->deck_json ?? [];

        $rows = ! empty($pivotDeck)
            ? $pivotDeck
            : (DeckVersion::find($game->match->deck_version_id)?->cards ?? []);

        $quantities = [];

        foreach ($rows as $row) {
            if (! isset($row['mtgo_id']) || self::isSideboard($row['sideboard'] ?? false)) {
                continue;
            }

            $mtgoId = (int) $row['mtgo_id'];
            $quantities[$mtgoId] = ($quantities[$mtgoId] ?? 0) + (int) $row['quantity'];
        }

        return $quantities;
    }

    private static function isSideboard(mixed $value): bool
    {
        return is_bool($value) ? $value : strtolower((string) $value) === 'true';
    }
}
