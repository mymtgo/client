<?php

namespace App\Actions\Matches;

use App\Enums\MatchState;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class ResolveMatchFromHistory
{
    /**
     * Resolve a match using match history W-L data.
     *
     * Backfills Game.won on existing records (best guess, game order).
     * Does NOT create missing Game records.
     *
     * @param  array{wins: int, losses: int}  $result
     */
    public static function run(MtgoMatch $match, array $result): bool
    {
        $outcome = MtgoMatch::determineOutcome($result['wins'], $result['losses']);

        // Backfill Game.won on existing games where won is null
        $games = $match->games()->orderBy('started_at')->get();
        $winsToAssign = $result['wins'];
        $lossesToAssign = $result['losses'];

        foreach ($games as $game) {
            if ($game->won !== null) {
                continue;
            }

            if ($winsToAssign > 0) {
                $game->update(['won' => true]);
                $winsToAssign--;
            } elseif ($lossesToAssign > 0) {
                $game->update(['won' => false]);
                $lossesToAssign--;
            }
        }

        $previousState = $match->state;

        $match->update([
            'outcome' => $outcome,
            'state' => MatchState::Complete,
            'ended_at' => $match->ended_at ?? now(),
        ]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: {$previousState->value} → Complete (from match_history)", [
            'result' => "{$result['wins']}-{$result['losses']}",
            'outcome' => $outcome->value,
        ]);

        return true;
    }
}
