<?php

namespace App\Actions\Matches;

use App\Enums\MatchState;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class ResolvePendingResults
{
    public static function run(): void
    {
        $pending = MtgoMatch::where('state', MatchState::PendingResult)->get();

        foreach ($pending as $match) {
            $result = ParseMatchHistory::findResult($match->mtgo_id);

            if ($result === null) {
                continue;
            }

            $outcome = MtgoMatch::determineOutcome($result['wins'], $result['losses']);

            $match->update([
                'games_won' => $result['wins'],
                'games_lost' => $result['losses'],
                'outcome' => $outcome,
                'state' => MatchState::Complete,
            ]);

            Log::channel('pipeline')->info("Match {$match->mtgo_id}: PendingResult → Complete (from match_history)", [
                'result' => "{$result['wins']}-{$result['losses']}",
                'outcome' => $outcome->value,
            ]);
        }
    }
}
