<?php

namespace App\Actions\Matches;

use App\Models\MtgoMatch;

class SyncGameResults
{
    /**
     * Sync individual game `won` fields with the parsed game log results.
     *
     * Games may have been created before all results were available
     * (e.g. early termination via opponent disconnect/concede). This
     * re-applies the authoritative log results to each game record.
     *
     * @param  array<int, bool>  $logResults  Ordered game results from GetGameLog (true = win, false = loss)
     */
    public static function run(MtgoMatch $match, array $logResults): void
    {
        $games = $match->games()->orderBy('started_at')->get();

        foreach ($games as $i => $game) {
            if (isset($logResults[$i]) && (bool) $game->won !== $logResults[$i]) {
                $game->update(['won' => $logResults[$i]]);
            }
        }

    }
}
