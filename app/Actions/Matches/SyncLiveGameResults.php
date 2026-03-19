<?php

namespace App\Actions\Matches;

use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class SyncLiveGameResults
{
    /**
     * Sync live game results from the binary game log file.
     *
     * Incrementally parses the .dat file and updates Game.won for any
     * games that have completed but don't have a result yet.
     */
    public static function run(MtgoMatch $match): void
    {
        $gameLog = GetGameLog::run($match->token);

        if (! $gameLog || empty($gameLog['results'])) {
            return;
        }

        $games = $match->games()->orderBy('started_at')->get();

        foreach ($games as $index => $game) {
            if ($game->won !== null) {
                continue;
            }

            if (! isset($gameLog['results'][$index])) {
                continue;
            }

            $game->update(['won' => $gameLog['results'][$index]]);

            Log::channel('pipeline')->info("Match {$match->mtgo_id}: live game result synced", [
                'game_id' => $game->mtgo_id,
                'game_index' => $index,
                'won' => $gameLog['results'][$index],
            ]);
        }
    }
}
