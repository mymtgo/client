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

        // Backfill any remaining null games from the match-level result.
        // This covers match-level concedes where the game log has no
        // per-game win/loss lines.
        $nullGames = $games->filter(fn ($g) => is_null($g->won));

        if ($nullGames->isNotEmpty() && ($match->games_won + $match->games_lost) > 0) {
            $knownWins = $games->filter(fn ($g) => $g->won === true)->count();
            $knownLosses = $games->filter(fn ($g) => $g->won === false)->count();
            $missingWins = $match->games_won - $knownWins;
            $missingLosses = $match->games_lost - $knownLosses;

            foreach ($nullGames as $game) {
                if ($missingWins > 0) {
                    $game->update(['won' => true]);
                    $missingWins--;
                } elseif ($missingLosses > 0) {
                    $game->update(['won' => false]);
                    $missingLosses--;
                }
            }
        }
    }
}
