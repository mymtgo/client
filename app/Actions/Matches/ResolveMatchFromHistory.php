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
     * Writes match-level outcome and aggregate counts only. Per-game
     * Game.won is owned by SyncGamePivots (driven by the actual game log)
     * because mtgo_game_history records only aggregate W-L, not per-game
     * winners — any per-game inference here would be a guess.
     *
     * @param  array{wins: int, losses: int}  $result
     */
    public static function run(MtgoMatch $match, array $result): bool
    {
        $outcome = MtgoMatch::determineOutcome($result['wins'], $result['losses']);

        $previousState = $match->state;

        $match->update([
            'outcome' => $outcome,
            'games_won' => $result['wins'],
            'games_lost' => $result['losses'],
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
