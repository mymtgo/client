<?php

namespace App\Actions\Games;

use App\Actions\Matches\RecomputeManualMatchStats;
use App\Models\Game;

class UpdateManualGameHand
{
    /**
     * Record the opening hand of a manual game: the cards kept, the cards
     * bottomed after the last mulligan, and each hand put back. An empty
     * kept hand with zero mulligans clears the record (null, not []),
     * which reads as "unknown".
     *
     * @param  list<int>  $keptHand  mtgo ids, one entry per copy
     * @param  list<int>  $bottomed  mtgo ids put on the bottom, one per copy
     * @param  list<list<int>>  $mulliganedHands  one entry per mulligan, empty when not recorded
     */
    public static function run(Game $game, int $mulliganCount, array $keptHand, array $bottomed = [], array $mulliganedHands = []): void
    {
        $game->loadMissing('players', 'match');

        $local = $game->players->first(fn ($p) => $p->pivot->is_local);

        if (! $local) {
            return;
        }

        $clearing = $keptHand === [] && $mulliganCount === 0;
        $ints = fn (array $ids): array => array_values(array_map('intval', $ids));

        $game->players()->updateExistingPivot($local->id, [
            'opening_hand_json' => $clearing ? null : [
                'kept' => $ints($keptHand),
                'bottomed' => $ints($bottomed),
                'mulligans' => array_values(array_map($ints, $mulliganedHands)),
            ],
            'mulligan_count' => $mulliganCount,
            'starting_hand_size' => $clearing ? 7 : count($keptHand),
        ]);

        RecomputeManualMatchStats::run($game->match);
    }
}
