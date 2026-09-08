<?php

namespace App\Actions\Games;

use App\Actions\Matches\RecomputeManualMatchStats;
use App\Models\Game;

class UpdateManualGameReveals
{
    /**
     * Record the cards the opponent showed in a manual game, in the same
     * shape ingestion writes so the reveals rail and archetype scans read it.
     *
     * @param  list<array{mtgo_id: int, quantity: int}>  $cards
     */
    public static function run(Game $game, array $cards): void
    {
        $game->loadMissing('players', 'match');

        $opponent = $game->players->first(fn ($p) => ! $p->pivot->is_local);

        if (! $opponent) {
            return;
        }

        $deckJson = array_values(array_map(fn (array $card) => [
            'mtgo_id' => (int) $card['mtgo_id'],
            'quantity' => (int) $card['quantity'],
        ], $cards));

        $game->players()->updateExistingPivot($opponent->id, ['deck_json' => $deckJson]);

        RecomputeManualMatchStats::run($game->match);
    }
}
